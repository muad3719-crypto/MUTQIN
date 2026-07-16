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
C:\xampp\php\php.exe artisan test                   # 23+ feature tests (uses the separate `mutqin_test` MySQL DB — phpunit.xml; sqlite won't work: raw MySQL ALTER in one migration)
C:\xampp\php\php.exe artisan test --filter=SomeTest # single test
C:\xampp\php\php.exe artisan tinker --execute='...' # quick DB/logic checks (used heavily for verification)
```

Serving the frontend: it is static, so `cd frontend-html && C:\xampp\php\php.exe -S localhost:8080` or Apache at `http://localhost/MUTQENQ/frontend-html/`. The API URL lives in one place — `frontend-html/js/config.js` (`API_BASE_URL`). Auth state is kept in `localStorage` under keys `mutqin_token` / `mutqin_user`.

Demo accounts (password `mutqin2026`): `admin@mutqin.ly` (admin), managers `{name}.centeradmin@mutqin.ly`, teachers `@mutqin.ly`, parents `@parent.mutqin.ly`. The login page pulls a live list from `/api/public/demo-accounts`. (Feature tests create their own users with password `password` — independent of seeders.)

> The `تشغيل-المشروع.bat` launcher is partly stale — its web step runs `cd frontend && php artisan serve`, but there is no `frontend/` Laravel app (the real client is the static `frontend-html/`). Its MySQL + API (9090) steps are fine.

## Architecture

### Auth is the load-bearing design — role + token-ability dual check

One login endpoint `POST /api/auth/login` serves all three roles. `users.role` is a string: `admin | teacher | parent`. The privilege is encoded **twice**: in the role *and* in the Sanctum token abilities granted at login (`AuthController::login`):

- parent → token abilities `['parent']`
- admin / teacher → token abilities `['*']`

The three middleware aliases (`admin`, `teacher`, `parent`, registered in `bootstrap/app.php`) each verify **both** the role and `tokenCan(...)`:

- `admin`   → `isAdmin() && tokenCan('*')`
- `teacher` → `role in [teacher, admin] && tokenCan('*')`
- `parent`  → `isParent() && tokenCan('parent')`

This dual check exists specifically to stop privilege escalation (a parent-scoped token must never reach admin/teacher routes even if pointed at one). **When adding any protected route, gate it with the correct alias — never rely on `role` alone.** All routes live in `backend/routes/api.php`, grouped by these aliases. Note the deliberate asymmetry: student **create** is admin-only (`POST /students` in the `admin` group), while teachers get the students resource `except(['store'])`.

### API/response contract

Every endpoint returns the envelope `{ success, message, data, errors }`. Validation failures are HTTP 422 with `errors` keyed by field and **Arabic** messages. `frontend-html/js/api.js` wraps `fetch`: it attaches `Authorization: Bearer <token>`, and on 401 clears storage and redirects to login. Keep new controllers in this envelope shape or the frontend breaks.

### Frontend page pattern

Every protected page is a small IIFE that does: `Auth.requireAuth([roles])` → `Layout.mount({user, active, title})` (returns the page container) → `API.get(...)` to load → render → wire `UI.formModal(...)` for create/edit. The shared modules attached to `window` are `Config, API, Auth, UI, Layout` (in `js/`). No framework, no bundler.

### Domain model & key rules

- **User** (admin/teacher/parent): a teacher `hasMany` students via `teacher_id`; a parent `hasMany` children via `students.parent_id`.
- **Student**: belongs to center, teacher, and parent. `guardian_name`/`guardian_phone` are **display-only**; the real guardian link is `parent_id`. Guardians are de-duplicated **by phone** (siblings share one parent account). `national_id` is optional, unique, Libyan male format. Parent/guardian email creation is two-step: insert with a temp email, then set `{latin}.{id}@domain` (the id only exists after insert).
- **Teacher `type`**: `محفظ أساسي` (primary) or `محفظ معاون` (assistant). Business rule: **at most one primary per center** — enforced in `TeacherController::assertSinglePrimary` (on store/update) and surfaced via `GET /api/centers/{id}/has-primary` (the teachers form disables the primary option accordingly).
- **Memorization** is tracked per surah session. Progress is **reverse order**: memorization starts at juz 30 (سورة الناس) and proceeds down toward juz 1.

### Surah/juz reference — the source of truth (`app/Support/SurahReference.php`)

`memorizations.juz` is unreliable hand-entered data (the same surah has been stored under different juz numbers). **Do not trust the stored `juz` column.** Derive a surah's juz from its `surah_name` via the static 114-surah `SurahReference::SURAHS` table. A juz counts as **complete only when every one of its surahs is recorded** — student progress is computed by surah completion, not by the presence of any single record. `SurahReference::namesOfJuz($n)` and `::progress($surahNames)` are the entry points. (The `athman` table is a separate thumn-level index for search/autocomplete — it covers only 92 surahs and has no juz column, so it cannot enumerate a full juz.)

### PDF reports

`ReportPdfController` uses **mPDF**. Render numbers with **Western digits** — Arabic-Indic digits show as empty boxes in the default font.

### Later-added subsystems (beyond the base CRUD)

- **Student requests** (`StudentRequestController`, `student_requests`): a teacher *requests* adding/transferring a student; only admin approval mutates `students`. Duplicate detection by national id suggests transfer instead of add.
- **In-app notifications**: Laravel database channel via the generic `App\Notifications\InAppNotification` (`sendSafe()` never breaks the primary operation). Types: `request_created/approved/rejected`, `memorization_added`, `test_added` (parents). Bell UI in `layout.js` polls every 60s.
- **Password reset by phone OTP** (`/auth/forgot-password/*`): hashed 6-digit OTP, 10-min expiry, 5 attempts, throttled. No SMS gateway — `AuthController::sendOtp()` is the single integration point; in `local` env only, the response carries `dev_otp` for the admin to relay.
- **Password-change tracking**: `password_changed_count`/`password_last_changed_at` + `password_change_logs` (method: otp/self/admin) via `User::recordPasswordChange()` — which also **revokes all tokens** (S1). Sanctum tokens expire after 7 days.
- **Nationality & identity**: students and parents have `nationality_type` (libyan/foreigner) + `nationality_name`; the parent's unified identifier is `users.id_number` (unique). Libyan format `^[12]\d{11}$` enforced only for `libyan`.
- **Guardian resolution** is centralized in `App\Support\ParentResolver` (id_number → normalized phone → email, parent-role scoped, random password on create, 422 Arabic on unusable data). Phones are normalized everywhere via `App\Support\PhoneNumber::normalize` (+218/00218/Arabic digits → `09xxxxxxxx`).
- **Deleted teachers**: records keep history (`teacher_id` → null, D1) and students carry `former_teacher_name` for display («محفّظ سابق: فلان»).

## Gotchas

- `ExtraDataSeeder` **appends** (it does not wipe) and is center-aware to respect the one-primary-per-center rule; re-running it adds more data.
- Two PHP extensions must be enabled in `C:\xampp\php\php.ini` for full functionality: `zip` (xlsx attendance import) and `gd` (mPDF). They were disabled by default in this install.
- App timezone is **Africa/Tripoli** (config/app.php) — do not revert to UTC; "today" logic (attendance defaults, dashboard stats) depends on it, and the frontend uses `UI.todayStr()` (local) rather than `toISOString()`.
- The week runs **Saturday → Friday** (center's convention). Carbon 3 removed the global `setWeekStartsAt`, so week-scoped queries pass `startOfWeek(Carbon::SATURDAY)`/`endOfWeek(Carbon::FRIDAY)` explicitly (dashboard weekly stat + weekly report) — new week-scoped code must do the same.
- `php artisan serve` is single-threaded: concurrent requests serialize (slow dependent dropdowns in dev). Set `PHP_CLI_SERVER_WORKERS=4` or use Apache.
- Feature tests live in `tests/Feature` (role matrix, ownership, login throttle, OTP, request notifications, phone normalization) against the `mutqin_test` DB. Keep them green — they are the only guard on the dual role+ability security model.

---

# Reference

## API routes (`backend/routes/api.php`)

Gate column = the middleware group (see the auth section above for what each enforces).

| Method | Path | Gate | Handler |
|---|---|---|---|
| POST | `/auth/login` | public | `AuthController@login` |
| GET | `/public/stats` | public | `DashboardController@publicStats` |
| GET | `/public/demo-accounts` | public | `DashboardController@demoAccounts` |
| POST | `/auth/logout` | auth | `AuthController@logout` |
| GET | `/auth/user` | auth | `AuthController@user` |
| GET | `/dashboard` | auth | `DashboardController@index` (role-aware payload) |
| GET | `/athman/search` · `/athman/hizb/{n}` · `/athman/{id}` | auth | `AthmanController` (thumn search/lookup) |
| GET | `/parent/children` | parent | `StudentController@parentChildren` |
| GET | `/parent/students/{id}` | parent | `StudentController@parentStudentDetails` |
| apiResource | `/teachers` | admin | `TeacherController` |
| GET | `/centers/{id}/has-primary` | admin | `TeacherController@hasPrimary` |
| apiResource | `/centers` | admin | `CenterController` |
| POST | `/students` | admin | `StudentController@store` (create is admin-only) |
| GET | `/parents/search` | admin | `StudentController@searchParents` |
| GET | `/reports/admin/missing-national-id` | admin | `ReportController@missingNationalId` |
| GET | `/reports/admin/{center/{id},teachers,at-risk,overview}/pdf` | admin | `ReportPdfController` |
| apiResource (no store) | `/students` | teacher | `StudentController` (index/show/update/destroy) |
| GET/POST | `/attendance`, `/attendance/report`, `/attendance/import` | teacher | `AttendanceController`, `AttendanceImportController@import` (xlsx) |
| GET | `/memorizations/surahs` · `/memorizations/students-progress` | teacher | `MemorizationController` |
| apiResource (index/store/destroy) | `/memorizations` | teacher | `MemorizationController` |
| apiResource (index/store/destroy/show) | `/weekly-tests` | teacher | `WeeklyTestController` |
| GET | `/reports/weekly` · `/reports/student/{id}` | teacher | `ReportController` |
| GET | `/reports/student/{id}/pdf` · `/reports/teacher/pdf` | teacher | `ReportPdfController` |

`teacher`-gated endpoints scope data to the caller's own students when the caller is a teacher, and to everything when admin (admin passes the `teacher` gate). The student-PDF route additionally enforces "own student only".

## Controllers (`app/Http/Controllers/Api/`)

- **AuthController** — unified login (issues role-scoped Sanctum abilities), logout, current user.
- **DashboardController** — `index` returns a role-aware dashboard (admin: system totals; teacher: own students/attendance/memorization); `publicStats` and `demoAccounts` are public.
- **TeacherController** — teacher CRUD; enforces one-primary-per-center (`assertSinglePrimary`) and exposes `hasPrimary`.
- **CenterController** — center CRUD.
- **StudentController** — student CRUD + guardian linking via `ParentResolver`, `searchParents`, and the two parent-facing read endpoints (child details include `weekly_tests` + `tests_summary`). `show` eager-loads attendances/memorizations/weeklyTests (revisions load removed — dead, no UI consumer).
- **AttendanceController** — list/store/report. **AttendanceImportController** — xlsx (fingerprint-device) import (needs the `zip` extension).
- **MemorizationController** — list (paginated, `?juz=N` filter by surah's juz via `SurahReference`), store, destroy, `surahs` list, and `studentsProgress` (surah-completion-based progress).
- **WeeklyTestController** — weekly thumn tests with per-thumn questions (index/store/show/destroy; no update).
- **ReportController** + **ReportService** (`app/Services/`) — JSON report data (student, weekly, missing-national-id). **ReportPdfController** — mPDF renderings of the same.
- **AthmanController** — read-only thumn index search/lookup.

## Models (`app/Models/`) & relationships

- **User** `(name,email,phone,role,center_id,type,password)` — `isAdmin/isTeacher/isParent`; `students()` (teacher_id), `children()` (parent_id), `center()`, `weeklyTests()`. Uses `HasApiTokens`.
- **Center** `(name,city,address,phone,is_active)` — `students()`, `teachers()`.
- **Student** `(name,birth_date,phone,national_id,age,guardian_name,guardian_phone,center_id,teacher_id,parent_id,enrollment_date,is_active)` — `center/teacher/parent` (belongsTo), `attendances/memorizations/revisions/tajweedEvaluations/weeklyTests` (hasMany); scope `missingNationalId`.
- **Memorization** `(student_id,teacher_id,date,surah_name,juz,hizb,page_from,page_to,eighth,quality,notes)` — `student/teacher`.
- **WeeklyTest** `(student_id,teacher_id,result,date/exam_date,test_type,passed,notes)` + **WeeklyTestQuestion** `(weekly_test_id,student_id,eighth_start,result,mistake)` — a test `hasMany` questions (one per thumn).
- **Attendance** `(student_id,teacher_id,date,time,status,notes,imported_at,center_id)` — unique `(student_id,date)`.
- **Revision**, **TajweedEvaluation** — full models + relations exist but are **not exposed** (see Missing features).
- **Athman** `(hizb,thumn_in_hizb,global_order,surah_name,start_text,start_text_norm,page)` — read-only reference with `normalize()`/`normalizeQuery()` helpers.

`app/Support/SurahReference.php` is a static class (not a model) — the 114-surah→juz source of truth (see the architecture section).

## Database schema (MySQL `mutqin_db`)

Core tables: `users`, `centers`, `students`, `attendances`, `memorizations`, `revisions`, `tajweed_evaluations`, `weekly_tests`, `weekly_test_questions`, `athman`, plus Laravel's `personal_access_tokens`, `cache`, `jobs`, `sessions`, `password_reset_tokens`.

Schema evolved through migrations (note for anyone reading raw migration files):
- `users.role` started as an enum `[admin,teacher]`, was later **changed to a plain string** to allow `parent`; `center_id` and `type` (primary/assistant) were added in v2.
- `students` gained `age` (v2), `parent_id` (parent link), and `national_id` (unique, optional) in later migrations. `birth_date` predates `age` and is largely vestigial — **`age` is the field actually used**.
- `weekly_tests` went through a `date`→`exam_date`→`date` rename and carries overlapping `result` (ناجح/راسب) + `passed` + `test_type` columns — historical cruft; the per-thumn detail lives in `weekly_test_questions`.
- `attendances` gained `time`/`imported_at`/`center_id` to support xlsx fingerprint import.

## Frontend structure (`frontend-html/`)

Static, role-segmented pages; each is a self-contained IIFE using the shared `window` modules.

- **Public**: `index.html` (landing, `/public/stats`), `login.html` (unified login, demo-accounts panel).
- **admin/**: `dashboard`, `centers`, `teachers`, `students`, `reports`.
- **teacher/**: `dashboard`, `students`, `attendance`, `memorization`, `weekly-tests`, `reports`.
- **parent/**: `dashboard` (children), `child` (read-only details).
- **js/**: `config.js` (API URL + storage keys), `api.js` (fetch wrapper + envelope/401 handling), `auth.js` (login/logout/`requireAuth`), `ui.js` (toasts, `formModal`, badges, table search, xlsx import, athman autocomplete — the largest module), `layout.js` (role-based sidebar/topbar), plus `pages/login.js` and `landing.js`.
- **css/theme.css**: brand identity (emerald/gold/ivory, Amiri + Cairo fonts).

## Missing / dormant features

Things present in the data model but not wired end-to-end — likely roadmap items:

- **Revisions (المراجعة)** and **Tajweed evaluations (التجويد)**: tables + models + Student relations exist, and `StudentController@show` reads revisions, but there is **no controller, route, or UI to record them**, and `ReportService` ignores them.
- **No self-registration**: parents and teachers are created server-side/by admin only; there is no public signup.
- **No update for some records**: attendance and weekly-tests have no update endpoint (create/delete only).
- **No SMS gateway** for OTP delivery (dev flow shows the code to the admin in `local` only) — see DEPLOYMENT.md.
- **No soft-deletes/audit trail** despite handling minors' PII (national IDs, parent contacts).

(Resolved since first written: password reset exists via phone OTP; login is rate-limited; a real feature-test suite exists — see Gotchas.)
