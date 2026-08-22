# n8n — ملخّص الحضور اليومي

Import `mutqin-daily-attendance-digest.json` into n8n: **Workflows → ⋯ → Import from File**.

## What it does

Every day at 20:00 (Africa/Tripoli) it logs into the MUTQEN API, pulls today's
attendance, builds an Arabic summary, and emails it — but only if there is
something worth reporting (an absence, or a student whose attendance was never
recorded).

```
Schedule (20:00) → Set config → POST /api/auth/login → GET /api/attendance?date=…
   → Code (summarise) → IF needsAttention → Email  /  NoOp
```

## Before the first run

1. **الإعدادات** node — set `apiBase` (default `http://localhost:9090`),
   `email`, `password`, and `sendTo`.
   The password sits in plain text for demo convenience; for anything real,
   replace it with an n8n credential and delete the field.
2. **إرسال الملخّص** node — attach an SMTP credential.
3. The API must be reachable from n8n. If n8n runs in Docker, `localhost` is the
   container — use `http://host.docker.internal:9090` instead.

## Notes

- Uses `GET /api/attendance` (daily), not `/api/attendance/report` (monthly).
  The route is `teacher`-gated, so the account must be **admin or teacher** —
  an admin sees all active students, a teacher only their own.
- Admins can add a `center_id` query parameter on **جلب حضور اليوم** to scope
  the digest to a single center.
- Workflow timezone is pinned to `Africa/Tripoli` to match `config/app.php`;
  the date is computed with `$now.setZone('Africa/Tripoli')`, so the digest
  never drifts a day on a UTC host.
- "لم يُسجَّل" counts active students with no attendance row for that date —
  usually a teacher who forgot to record, which is the point of the alert.
