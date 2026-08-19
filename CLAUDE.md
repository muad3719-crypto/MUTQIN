# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**MUTQEN (مُتقِن)** — a management system for Quran-memorization centers. Arabic/RTL throughout. Two decoupled parts that talk only over HTTP/JSON:

- `backend/` — Laravel 11 REST API, MySQL database `mutqin_db`, served on port **9090**. **The UI has no Blade** — the only Blade left in the project is the seven PDF report templates (see PDF reports below).
- `frontend-html/` — a standalone vanilla **HTML + CSS + JS + Bootstrap 5 RTL** client (no PHP, no build step) that consumes the API. Served as static files from any web server.

This is a Windows/XAMPP setup, versioned as a **single unified git repository** (backend + frontend together; a stale nested `backend/.git` was retired and backed up).

## Environment & commands

PHP is only available at `C:\xampp\php\php.exe` (not on PATH). There is **no global Composer** — use the bundled `backend/composer.phar`. MySQL is XAMPP's. The Bash tool here is Git Bash; a convenient idiom is `PHP=/c/xampp/php/php.exe`.

```bash
# from backend/
C:\xampp\php\php.exe artisan serve --port=9090     # start the API (MySQL must be running)
C:\xampp\php\php.exe composer.phar install          # install deps (no global composer)
C:\xampp\php\php.exe artisan migrate                # run migrations
C:\xampp\php\php.exe artisan migrate:fresh --seed   # rebuild DB + base demo data
C:\xampp\php\php.exe artisan db:seed --class=ExtraDataSeeder   # APPENDS extra data (does not wipe)
C:\xampp\php\php.exe artisan test                   # 83 feature tests (uses the separate `mutqin_test` MySQL DB — phpunit.xml; sqlite won't work: raw MySQL ALTER + LAST_INSERT_ID/FIELD() SQL)
C:\xampp\php\php.exe artisan test --filter=SomeTest # single test
C:\xampp\php\php.exe artisan tinker --execute='...' # quick DB/logic checks (used heavily for verification)
```

Serving the frontend: it is static, so `cd frontend-html && C:\xampp\php\php.exe -S localhost:8080` or Apache at `http://localhost/MUTQENQ/frontend-html/`. The API URL lives in one place — `frontend-html/js/config.js` (`API_BASE_URL`). Auth state is kept in `localStorage` under keys `mutqin_token` / `mutqin_user`.

Demo accounts (password `mutqin2026`): `admin@mutqin.ly` (admin), center managers `{latin-name}.centeradmin@mutqin.ly`, teachers `@mutqin.ly`, parents `@parent.mutqin.ly`. The login page pulls a live list from `/api/public/demo-accounts`. (Feature tests create their own users with password `password` — independent of seeders.)

> The `تشغيل-المشروع.bat` launcher is partly stale — its web step runs `cd frontend && php artisan serve`, but there is no `frontend/` Laravel app (the real client is the static `frontend-html/`). Its MySQL + API (9090) steps are fine.
>
> `دليل-محتوى-الصفحات.md` is a page-content/design reference from the earlier Blade-based iteration (it talks about `layouts/app.blade.php` and `public/css/mutqin.css`). Useful for **content and design intent**, stale on file layout.

## Architecture

### Auth is the load-bearing design — role + token-ability dual check

One login endpoint `POST /api/auth/login` serves all **four** roles. `users.role` is a string: `admin | center_manager | teacher | parent`. The privilege is encoded **twice**: in the role *and* in the Sanctum token abilities granted at login (`AuthController::login`):

- parent → token abilities `['parent']`
- center_manager → token abilities `['manager']`
- admin / teacher → token abilities `['*']`

The four middleware aliases (`admin`, `teacher`, `parent`, `manager`, registered in `bootstrap/app.php`) each verify **both** the role and `tokenCan(...)`:

- `admin`   → `isAdmin() && tokenCan('*')`
- `teacher` → `role in [teacher, admin] && tokenCan('*')`
- `parent`  → `isParent() && tokenCan('parent')`
- `manager` → `isCenterManager() && tokenCan('manager') && center_id` (a manager with no center never passes)

This dual check exists specifically to stop privilege escalation (a parent- or manager-scoped token must never reach admin/teacher routes even if pointed at one). **When adding any protected route, gate it with the correct alias — never rely on `role` alone.** All routes live in `backend/routes/api.php`, grouped by these aliases. Note the deliberate asymmetries: student **create** is admin/manager-only (teachers get the students resource `except(['store'])` and must file a *request* instead), and teachers/centers have **no destroy route at all**.

Login also refuses two states, *after* the password check (so it never leaks account existence to a guesser):
1. `users.is_active = false` → 403 «هذا الحساب غير نشط».
2. the user's center is inactive → 403 «مركزك غير نشط» — only teachers and managers carry `center_id`, so parents are unaffected.

### Center-manager scoping — never trust `center_id` from the client

`center_manager` is a per-center operator sitting between admin and teacher. **Every manager endpoint derives the center from `$request->user()->center_id`**, never from input. Where a shared controller method serves both admin and manager (e.g. `StudentController@store`, `@index`), the manager branch *overwrites* the request value:

```php
if ($user->isCenterManager()) {
    $request->merge(['center_id' => $user->center_id]);
}
```

Keep this shape for any new manager route — `ManagerReportsTest::test_manager_cannot_request_another_center_via_param` and `CenterManagerTest::test_manager_scope_is_his_center_only` guard it. A manager can approve **internal** transfers only (`from_center_id === target_center_id === his center`); cross-center transfers and all `add` requests stay with the admin. Managers may create and edit teachers/students in their center but never delete; there is at most **one manager per center** (`ManagerManagementController::assertSingleSupervisor`).

### Display codes (`app/Support/DisplayCode.php` + `code_sequences`)

Short human-facing identifiers, unique system-wide, with an independent counter per type:

| type | prefix | example |
|---|---|---|
| `student` | `S` | `S121` |
| `teacher` | `T` | `T13` |
| `center_manager` | `CA` | `CA4` |
| `center` | `C` | `C7` |

Admins and parents get **no** display code (column stays NULL). Reservation is one atomic statement — `UPDATE code_sequences SET value = LAST_INSERT_ID(value + 1) WHERE name = ?` — so the row lock serializes concurrent requests and the counter never goes backwards (**codes are never reused after deletion**). Call `DisplayCode::next($type)` inside the creating transaction; `DisplayCode::preview($type)` is the read-only "what would the next code be" used by the `*/next-code` endpoints (it does not reserve, so an abandoned form wastes nothing — the previewed number is advisory).

Codes are assigned by model hooks, not by controllers: `User::booted()` assigns on create for `teacher`/`center_manager`, `Student` does the same for `S`. **`display_code` is never read from the request** — controllers build their create arrays explicitly and omit it.

The student's code number doubles as the **fingerprint-device ID**: the attendance xlsx import matches «رقم الطالب» → `Student::where('display_code', 'S'.$deviceNum)`.

### Activation replaces deletion

Deleting a teacher, center, or manager loses history, so the system deactivates instead (approved decision — the routes have `->except(['destroy'])`):

- `PUT /teachers/{id}/status` and `PUT /centers/{id}/status` (admin only) flip `is_active`.
- Deactivating **revokes all of that user's Sanctum tokens immediately** (same mechanism as S1 password change) — otherwise a live token keeps working for up to 7 days.
- Deactivating a **center** cascades: every user carrying that `center_id` (its teachers + its manager) has their tokens deleted, and the response reports how many were cut off.
- `users.status_changed_by` / `status_changed_at` record who flipped it and when (mirrors `attendances.corrected_by/corrected_at`).

`GET /centers?active=1` returns only active centers — use it for any "pick a center" dropdown so nobody is enrolled into a closed center. Management and report screens still list everything.

### API/response contract

Every endpoint returns the envelope `{ success, message, data, errors }`. Validation failures are HTTP 422 with `errors` keyed by field and **Arabic** messages. `frontend-html/js/api.js` wraps `fetch`: it attaches `Authorization: Bearer <token>`, and on 401 clears storage and redirects to login. Keep new controllers in this envelope shape or the frontend breaks.

List endpoints are **server-paginated** (`paginate(20)->withQueryString()`, centers 10) and accept `?all=1` to return a plain array instead — the frontend handles both shapes.

### Frontend page pattern

Every protected page is a small IIFE that does: `Auth.requireAuth([roles])` → `Layout.mount({user, active, title})` (returns the page container) → `API.get(...)` to load → render → wire `UI.formModal(...)` for create/edit. The shared modules attached to `window` are `Config, API, Auth, UI, Layout` (in `js/`). No framework, no bundler. `Auth.dashboardFor(role)` and the `NAV` map in `layout.js` are the two places a new role's landing page and sidebar are declared.

### Domain model & key rules

- **User** (admin/center_manager/teacher/parent): a teacher `hasMany` students via `teacher_id`; a parent `hasMany` children via `students.parent_id`; teachers and managers `belongsTo` a center.
- **Student**: belongs to center, teacher, and parent. `guardian_name`/`guardian_phone` are **display-only**; the real guardian link is `parent_id`. Guardians are de-duplicated by identity → phone → email (siblings share one parent account). `national_id` is optional and unique.
- **Teacher `type`**: `محفظ أساسي` (primary) or `محفظ معاون` (assistant). Business rule: **at most one primary per center** — the single source is `App\Support\PrimaryTeacherRule::assert()`, called from `TeacherController` (admin) *and* `CenterManagerController` (manager). `GET /api/centers/{id}/has-primary` (admin) and `GET /api/manager/center` (manager) let the forms disable the option; the backend check is still authoritative.
- **Memorization** is tracked per surah session. Progress is **reverse order**: memorization starts at juz 30 (سورة الناس) and proceeds down toward juz 1.
- **Center manager**: one per center, email scheme `{latin}.centeradmin@mutqin.ly` (no numeric suffix). Password is **required explicitly** at creation — no silent generation.

### Surah/juz reference — the source of truth (`app/Support/SurahReference.php`)

`memorizations.juz` is unreliable hand-entered data (the same surah has been stored under different juz numbers). **Do not trust the stored `juz` column.** Derive a surah's juz from its `surah_name` via the static 114-surah `SurahReference::SURAHS` table. A juz counts as **complete only when every one of its surahs is recorded** — student progress is computed by surah completion, not by the presence of any single record. `SurahReference::namesOfJuz($n)` and `::progress($surahNames)` are the entry points. (The `athman` table is a separate thumn-level index for search/autocomplete — it covers only 92 surahs and has no juz column, so it cannot enumerate a full juz.)

### Shared `app/Support/` helpers — use these, don't re-derive

| class | purpose |
|---|---|
| `ArabicText` | the single normalizer. `normalize()` (alef/ya/ta-marbuta/hamza + tashkeel + spaces), `normalizeQuery()` (also strips leading و/ف/ال), `stripTashkeel()`, and **`sqlNormalize($column)`** which emits nested `REPLACE()` so MySQL matches «احمد» to «أحمد». All search endpoints use it. |
| `PhoneNumber` | `normalize()` — `+218` / `00218` / Arabic digits → `09xxxxxxxx`. Applied on every phone write and phone search. |
| `DisplayCode` | `next()` / `preview()` — see above. |
| `PrimaryTeacherRule` | one-primary-per-center, throws a 422 with an Arabic message. |
| `ParentResolver` | guardian resolution: id_number → normalized phone → email, parent-role scoped, random password on create, 422 Arabic on unusable data. |
| `Percentage` | `of($part, $total)` — divide-by-zero-safe integer percent, used in attendance summaries and reports. |
| `SurahReference` | 114-surah → juz table. |

### PDF reports

`ReportPdfController::render()` renders a **Blade** template to HTML (`view($view, ...)->render()`) and feeds it to **mPDF**. These are the project's only Blade files — `resources/views/pdf/`: `layout.blade.php` (shared shell), `student`, `teacher-group`, and `admin/{center,teachers,at-risk,overview}`. Editing a report means editing Blade; nothing else in the system does. Render numbers with **Western digits** — Arabic-Indic digits show as empty boxes in the default font. The manager PDF endpoints (`managerCenter`, `managerAtRisk`, `managerTeachers`) reuse the admin templates with `center_id` taken from the account.

### Later-added subsystems (beyond the base CRUD)

- **Student requests** (`StudentRequestController`, `student_requests`): a teacher *requests* adding/transferring a student; only an approval mutates `students`. Duplicate detection by national id suggests transfer instead of add. Approval routing: internal transfers → the center manager (with admin fallback when the center has none, and pending requests hand back to admin if the manager is deleted); cross-center transfers and adds → admin.
- **Center-manager attendance review**: `GET /manager/attendance` (paginated, filterable) + `PUT /manager/attendance/{id}/status` to correct a single record, stamping `corrected_by`/`corrected_at`. Deliberately a light audit — the previous status is not retained.
- **Teacher self-service profile** (`TeacherProfileController`): everything operates on `$request->user()` — **no id is ever read from the request**. Only phone and password are editable; name/center/type/code are display-only and any submitted value is ignored (the writes are explicit single-column, not mass assignment).
- **In-app notifications**: Laravel database channel via the generic `App\Notifications\InAppNotification` (`sendSafe()` swallows failures so it never breaks the primary operation). Types: `request_created/approved/rejected`, `memorization_added`, `test_added` (parents), plus manager-account notices. Bell UI in `layout.js` polls every 60s; endpoints are `/notifications`, `/notifications/{id}/read`, `/notifications/read-all`.
- **Password reset by phone OTP** (`/auth/forgot-password/*`): hashed 6-digit OTP, 10-min expiry, 5 attempts, throttled, neutral response so phone numbers can't be enumerated. Parents and teachers only — the admin is reset manually. No SMS gateway — `AuthController::sendOtp()` is the single integration point; in `local` env only, the response carries `dev_otp`.
- **Password-change tracking**: `password_changed_count`/`password_last_changed_at` + `password_change_logs` (method: otp/self/admin) via `User::recordPasswordChange()` — which also **revokes all tokens** (S1). Sanctum tokens expire after 7 days.
- **Nationality & identity**: students and parents have `nationality_type` (libyan/foreigner) + `nationality_name`; the parent's unified identifier is `users.id_number` (unique). Libyan students: 12 digits starting 1/2; foreigners: free-text passport/residency up to 32 chars. The student-id requirement is a one-line switch — `StudentController` documents where to flip `nullable` → `required`.
- **Deleted teachers**: records keep history (`teacher_id` → null, D1) and students carry `former_teacher_name` for display («محفّظ سابق: فلان»). The manager dashboard surfaces a `students_without_teacher` counter so they get reassigned.

## Gotchas

- `ExtraDataSeeder` **appends** (it does not wipe) and is center-aware to respect the one-primary-per-center rule; re-running it adds more data.
- Two PHP extensions must be enabled in `C:\xampp\php\php.ini` for full functionality: `zip` (xlsx attendance import) and `gd` (mPDF). They were disabled by default in this install.
- App timezone is **Africa/Tripoli** (config/app.php) — do not revert to UTC; "today" logic (attendance defaults, dashboard stats, report month defaults) depends on it, and the frontend uses `UI.todayStr()` (local) rather than `toISOString()`.
- The week runs **Saturday → Friday** (center's convention). Carbon 3 removed the global `setWeekStartsAt`, so week-scoped queries pass `startOfWeek(Carbon::SATURDAY)`/`endOfWeek(Carbon::FRIDAY)` explicitly (dashboard weekly stat + weekly report) — new week-scoped code must do the same.
- Attendance is **unique per `(student_id, date)`**: re-importing a day **updates** the existing row rather than duplicating or weakening it (approved rule). Weekly tests, by contrast, may repeat freely on the same day.
- The xlsx import validates the Arabic header exactly — `رقم الطالب | الاسم | التاريخ | الوقت | الحالة` — matches students by `S{deviceNum}`, tolerates truncated names (a shorter name matching as a word-boundary prefix of the longer one, ≥2 words, imports with a warning), computes absences for dates in range, and silently skips out-of-scope students. All of it runs in one transaction.
- `php artisan serve` is single-threaded: concurrent requests serialize (slow dependent dropdowns in dev). Set `PHP_CLI_SERVER_WORKERS=4` or use Apache.
- Feature tests live in `tests/Feature` (83 tests) against the `mutqin_test` DB. Keep them green — they are the only guard on the dual role+ability security model, manager center-scoping, and display-code uniqueness.

---

# Reference

## API routes (`backend/routes/api.php`)

Gate column = the middleware group (see the auth section above for what each enforces).

### Public / any authenticated user

| Method | Path | Gate | Handler |
|---|---|---|---|
| POST | `/auth/login` | public (throttle 10/min) | `AuthController@login` |
| POST | `/auth/forgot-password/request` · `/verify` | public (throttle 5/10 per min) | `AuthController` |
| GET | `/public/stats` · `/public/demo-accounts` | public | `DashboardController` |
| POST/GET | `/auth/logout` · `/auth/user` | auth | `AuthController` |
| GET | `/dashboard` | auth | `DashboardController@index` (role-aware payload) |
| GET/POST | `/notifications` · `/notifications/read-all` · `/notifications/{id}/read` | auth | `NotificationController` |
| GET | `/athman/search` · `/athman/hizb/{n}` · `/athman/{id}` | auth | `AthmanController` (thumn search/lookup) |

### `parent`

| Method | Path | Handler |
|---|---|---|
| GET | `/parent/children` | `StudentController@parentChildren` |
| GET | `/parent/students/{id}` | `StudentController@parentStudentDetails` |

### `manager` — every one scoped to the caller's center

| Method | Path | Handler |
|---|---|---|
| GET | `/manager/dashboard` · `/manager/center` | `CenterManagerController` |
| GET/POST | `/manager/students` · `/manager/students/next-code` · `/manager/parents/search` | `StudentController` (`index`/`store`/`nextCode`/`searchParents`) |
| GET/POST/PUT | `/manager/teachers` · `/{id}` · `/next-code` | `CenterManagerController` (`teachers`/`showTeacher`/`storeTeacher`/`updateTeacher`/`teacherNextCode`) — no delete |
| POST | `/manager/attendance/import` | `AttendanceImportController@import` |
| GET/PUT | `/manager/attendance` · `/manager/attendance/{id}/status` | `CenterManagerController` (review + single-record correction) |
| GET | `/manager/reports/system` · `/manager/reports/management` | `CenterManagerController` (report groups 1 and 2) |
| GET | `/manager/reports/{center,at-risk,teachers}/pdf` | `ReportPdfController@manager*` |
| GET/POST | `/manager/student-requests` · `/{id}/approve` · `/{id}/reject` | `StudentRequestController` (internal transfers only) |

### `admin`

| Method | Path | Handler |
|---|---|---|
| apiResource (no destroy) | `/teachers` | `TeacherController` |
| PUT | `/teachers/{id}/status` | `TeacherController@toggleStatus` (activate/deactivate) |
| GET | `/centers/{id}/has-primary` | `TeacherController@hasPrimary` |
| apiResource (no destroy) | `/centers` | `CenterController` (`?active=1` filter, `show` returns counts + teacher list) |
| PUT | `/centers/{id}/status` | `CenterController@toggleStatus` (cascades token revocation) |
| POST/GET | `/students` · `/students/next-code` · `/parents/search` | `StudentController` |
| GET/POST/PUT/DELETE | `/admin/managers` (+`/{id}`) | `ManagerManagementController` (one manager per center) |
| GET | `/reports/admin/missing-national-id` | `ReportController@missingNationalId` |
| GET | `/reports/admin/{center/{id},teachers,at-risk,overview}/pdf` | `ReportPdfController` |
| GET/POST | `/admin/student-requests` · `/{id}/approve` · `/{id}/reject` | `StudentRequestController` |

### `teacher` (admin also passes this gate)

| Method | Path | Handler |
|---|---|---|
| GET/PUT/POST | `/profile` · `/profile/phone` · `/profile/password` | `TeacherProfileController` (token owner only) |
| apiResource (no store) | `/students` | `StudentController` (index/show/update/destroy) |
| GET/POST | `/student-requests` · `/student-requests/search-students` | `StudentRequestController` (create/track; no direct mutation) |
| GET/POST | `/attendance`, `/attendance/report`, `/attendance/import` | `AttendanceController`, `AttendanceImportController@import` (xlsx) |
| GET | `/memorizations/surahs` · `/memorizations/students-progress` | `MemorizationController` |
| apiResource (index/store/destroy) | `/memorizations` | `MemorizationController` |
| apiResource (index/store/destroy/show) | `/weekly-tests` | `WeeklyTestController` |
| GET | `/reports/weekly` · `/reports/student/{id}` | `ReportController` |
| GET | `/reports/student/{id}/pdf` · `/reports/teacher/pdf` | `ReportPdfController` |

`teacher`-gated endpoints scope data to the caller's own students when the caller is a teacher, and to everything when admin. The student-PDF route additionally enforces "own student only".

## Controllers (`app/Http/Controllers/Api/`)

- **AuthController** — unified login (issues role-scoped Sanctum abilities, blocks inactive users and users of inactive centers), logout, current user, phone-OTP password reset.
- **DashboardController** — `index` returns a role-aware dashboard; `publicStats` and `demoAccounts` are public (`demoAccounts` orders admin → manager → teacher → parent).
- **CenterManagerController** — the whole center-manager surface: dashboard, own center info, teacher list/details/create/update, attendance review + correction, and the two report groups. Center always from the account.
- **ManagerManagementController** — admin CRUD over center managers; enforces one per center and builds the `{latin}.centeradmin@mutqin.ly` email (tolerating a full email or `.centeradmin` suffix in the input).
- **TeacherController** — teacher CRUD (no destroy) + `toggleStatus`, `hasPrimary`, and a unified `?q=` search across normalized name, display code (`T5`, `5`, `٥` — exact code match, so `T5` never catches `T50`), and normalized center name.
- **CenterController** — center CRUD (no destroy) + `toggleStatus`; `show` returns counts and the center's teachers (primary first) in two queries, no N+1.
- **StudentController** — student CRUD + guardian linking via `ParentResolver`, `nextCode`, `searchParents`, and the two parent-facing read endpoints. `index` scopes by role (manager → his center, teacher → his students, admin → all) and supports filters + a normalized all-column `?q=`.
- **StudentRequestController** — teacher-filed add/transfer requests; admin and manager approval paths (manager limited to internal transfers).
- **TeacherProfileController** — teacher self-service; phone + password only.
- **AttendanceController** — list/store/report. **AttendanceImportController** — xlsx fingerprint-device import (needs the `zip` extension), shared by the teacher and manager routes.
- **MemorizationController** — list (paginated, `?juz=N` filter by surah's juz via `SurahReference`), store, destroy, `surahs` list, and `studentsProgress` (surah-completion-based progress).
- **WeeklyTestController** — weekly thumn tests with per-thumn questions (index/store/show/destroy; no update).
- **ReportController** + **ReportService** (`app/Services/`) — JSON report data. `ReportService` methods take an optional `?int $centerId` so admin and manager reuse the same queries: `studentData`, `studentSummaryRow`, `teacherGroupData`, `centerData`, `allCentersData`, `teachersPerformance`, `atRiskStudents`, `progressSummary`, `centerManagement`, `overview`. **ReportPdfController** — mPDF renderings of the same, including the `manager*` variants.
- **NotificationController** — list / mark one read / mark all read.
- **AthmanController** — read-only thumn index search/lookup.

## Models (`app/Models/`) & relationships

- **User** `(name,display_code,email,phone,role,center_id,type,nationality_*,id_number,is_active,status_changed_by,status_changed_at,password)` — `isAdmin/isTeacher/isParent/isCenterManager`; `students()` (teacher_id), `children()` (parent_id), `center()`, `weeklyTests()`, `passwordChangeLogs()`; `recordPasswordChange()`; `booted()` assigns the display code. Uses `HasApiTokens`.
- **Center** `(name,display_code,city,address,phone,is_active)` — `students()`, `teachers()`.
- **Student** `(name,display_code,birth_date,phone,national_id,nationality_*,age,guardian_name,guardian_phone,center_id,teacher_id,former_teacher_name,parent_id,enrollment_date,is_active)` — `center/teacher/parent` (belongsTo), `attendances/memorizations/revisions/tajweedEvaluations/weeklyTests` (hasMany); scope `missingNationalId`.
- **Memorization** `(student_id,teacher_id,date,surah_name,juz,hizb,page_from,page_to,eighth,quality,notes)` — `student/teacher`.
- **WeeklyTest** `(student_id,teacher_id,result,date,test_type,passed,notes)` + **WeeklyTestQuestion** `(weekly_test_id,student_id,eighth_start,result,mistake)` — a test `hasMany` questions (one per thumn).
- **Attendance** `(student_id,teacher_id,date,time,status,notes,imported_at,corrected_by,corrected_at,center_id)` — unique `(student_id,date)`.
- **StudentRequest** — pending add/transfer requests with `from_center_id`/`target_center_id`/`requested_by`.
- **OtpReset**, **PasswordChangeLog** — reset/audit support tables.
- **Revision**, **TajweedEvaluation** — full models + relations exist but are **not exposed** (see Missing features).
- **Athman** `(hizb,thumn_in_hizb,global_order,surah_name,start_text,start_text_norm,page)` — read-only reference; normalization now delegates to `ArabicText`.

`app/Support/SurahReference.php` is a static class (not a model) — the 114-surah→juz source of truth (see the architecture section).

## Database schema (MySQL `mutqin_db`)

Core tables: `users`, `centers`, `students`, `attendances`, `memorizations`, `revisions`, `tajweed_evaluations`, `weekly_tests`, `weekly_test_questions`, `student_requests`, `athman`, `code_sequences`, `otp_resets`, `password_change_logs`, `notifications`, plus Laravel's `personal_access_tokens`, `cache`, `jobs`, `sessions`, `password_reset_tokens`.

Schema evolved through migrations (note for anyone reading raw migration files):
- `users.role` started as an enum `[admin,teacher]`, was later **changed to a plain string** to allow `parent` and then `center_manager`; `center_id` and `type` (primary/assistant) were added in v2; `is_active` + `status_changed_by/at` in the deactivation migration.
- `students` gained `age` (v2), `parent_id`, `national_id` (unique, optional), nationality columns, `former_teacher_name`, and `display_code`. `birth_date` predates `age` and is largely vestigial — **`age` is the field actually used**.
- `code_sequences` is a one-row-per-type counter table (created with rows for student/teacher/center_manager; `center` was added later with an idempotent insert plus a backfill of existing centers ordered by id).
- `weekly_tests` went through a `date`→`exam_date`→`date` rename and carries overlapping `result` (ناجح/راسب) + `passed` + `test_type` columns — historical cruft; the per-thumn detail lives in `weekly_test_questions`.
- `attendances` gained `time`/`imported_at`/`center_id` for the xlsx import, then `corrected_by`/`corrected_at` for manager corrections.

## Frontend structure (`frontend-html/`)

Static, role-segmented pages; each is a self-contained IIFE using the shared `window` modules.

- **Public**: `index.html` (landing, `/public/stats`), `login.html` (unified login, demo-accounts panel), `forgot-password.html` (OTP flow).
- **admin/**: `dashboard`, `centers`, `teachers`, `managers`, `students`, `requests`, `reports`.
- **manager/**: `dashboard`, `teachers`, `students`, `attendance` (import), `attendance-review`, `reports`, `requests`.
- **teacher/**: `dashboard`, `profile`, `students`, `requests`, `attendance`, `memorization`, `weekly-tests`, `reports`.
- **parent/**: `dashboard` (children), `child` (read-only details).
- **js/**: `config.js` (API URL + `APP_ROOT` auto-detect + storage keys), `api.js` (fetch wrapper + envelope/401 handling), `auth.js` (login/logout/`requireAuth`/`dashboardFor`), `ui.js` (toasts, `formModal`, badges, table search, xlsx import + summary modal, athman autocomplete, PDF open, confirm dialogs, SVG icons — the largest module), `layout.js` (role-based sidebar/topbar + notification bell), plus `pages/login.js` and `pages/landing.js`.
- **css/theme.css**: brand identity (emerald `#04532F`, gold `#D4AF37`, ivory `#FBF7DA`; Amiri for headings + Cairo for UI).

## Test suite (`backend/tests/Feature`, 83 tests)

`AuthLoginTest`, `RoleMatrixTest` (role×route matrix, tampered-token escalation, admin-only student create), `OwnershipTest`, `CenterManagerTest` (manager route matrix, center scoping, internal-vs-cross-center approval, one-manager-per-center, request notification fallbacks), `ManagerAddTeacherTest`, `ManagerAttendanceReviewTest`, `ManagerReportsTest` (scoping + constant query count), `TeacherStatusTest`, `CenterStatusTest`, `TeacherProfileTest`, `DisplayCodeTest` (sequential codes, independent counters, no reuse after delete, admin/parent get none), `StudentCodePreviewTest`, `AttendanceDuplicationTest`, `FingerprintImportTest`, `PaginationSearchTest` (pagination + normalized Arabic search), `PhoneNormalizationTest`, `OtpResetTest`, `StudentRequestNotificationTest`.

## Missing / dormant features

Things present in the data model but not wired end-to-end — likely roadmap items:

- **Revisions (المراجعة)** and **Tajweed evaluations (التجويد)**: tables + models + Student relations exist, but there is **no controller, route, or UI**, and `ReportService` ignores them. (The dead revisions eager-load in `StudentController@show` has since been removed.)
- **No self-registration**: parents, teachers and managers are created server-side/by admin (or manager, for teachers) only; there is no public signup.
- **No update for some records**: attendance has no general update endpoint (create/delete + manager single-status correction only); weekly tests have no update.
- **No SMS gateway** for OTP delivery (dev flow shows the code to the admin in `local` only) — see DEPLOYMENT.md.
- **No soft-deletes/full audit trail** despite handling minors' PII — the audit that exists is deliberately light (who changed a status/attendance and when, no previous value).
