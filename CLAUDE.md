# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

**MUTQEN (مُتقِن)** — a management system for Quran-memorization centers. Arabic/RTL throughout. Two decoupled parts that talk only over HTTP/JSON:

- `backend/` — Laravel 11 REST API (no Blade views for the app), MySQL database `mutqin_db`, served on port **9090**.
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
C:\xampp\php\php.exe artisan test                   # 20 feature-test files (uses the separate `mutqin_test` MySQL DB — phpunit.xml; sqlite won't work: raw MySQL ALTER in one migration)
C:\xampp\php\php.exe artisan test --filter=SomeTest # single test
C:\xampp\php\php.exe artisan tinker --execute='...' # quick DB/logic checks (used heavily for verification)
```

Serving the frontend: it is static, so `cd frontend-html && C:\xampp\php\php.exe -S localhost:8080` or Apache at `http://localhost/MUTQENQ/frontend-html/`. The API URL lives in one place — `frontend-html/js/config.js` (`API_BASE_URL`). Auth state is kept in `localStorage` under keys `mutqin_token` / `mutqin_user`.

Demo accounts (password `mutqin2026`): `admin@mutqin.ly` (admin), managers `{name}.centeradmin@mutqin.ly`, teachers `@mutqin.ly`, parents `@parent.mutqin.ly`. The login page pulls a live list from `/api/public/demo-accounts`. (Feature tests create their own users with password `password` — independent of seeders.)

> The `تشغيل-المشروع.bat` launcher is partly stale — its web step runs `cd frontend && php artisan serve --port=9091`, but `frontend/` contains only a single leftover `routes/web.php` (no artisan, no app) — that step cannot work. The real client is the static `frontend-html/` (no fixed port; 8080 is just the suggested `php -S` port). Its MySQL + API (9090) steps are fine.

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
- `manager` → `isCenterManager() && tokenCan('manager') && center_id set` (`CenterManagerMiddleware`)

This dual check exists specifically to stop privilege escalation (a parent- or manager-scoped token must never reach admin/teacher routes even if pointed at one). **When adding any protected route, gate it with the correct alias — never rely on `role` alone.** All routes live in `backend/routes/api.php`, grouped by these aliases. Note the deliberate asymmetry: student **create** is admin+manager only (`POST /students`, `POST /manager/students`), while teachers get the students resource `except(['store', 'destroy'])`.

Login additionally blocks (403, after password check so account state isn't leaked to guessers): deactivated users (`is_active = false`) and members of a deactivated center (teachers + center managers — the only roles carrying `center_id`).

### API/response contract

Every endpoint returns the envelope `{ success, message, data, errors }`. Validation failures are HTTP 422 with `errors` keyed by field and **Arabic** messages. `frontend-html/js/api.js` wraps `fetch`: it attaches `Authorization: Bearer <token>`, and on 401 clears storage and redirects to login. Keep new controllers in this envelope shape or the frontend breaks.

### Frontend page pattern

Every protected page is a small IIFE that does: `Auth.requireAuth([roles])` → `Layout.mount({user, active, title})` (returns the page container) → `API.get(...)` to load → render → wire `UI.formModal(...)` for create/edit. The shared modules attached to `window` are `Config, API, Auth, UI, Layout` (in `js/`). No framework, no bundler.

### Domain model & key rules

- **User** (admin/center_manager/teacher/parent): a teacher `hasMany` students via `teacher_id`; a parent `hasMany` children via `students.parent_id`; a center manager belongs to exactly one center (`center_id`) and there is **at most one manager per center** (`ManagerManagementController::assertSingleSupervisor`).
- **Student**: belongs to center, teacher, and parent. `guardian_name`/`guardian_phone` are **display-only**; the real guardian link is `parent_id`. Guardians are de-duplicated **by phone** (siblings share one parent account). `national_id` is optional, unique, Libyan male format. Parent/guardian email creation is two-step: insert with a temp email, then set `{latin}.{id}@domain` (the id only exists after insert).
- **Teacher `type`**: `محفظ أساسي` (primary) or `محفظ معاون` (assistant). Business rule: **at most one primary per center** — single source `App\Support\PrimaryTeacherRule::assert` (used by `TeacherController` and `CenterManagerController`; the manager's create path re-checks inside a transaction with `lockForUpdate`), surfaced via `GET /api/centers/{id}/has-primary` (admin) and `GET /api/manager/center` (manager).
- **Display codes** (`App\Support\DisplayCode`): short system-wide-unique codes per type — student `S{n}`, teacher `T{n}`, center manager `CA{n}`, center `C{n}` (admin + parent have none). Reserved **atomically** from the `code_sequences` table inside the create transaction via `creating` hooks on `Student`/`User`/`Center` — never accepted from the client, numbers are never reused. `.../next-code` endpoints give a read-only **preview** (no reservation).
- **Activate/deactivate replaces deletion** (approved decision): teachers, centers, students, and center managers are never hard-deleted — each has a `PUT .../{id}/status` toggle with audit fields (`status_changed_by/at`). Deactivating a user **revokes all their tokens immediately** (same S1 mechanism as password change); deactivating a center revokes the tokens of all its members and blocks their login; a deactivated manager's pending internal transfer requests devolve to the system admin (with a notification). Inactive students stay in history but drop out of attendance, fingerprint import, and reports (`is_active` filters); student lists default to `status=active` (`?status=inactive|all` to override).
- **Memorization** is tracked per surah session. Progress is **reverse order**: memorization starts at juz 30 (سورة الناس) and proceeds down toward juz 1.

### Surah/juz reference — the source of truth (`app/Support/SurahReference.php`)

`memorizations.juz` is unreliable hand-entered data (the same surah has been stored under different juz numbers). **Do not trust the stored `juz` column.** Derive a surah's juz from its `surah_name` via the static 114-surah `SurahReference::SURAHS` table. A juz counts as **complete only when every one of its surahs is recorded** — student progress is computed by surah completion, not by the presence of any single record. `SurahReference::namesOfJuz($n)` and `::progress($surahNames)` are the entry points. (The `athman` table is a separate thumn-level index for search/autocomplete — it covers only 92 surahs and has no juz column, so it cannot enumerate a full juz.)

### PDF reports

`ReportPdfController` uses **mPDF**. Render numbers with **Western digits** — Arabic-Indic digits show as empty boxes in the default font.

### Later-added subsystems (beyond the base CRUD)

- **Center manager panel** (`CenterManagerController` + `manager` gate): a per-center operations role. Everything is scoped to the manager's own `center_id` **taken from the account, never from the request** (client-sent `center_id` is overwritten/ignored). Can: view a center dashboard, list/add/edit (not delete) teachers of their center (`role` and `center_id` forced server-side), add/toggle students, import + review + correct fingerprint attendance (`corrected_by/at` audit, out-of-center record → 403), pull center-scoped report groups (`reportsSystem`/`reportsManagement` reusing `ReportService`) and manager PDF variants, and approve/reject **internal** transfer requests only (`from = target = own center`, enforced by `StudentRequestController::assertManagerScope`).
- **Manager management** (`ManagerManagementController`, admin-only `/admin/managers`): create/update/toggle center managers; at most one manager per center; email is the fixed scheme `{latin}.centeradmin@mutqin.ly` (input tolerant — a pasted full email is trimmed to its prefix); password required explicitly on create (no silent generation).
- **Student requests** (`StudentRequestController`, `student_requests`): a teacher *requests* adding/transferring a student; approval mutates `students`. Duplicate detection by national id suggests transfer instead of add. Routing: **internal** transfers (same source and target center) go to that center's **active** manager if one exists, otherwise — and for cross-center transfers and all adds — to the system admin.
- **Teacher self-profile** (`TeacherProfileController`, `/profile*`): token-owner only (no id accepted); can change **phone and password only** (current password required; change runs `recordPasswordChange('self')` which revokes all tokens).
- **Unified normalized search**: `App\Support\ArabicText` (strip tashkeel, unify alef/ya/ta-marbuta/hamza, `sqlNormalize()` for in-MySQL matching) powers the `q=` search on teachers (name / display code incl. Arabic-digit `T5`/`5` forms / center name), students (name, guardian, former teacher, nationality words, national id, normalized phone), and the manager's attendance review.
- **In-app notifications**: Laravel database channel via the generic `App\Notifications\InAppNotification` (`sendSafe()` never breaks the primary operation). Types: `request_created/approved/rejected`, `memorization_added`, `test_added` (parents). Bell UI in `layout.js` polls every 60s.
- **Password reset by phone OTP** (`/auth/forgot-password/*`): hashed 6-digit OTP, 10-min expiry, 5 attempts, throttled. No SMS gateway — `AuthController::sendOtp()` is the single integration point; in `local` env only, the response carries `dev_otp` for the admin to relay.
- **Password-change tracking**: `password_changed_count`/`password_last_changed_at` + `password_change_logs` (method: otp/self/admin) via `User::recordPasswordChange()` — which also **revokes all tokens** (S1). Sanctum tokens expire after 7 days.
- **Nationality & identity**: students and parents have `nationality_type` (libyan/foreigner) + `nationality_name`; the parent's unified identifier is `users.id_number` (unique). Libyan format `^[12]\d{11}$` enforced only for `libyan`.
- **Guardian resolution** is centralized in `App\Support\ParentResolver` (id_number → normalized phone → email, parent-role scoped, random password on create, 422 Arabic on unusable data). Phones are normalized everywhere via `App\Support\PhoneNumber::normalize` (+218/00218/Arabic digits → `09xxxxxxxx`).
- **Teacher history preservation** (from the era when deletion existed): records keep history (`teacher_id` → null, D1) and students carry `former_teacher_name` for display («محفّظ سابق: فلان»). Deletion has since been replaced by deactivation, but the null-`teacher_id` path still matters (e.g. the manager dashboard counts `students_without_teacher`).

## Gotchas

- `ExtraDataSeeder` **appends** (it does not wipe) and is center-aware to respect the one-primary-per-center rule; re-running it adds more data.
- Two PHP extensions must be enabled in `C:\xampp\php\php.ini` for full functionality: `zip` (xlsx attendance import) and `gd` (mPDF). They were disabled by default in this install.
- App timezone is **Africa/Tripoli** (config/app.php) — do not revert to UTC; "today" logic (attendance defaults, dashboard stats) depends on it, and the frontend uses `UI.todayStr()` (local) rather than `toISOString()`.
- The week runs **Saturday → Friday** (center's convention). Carbon 3 removed the global `setWeekStartsAt`, so week-scoped queries pass `startOfWeek(Carbon::SATURDAY)`/`endOfWeek(Carbon::FRIDAY)` explicitly (dashboard weekly stat + weekly report) — new week-scoped code must do the same.
- `php artisan serve` is single-threaded: concurrent requests serialize (slow dependent dropdowns in dev). Set `PHP_CLI_SERVER_WORKERS=4` or use Apache.
- Feature tests live in `tests/Feature` (role matrix, ownership, login throttle, OTP, request notifications, phone normalization, display codes, center-manager scoping/add-teacher/attendance-review/reports, teacher/manager/center/student status toggles, pagination+search) against the `mutqin_test` DB. Keep them green — they are the only guard on the dual role+ability security model.

---

# Reference

## API routes (`backend/routes/api.php`)

Gate column = the middleware group (see the auth section above for what each enforces).

| Method | Path | Gate | Handler |
|---|---|---|---|
| POST | `/auth/login` | public (throttle:10,1) | `AuthController@login` |
| POST | `/auth/forgot-password/request` | public (throttle:5,1) | `AuthController@forgotPasswordRequest` |
| POST | `/auth/forgot-password/verify` | public (throttle:10,1) | `AuthController@forgotPasswordVerify` |
| GET | `/public/stats` · `/public/demo-accounts` | public | `DashboardController` |
| POST | `/auth/logout` | auth | `AuthController@logout` |
| GET | `/auth/user` | auth | `AuthController@user` |
| GET | `/dashboard` | auth | `DashboardController@index` (role-aware payload) |
| GET/POST | `/notifications` · `/notifications/read-all` · `/notifications/{id}/read` | auth | `NotificationController` |
| GET | `/athman/search` · `/athman/hizb/{n}` · `/athman/{id}` | auth | `AthmanController` (thumn search/lookup) |
| GET | `/parent/children` · `/parent/students/{id}` | parent | `StudentController` |
| GET | `/manager/dashboard` · `/manager/center` | manager | `CenterManagerController` |
| GET/POST | `/manager/students` (+`/next-code`) | manager | `StudentController@index/store/nextCode` (center forced) |
| PUT | `/manager/students/{id}/status` | manager | `StudentController@toggleStatus` (own center only) |
| GET | `/manager/parents/search` | manager | `StudentController@searchParents` |
| GET/POST/PUT | `/manager/teachers` (+`/next-code`, `/{id}`) | manager | `CenterManagerController` (no delete) |
| POST | `/manager/attendance/import` | manager | `AttendanceImportController@import` (center-scoped) |
| GET/PUT | `/manager/attendance` · `/manager/attendance/{id}/status` | manager | `CenterManagerController@attendanceIndex/correctAttendance` |
| GET | `/manager/reports/system` · `/manager/reports/management` | manager | `CenterManagerController` |
| GET | `/manager/reports/{center,at-risk,teachers}/pdf` | manager | `ReportPdfController@manager*` |
| GET/POST | `/manager/student-requests` (+`/{id}/approve`, `/{id}/reject`) | manager | `StudentRequestController` (internal transfers only) |
| apiResource (no destroy) | `/teachers` | admin | `TeacherController` |
| PUT | `/teachers/{id}/status` | admin | `TeacherController@toggleStatus` |
| GET | `/centers/{id}/has-primary` | admin | `TeacherController@hasPrimary` |
| apiResource (no destroy) | `/centers` | admin | `CenterController` |
| PUT | `/centers/{id}/status` | admin | `CenterController@toggleStatus` (revokes members' tokens) |
| POST | `/students` | admin | `StudentController@store` |
| PUT | `/students/{id}/status` | admin | `StudentController@toggleStatus` |
| GET | `/students/next-code` · `/parents/search` | admin | `StudentController` |
| GET/POST/PUT | `/admin/managers` (+`/{id}`, `/{id}/status`) | admin | `ManagerManagementController` (no delete) |
| GET | `/reports/admin/missing-national-id` | admin | `ReportController@missingNationalId` |
| GET | `/reports/admin/{center/{id},teachers,at-risk,overview}/pdf` | admin | `ReportPdfController` |
| GET/POST | `/admin/student-requests` (+`/{id}/approve`, `/{id}/reject`) | admin | `StudentRequestController` |
| GET/PUT/POST | `/profile` · `/profile/phone` · `/profile/password` | teacher | `TeacherProfileController` (token owner only) |
| apiResource (no store/destroy) | `/students` | teacher | `StudentController` (index/show/update) |
| GET/POST | `/student-requests` (+`/search-students`) | teacher | `StudentRequestController` |
| GET/POST | `/attendance`, `/attendance/report`, `/attendance/import` | teacher | `AttendanceController`, `AttendanceImportController@import` (xlsx) |
| GET | `/memorizations/surahs` · `/memorizations/students-progress` | teacher | `MemorizationController` |
| apiResource (index/store/destroy) | `/memorizations` | teacher | `MemorizationController` |
| apiResource (index/store/destroy/show) | `/weekly-tests` | teacher | `WeeklyTestController` |
| GET | `/reports/weekly` · `/reports/student/{id}` | teacher | `ReportController` |
| GET | `/reports/student/{id}/pdf` · `/reports/teacher/pdf` | teacher | `ReportPdfController` |

`teacher`-gated endpoints scope data to the caller's own students when the caller is a teacher, and to everything when admin (admin passes the `teacher` gate). The student-PDF route additionally enforces "own student only". All `manager`-gated endpoints scope to the caller's own `center_id` from the account (client-sent center ids are ignored/overwritten).

## Controllers (`app/Http/Controllers/Api/`)

- **AuthController** — unified login (issues role-scoped Sanctum abilities), logout, current user.
- **DashboardController** — `index` returns a role-aware dashboard (admin: system totals; teacher: own students/attendance/memorization); `publicStats` and `demoAccounts` are public.
- **TeacherController** — teacher CRUD (no destroy) + `toggleStatus` (revokes tokens on deactivate); one-primary-per-center via `PrimaryTeacherRule`; unified `q=` search (normalized name / display code / center name); exposes `hasPrimary`.
- **CenterController** — center CRUD (no destroy) + `toggleStatus` (deactivation revokes all members' tokens); `?active=1` filter for pick-lists; `show` returns counts + teacher list (type/status) without N+1.
- **CenterManagerController** — the manager panel: center dashboard, own-center teacher list/show/store/update (role + center forced server-side, primary rule under `lockForUpdate`), `teacherNextCode`, attendance review (`attendanceIndex`) + single-record correction (`correctAttendance`, audit fields), and center-scoped report groups (`reportsSystem`/`reportsManagement`).
- **ManagerManagementController** — admin-only CRUD (no destroy) + `toggleStatus` for center managers; one manager per center; fixed email scheme `{latin}.centeradmin@mutqin.ly`; deactivation devolves pending internal requests to admin with a notification.
- **TeacherProfileController** — teacher self-service on the token owner only: `show`, `updatePhone`, `changePassword` (current password required; revokes all tokens).
- **StudentController** — student CRUD (no destroy) + `toggleStatus` (admin any / manager own center), `nextCode` preview, guardian linking via `ParentResolver`, `searchParents`, and the two parent-facing read endpoints (child details include `weekly_tests` + `tests_summary`; inactive children stay visible with an `is_active` badge). `index` scopes by role (manager → center, teacher → own students) and defaults to active students only.
- **StudentRequestController** — teacher add/transfer requests; admin approves anything, manager approves internal transfers of their center only (`assertManagerScope`); approval re-validates (duplicate national id, target teacher still in target center) and executes inside a transaction; notification routing prefers the active center manager for internal transfers.
- **NotificationController** — per-user in-app notification list / mark-read / mark-all-read.
- **AttendanceController** — list/store/report (active students only). **AttendanceImportController** — xlsx (fingerprint-device) import (needs the `zip` extension); serves both the teacher and manager routes (manager scoped to their center).
- **MemorizationController** — list (paginated, `?juz=N` filter by surah's juz via `SurahReference`), store, destroy, `surahs` list, and `studentsProgress` (surah-completion-based progress).
- **WeeklyTestController** — weekly thumn tests with per-thumn questions (index/store/show/destroy; no update).
- **ReportController** + **ReportService** (`app/Services/`) — JSON report data (student, weekly, missing-national-id; `centerData`/`atRiskStudents`/`progressSummary`/`centerManagement` take an optional center scope for the manager). **ReportPdfController** — mPDF renderings, including `manager*` center-scoped variants.
- **AthmanController** — read-only thumn index search/lookup.

## Models (`app/Models/`) & relationships

- **User** `(name,display_code,email,phone,role,center_id,type,password,nationality_type,nationality_name,id_number,is_active,status_changed_by/at)` — `isAdmin/isCenterManager/isTeacher/isParent`; `students()` (teacher_id), `children()` (parent_id), `center()`, `weeklyTests()`, `passwordChangeLogs()`; `creating` hook reserves `display_code` for teacher/center_manager; `recordPasswordChange()` logs + revokes all tokens. Uses `HasApiTokens`.
- **Center** `(name,display_code,city,address,phone,is_active)` — `students()`, `teachers()`; `creating` hook reserves `C{n}` code.
- **Student** `(name,display_code,birth_date,phone,national_id,nationality_type,nationality_name,age,guardian_name,guardian_phone,center_id,teacher_id,former_teacher_name,parent_id,enrollment_date,is_active,status_changed_by/at)` — `center/teacher/parent` (belongsTo), `attendances/memorizations/revisions/tajweedEvaluations/weeklyTests` (hasMany); scope `missingNationalId`; `creating` hook reserves `S{n}` code.
- **StudentRequest** — add/transfer request rows (type, status, requester, from/target center+teacher, student snapshot incl. nationality + guardian fields, admin_note).
- **OtpReset**, **PasswordChangeLog** — OTP password-reset state and the password-change audit log.
- **Memorization** `(student_id,teacher_id,date,surah_name,juz,hizb,page_from,page_to,eighth,quality,notes)` — `student/teacher`.
- **WeeklyTest** `(student_id,teacher_id,result,date/exam_date,test_type,passed,notes)` + **WeeklyTestQuestion** `(weekly_test_id,student_id,eighth_start,result,mistake)` — a test `hasMany` questions (one per thumn).
- **Attendance** `(student_id,teacher_id,date,time,status,notes,imported_at,center_id)` — unique `(student_id,date)`.
- **Revision**, **TajweedEvaluation** — full models + relations exist but are **not exposed** (see Missing features).
- **Athman** `(hizb,thumn_in_hizb,global_order,surah_name,start_text,start_text_norm,page)` — read-only reference with `normalize()`/`normalizeQuery()` helpers.

`app/Support/SurahReference.php` is a static class (not a model) — the 114-surah→juz source of truth (see the architecture section).

## Database schema (MySQL `mutqin_db`)

Core tables: `users`, `centers`, `students`, `attendances`, `memorizations`, `revisions`, `tajweed_evaluations`, `weekly_tests`, `weekly_test_questions`, `athman`, `student_requests`, `otp_resets`, `password_change_logs`, `notifications`, `code_sequences` (display-code counters), plus Laravel's `personal_access_tokens`, `cache`, `jobs`, `sessions`, `password_reset_tokens`.

Schema evolved through migrations (note for anyone reading raw migration files):
- `users.role` started as an enum `[admin,teacher]`, was later **changed to a plain string** to allow `parent`; `center_id` and `type` (primary/assistant) were added in v2.
- `students` gained `age` (v2), `parent_id` (parent link), and `national_id` (unique, optional) in later migrations. `birth_date` predates `age` and is largely vestigial — **`age` is the field actually used**.
- `weekly_tests` went through a `date`→`exam_date`→`date` rename and carries overlapping `result` (ناجح/راسب) + `passed` + `test_type` columns — historical cruft; the per-thumn detail lives in `weekly_test_questions`.
- `attendances` gained `time`/`imported_at`/`center_id` to support xlsx fingerprint import, and later `corrected_by`/`corrected_at` for the manager's correction audit.
- Later waves added: `display_code` columns + `code_sequences` + backfills (users/students, then centers), `users.is_active` + status audit columns, and `students` status audit (`status_changed_by/at`).

## Frontend structure (`frontend-html/`)

Static, role-segmented pages; each is a self-contained IIFE using the shared `window` modules.

- **Public**: `index.html` (landing, `/public/stats`), `login.html` (unified login, demo-accounts panel), `forgot-password.html` (OTP flow).
- **admin/**: `dashboard`, `centers`, `teachers`, `students`, `managers`, `requests`, `reports`.
- **manager/**: `dashboard`, `students`, `teachers`, `attendance`, `attendance-review`, `requests`, `reports`.
- **teacher/**: `dashboard`, `students`, `attendance`, `memorization`, `weekly-tests`, `requests`, `profile`, `reports`.
- **parent/**: `dashboard` (children), `child` (read-only details).
- **js/**: `config.js` (API URL + storage keys), `api.js` (fetch wrapper + envelope/401 handling), `auth.js` (login/logout/`requireAuth`), `ui.js` (toasts, `formModal`, badges, table search, xlsx import, athman autocomplete — the largest module), `layout.js` (role-based sidebar/topbar), plus `pages/login.js` and `landing.js`.
- **css/theme.css**: brand identity (emerald/gold/ivory, Amiri + Cairo fonts).

## Missing / dormant features

Things present in the data model but not wired end-to-end — likely roadmap items:

- **Revisions (المراجعة)** and **Tajweed evaluations (التجويد)**: tables + models + Student relations exist, and `StudentController@show` reads revisions, but there is **no controller, route, or UI to record them**, and `ReportService` ignores them.
- **No self-registration**: parents, teachers, and managers are created server-side (admin or center manager) only; there is no public signup.
- **No update for some records**: weekly-tests have no update endpoint (create/delete only); attendance updates exist only as the manager's single-record status correction.
- **No SMS gateway** for OTP delivery (dev flow shows the code to the admin in `local` only) — see DEPLOYMENT.md.

(Resolved since first written: password reset exists via phone OTP; login is rate-limited; a real feature-test suite exists — see Gotchas. Hard deletion was replaced everywhere by activate/deactivate with `status_changed_by/at` + attendance `corrected_by/at` audit fields, closing most of the old "no soft-deletes/audit trail" gap.)
