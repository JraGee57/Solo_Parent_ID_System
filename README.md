# Virac Solo Parent ID Online Portal (e-SoloParentID) — Implementation

PHP 8 + MySQL + Bootstrap 5 implementation of the design handoff in
`../design_handoff_solo_parent_portal/`. Built and tested against XAMPP
(Apache + MariaDB) on Windows.

**Status:** Implemented workflows are described under **What's implemented**.
See **Security notes**, **UI review notes**, and **Recent updates and
verification (October 13, 2026)** for review scope and verification details.

## Setup (XAMPP)

1. Copy (or symlink) this `virac-soloparent/` folder into `C:\xampp\htdocs\`.
2. Start Apache and MySQL in the XAMPP Control Panel.
3. Create the database structure, then load the reference data (roles,
   the 9 solo-parent categories and their document requirements, FAQs,
   SMS templates) and the initial administrator:
   ```
   "C:\xampp\mysql\bin\mysql.exe" -u root < database\schema.sql
   "C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\seed.sql
   ```
   (or phpMyAdmin → Import `database/schema.sql`, then `database/seed.sql`).
4. Log in at `/admin/login.php` as **admin** / **Admin@Virac2026** and
   change that password straight away (**My Account** in the menu). Use
   **Manage Users** to create MSWD Staff accounts (there's no other way to
   create staff accounts, by design).
5. Visit `http://localhost/virac-soloparent/index.php`.

Default DB credentials are `root` / no password, matching a stock XAMPP
install. If yours differ, copy `config/local.example.php` to
`config/local.php` and edit it (that file is git-ignored). Set
`APP_DEBUG` to `true` there to see error details while developing.

## Keeping an existing database aligned

`database/schema.sql` is the canonical structure for the current system
(31 tables). It recreates the database and is for fresh installations only.
The historical `migrate_*.sql` files describe upgrades from specific older
versions; do not import all of them into an already current database.

Use the configured connection from `config/app.php` / `config/local.php`:

```powershell
php database/sync_schema.php --check
php database/sync_schema.php --apply
```

The check compares columns, types, defaults, nullability, indexes, foreign
keys, table options, and the audit protection triggers against an isolated
copy of the current schema. Index ordering and auto-increment counters are
ignored. The temporary reference database is removed after the command;
the database account needs permission to create and drop that temporary
database. Both commands leave a matching database unchanged.

`--apply` adds missing tables, columns, indexes, foreign keys and triggers.
Before any upgrade it saves a structure-and-data backup in
`storage/db_backups/`. It preserves existing records, reference data,
custom SMS templates and settings. Incompatible column types, constraints,
or legacy unique keys are reported for a reviewed migration rather than
changed automatically. DDL is not transactional: if an addition fails,
the backup remains available and `--check` reports what is still pending.
Run upgrades while the application is not receiving writes so the backup
and data checks stay consistent.

This command synchronizes structure only. Feature migrations can also
backfill data: `migrate_document_checklists.sql` now inserts only missing
entries, uses the same category/renewal requirements as the application,
and preserves applicant declarations and staff reviews when rerun.
`migrate_interview_sms_tracking.sql` can also be rerun without duplicate
column, index or foreign-key errors. Neither is required after a fresh
schema-and-seed installation.

### Fixed one-year ID validity

New and renewed Solo Parent IDs expire **one calendar year after confirmed
release**, rather than after approval or the planned handover appointment.
The release code enforces this period even if an older installation has a
different saved `id_validity_years` value. System Settings displays a fixed,
read-only value of 1; the server rejects a longer validity period.

For an existing installation, apply `database/migrate_fixed_id_validity.sql`
to align the saved setting. With the default XAMPP connection, run from the
project directory in PowerShell:

```powershell
Get-Content -Raw database/migrate_fixed_id_validity.sql | & 'C:\xampp\mysql\bin\mysql.exe' -u root virac_solo_parent
```

Use the database name and account from your installation if they differ.
This migration is safe to rerun and updates only the validity setting;
existing ID and QR expiry dates stay unchanged. `sync_schema.php` does not
apply this setting update because it synchronizes database structure only.
Fresh installations use the one-year default without this migration.
For future releases, QR validity follows the recorded ID expiry, and renewal
access and reminders continue to use that expiry date.

## QR codes on the application slip

The printable slip (`slip.php`) and the applicant's **My Solo Parent ID**
page show a QR code. It encodes only a link to the MSWDO staff
verification page with a random 256-bit token (`qr_application_slips`) —
no name or reference number — and staff must be signed in to use it.
QR images need the `endroid/qr-code` library (pinned in `composer.lock`):
```
composer install
```
Without it the slip still works and shows the slip code as text instead of
a QR image.
## SMS delivery

`services/SMSService.php` sends every text message and logs each attempt in
`sms_logs` (PENDING, then SENT or FAILED with the gateway's answer). Messages
come from the admin-editable templates on **SMS Notifications**, where
failed messages can also be retried and a test SMS sent.

Texts about an application go to the applicant's **confirmed account
number**, so a number changed and re-confirmed on My Profile after
submission is used from then on; the number saved on the application is
used only while the account has no confirmed number. The application keeps
its original number as part of the record. Staff application pages show
both — **Confirmed mobile number** (used for SMS) and **Application mobile
number** (as submitted), flagged when they differ — or a single row when
they are the same.

It runs in **test mode** by default: messages are logged (flagged as test)
but nothing is sent. To send real SMS through Semaphore PH, set these as
server environment variables or in `config/local.php` (never in code):

| Setting | Default | |
|---|---|---|
| `SMS_PROVIDER` | `test` | `semaphore` to send for real |
| `SMS_API_KEY` | (none) | your Semaphore API key |
| `SMS_SENDER_NAME` | `VIRACMSWD` | must be approved by Semaphore |
| `SMS_API_URL` | Semaphore's messages API | point at a sandbox/mock gateway to test |
| `SMS_TIMEOUT` | `10` | seconds per gateway call |
| `SMS_MAX_ATTEMPTS` | `3` | attempts allowed per message, including retries |

Password-reset codes are sent but never stored: the log keeps the text with
the code masked.

## Scheduled jobs (renewal and appointment reminders)

Both run from the command line only (`cron/`, which the web server refuses
to serve) and log every run in `scheduled_job_runs`; the latest runs show
on **System Settings**, which warns if a job hasn't run in two days.

- `cron/renewal-reminders.php` — **daily**. Reminds (in-app + SMS) every
  applicant whose released ID expires within the configured window
  (default 30 days, **System Settings** → Renewal reminder period), once
  per ID per expiry date (`renewal_reminders`). IDs with a renewal already
  filed and deactivated accounts are skipped; an ID whose window was missed
  gets a catch-up reminder after it expires. Safe to run any number of
  times — overlapping runs are locked out and never double-send.
- `cron/appointment-reminders.php` — **hourly**. Reminds applicants of a
  booked appointment within the configured window (default 24 hours), once
  per scheduled time.

Windows Task Scheduler: Create Task → Trigger (Daily / Hourly) → Action:
Start a program, Program `C:\xampp\php\php.exe`, Arguments
`C:\xampp\htdocs\virac-soloparent\cron\renewal-reminders.php` (or
`...\cron\appointment-reminders.php`). On Linux, e.g.
`0 8 * * * php /path/to/virac-soloparent/cron/renewal-reminders.php`.
Both can also be run by hand, or with **Send Reminders Now** on System
Settings. (The old `database/send_*_reminders.php` paths still work.)
## Chat Support (Help Assistant)

`applicant/dashboard.php?section=chat` — `services/HelpAssistantService.php`,
`models/ChatMessage.php`, conversation stored in `chat_messages`.

- A **rule-based assistant** (no external AI; nothing leaves the server)
  answers questions about applying, documents, appointments, renewal, the
  QR slip / ID, where things are in the portal and the office — from the
  applicant's own records (status and correction reasons, documents that
  need fixing, their appointment, renewal window) and the FAQ list, with
  links to the right page. Links are stored as keys from a fixed list, so
  a reply can never point outside the app.
- **It never decides.** Any question about eligibility, approval or
  rejection gets the same answer: the assistant can't say, *official
  decisions are made only by MSWD staff*, plus what is recorded so far and
  a link to apply. The same notice is
  shown above the chat.
- **Ask MSWDO staff** hands the conversation to MSWD social workers
  (permission `chat.respond`), who are notified once per open request and
  reply from `staff/chat.php` (waiting conversations first). Staff only see
  conversations in which the applicant asked for staff; each reply
  notifies the applicant and is audit-logged. Admins don't handle chats.
- Limits: 500 characters per applicant message (1000 per staff reply),
  30 messages per 5 minutes per applicant, CSRF on every post, all text
  escaped on output.

## Actors & access control

The system has three account roles (`users.role`) plus the SMS service,
matching the e-SoloParentID system design:

| Role | Can | Landing page |
|---|---|---|
| `applicant` | Register, apply, upload documents, track status/history, request ID renewal, view interview schedule, view notifications, read announcements | `applicant/dashboard.php` |
| `mswd_social_worker` | Review applications & renewals, validate documents, assess eligibility, manage appointments, approve/reject/request correction, verify by QR, update statuses | `staff/dashboard.php` (processing-focused) |
| `admin` | System-wide monitoring, analytics & reports, user/account management, SMS & notification management, application requirements & system settings, audit logs, database/system monitoring & backup. Can view applications read-only; decisions, document verification and interviews belong to MSWD staff only | `admin/dashboard.php` (system-management-focused) |

Access control is enforced **server-side** on every protected page via
`require_staff_login()` / `require_admin_login()` in `includes/auth.php` —
never just by hiding a nav link. A deactivated account (`users.is_active`)
loses access on its very next request, even mid-session, not just on next
login. `current_staff()`/`login.php` treat `mswd_social_worker` and `admin`
as the two "staff" roles that can sign in at `/admin/login.php`;
`require_admin_login()` additionally bounces non-admin staff back to their
own dashboard.

Applicants only ever see and act on their own data — every applicant-facing
query filters by the logged-in `user_id`; there is no code path that trusts
a raw `?id=`/`?user_id=` parameter for authorization.

## What's implemented

**Applicant**
- Home, Services list, and the full Solo Parent ID portal (Overview, Apply
  Online, My Applications, Check Status) per the design handoff, plus a
  dedicated fixed-sidebar, mobile-first **Dashboard** (`applicant/dashboard.php`) for
  logged-in applicants covering every stage of the process: Dashboard home,
  Apply, My Application, Upload Documents, Appointment,
  ID Renewal, My Solo Parent ID, News & Announcements, Notifications, Chat
  Support, and Profile.
- The 4-step application (Personal Info → Children → Documents → Review),
  with the in-progress draft and uploaded files kept in the applicant's
  session/temp storage until final submit, at which point real
  `applications` / `application_children` / `application_documents` rows
  are created in one DB transaction.
- **Eligibility Checker** (`eligibility.php`, linked from the public Overview
  tab): a 3-question pre-application check with an instant result — never
  the final legal determination, which stays with MSWD. It is public only:
  the applicant dashboard has no eligibility step (old
  `?section=eligibility` links go to the dashboard), and a check made
  before logging in or registering is attached to the account and shown on
  the dashboard until an application exists.
- **Application status tracker**: each application shows a 6-step visual
  progress bar (`application_stage_info()` in `includes/functions.php`)
  mapped from `applications.status` — Eligibility Check → Application
  Submitted → Documents Under Review → Interview/Assessment → Approved →
  QR/Slip Generated — plus a distinct "Needs Correction" banner (with the
  staff's remarks) when a revision is requested, since that's a detour, not
  a fixed step.
- **Upload Documents**: shows every requirement for the applicant's
  category (required vs. optional), each document's verification status
  (`Pending` / `Verified` / `Needs Correction`) and any staff remarks, and
  lets the applicant upload what's missing or replace a flagged document —
  re-uploading resets that document back to `Pending` for MSWD to recheck.
  Only allowed while the application is still in an editable status.
- **Appointment**: applicant self-service interview/assessment booking
  against slots MSWD opens up (`appointment_slots` table, managed at
  `staff/appointment_slots.php`) — pick an open date/time, or reschedule
  into a different open slot. Staff can still schedule/reschedule directly
  from the application page with no slot at all; both paths write to the
  same `appointments` row, and the review page shows who booked it.
- **ID Renewal**: a real, separate single-step flow (`services/RenewalService.php`)
  gated on already holding an `ID Released` application — re-confirms the
  standard documents only (not the original qualifying circumstance) and
  creates a real `applications` row with `type = 'renewal'`, which MSWD
  processes through the exact same review page as new applications.
- **My Solo Parent ID**: once released, shows the QR/slip plus a
  Valid/Expired badge computed from `applications.id_expires_at` (set to
  exactly one year after confirmed release for new and renewed IDs).
- **Chat Support**: a Help Assistant plus hand-off to MSWDO staff (see
  *Chat Support* below), with the searchable, admin-managed FAQ
  (`admin/faq.php`, `faqs` table) and the office contact details below it.
- **Profile**: view/update full name, email, and mobile; change password;
  upload a profile photo (served access-controlled via `avatar.php`, same
  owner-or-staff pattern as `download.php`).
- Server-side validation mirroring the design handoff's rules (PH mobile
  format, required docs per category, etc.) — never trust the browser.
- Per-category document requirements driven by the `document_requirements`
  table, so staff can add/edit requirements without touching code.
- Uploaded documents stored outside public access (`storage/uploads/`,
  `.htaccess`-denied) and served only via `download.php`, which checks the
  requester is the owning applicant or staff.
- Public reference-number status checker (masks the applicant's name).
- **Notifications**: a real internal notification system (`notifications`
  table, independent of the SMS delivery log) with a persistent read/unread
  state, generated for registration, application/renewal submission,
  appointment confirmation, appointment reminder, correction, approval,
  rejection, and renewal reminder. News & Announcements keeps its own
  session-tracked unread indicator (a different feature, no DB table).

**MSWD Social Worker** (pages under `staff/`, shared with `admin` unless noted)
- Application review queue (`staff/index.php`, filterable by status *and*
  type — New vs. Renewal) and detail page (`staff/application.php`):
  view documents, change status, and the change is recorded in both
  `status_history` (human-readable log) and `applications.reviewed_by`
  (a real FK to the reviewing account).
- **Eligibility Assessment**: MSWD's own eligible/not-eligible determination
  with remarks (`applications.eligibility_status`/`eligibility_remarks`),
  recorded directly on the application review page — distinct from the
  applicant's own non-binding self-check.
- **Interview scheduling**: schedule/reschedule an interview (date, time,
  location, notes) directly from the application page — this also sets
  the application's status to `Interview Scheduled` and sends an SMS.
  After the interview, record its result separately (recording a result
  does not itself change the application status — the social worker still
  makes the actual approve/reject/etc. decision as its own step).
- A dedicated **MSWD Dashboard** (`staff/dashboard.php`): pending / under
  verification / for revision / pending renewal counts (each linking into
  the filtered queue), upcoming interviews, and applications recently
  processed by all staff, with the last reviewer's name shown.
  Newly created staff accounts can immediately access existing applications,
  applicant records and appointments and continue permitted casework after
  sign-in. Creating an account or opening the dashboard does not transfer
  record ownership or replace previous staff history; new actions retain
  their own staff attribution.
- **Document verification**: per-document status (`Pending` / `Verified` /
  `Needs Correction`) and remarks, set directly from the application review
  page — surfaces immediately on the applicant's Upload Documents page.
- **Appointment Management** (`staff/appointments.php`): today's
  appointments, an upcoming calendar grouped by date, and open-slot
  availability at a glance; **Appointment Slots** (`staff/appointment_slots.php`)
  opens up the date/time blocks (with a capacity) that applicants can
  self-book from — a slot with existing bookings can't be deleted.
- **Applicant Records** (`staff/applicants.php` search/list,
  `staff/applicant_view.php` detail; `services/RecordService.php`,
  `models/ApplicantRecord.php`): centralised, read-only record management
  for MSWD staff and administrators (`applicants.view`). Search by
  applicant name, name on an application, reference number, username,
  email or mobile, and filter by barangay (every barangay on file),
  application status (or "no application yet"), application type and
  account status — status and type must hold for the same application —
  sorted by name or latest application, 20 per page for staff and 10 per page
  for administrators. The record page shows
  the profile, application history, renewal history (with the ID each
  renewal renews), every document (status, verifier, remarks, view link),
  appointments (outcome or cancellation reason), decisions and the full
  status history (25 per page); the lower sections can be narrowed to one
  application. Changes still happen on the application's processing page
  (`staff/index.php` queue, which has its own status/type/barangay filters).
- **QR Verification** (`staff/qr_verify.php`): scan an applicant's QR code
  (camera-based, via the `jsQR` library) or type in a reference number to
  jump straight to that application's review page.
- **Reports** (`staff/reports.php`): the same eight reports as the
  administrators' Reporting page (see below), with print and CSV export.

**System Administrator** (pages under `admin/`)
- **Application Monitoring** (`admin/applications.php` list,
  `admin/application_view.php` read-only detail): the same queue MSWD
  works from, but view-only — no status/document/interview/eligibility
  actions — reflecting that admin's role is oversight, not casework.
  The list shows 10 applications per page, retains search and status/type
  filters, and scrolls within a bounded list area.
- **Manage Users** (`admin/users.php`, permission `users.manage`, admins
  only; `services/UserManagementService.php`): every account of the three
  roles — Administrator, MSWD Staff, Solo Parent — filtered by role, status
  (active, deactivated, locked out, temporary password) and name /
  username / email / mobile, 10 per page, with last sign-in; plus a create
  form (validated name, email, unique username and mobile, password
  policy, optional "require a new password at first sign-in"). Each
  account's **manage page** (`admin/user_edit.php`) edits name / email /
  mobile (the username is fixed), assigns the role, activates or
  deactivates (takes effect on the user's next request), **Reset Access**
  (a 12-character temporary password shown once to the admin — never
  stored in plain text or logged — which also clears any lockout and makes
  the user choose a new password before they can use anything else),
  **Unlock** (clears a lockout only), and shows the account's **activity**
  from the audit log: what it did and what was done to it, 20 per page.
  An admin can't deactivate, re-role or reset their own account, so an
  active administrator always remains; an account with applications on
  file stays a Solo Parent account. Mobile numbers match in both 09… and
  +639… forms. All rules are enforced in the service, not just the form.
  The compact account table has a fixed header, a visible record range,
  explicit Edit and Delete actions, and stacked rows on small screens.
  Contact details are available on the account's manage page. Buttons have
  action icons, consistent sizing and keyboard focus indicators; destructive
  actions retain their confirmation prompts.
- **My Account** (`staff/account.php`): staff and administrators see their
  account details and change their own password (applicants do the same
  on My Profile).
- **Analytics** (`admin/analytics.php`, permission `analytics.view`;
  `services/AnalyticsService.php`, `models/Analytics.php`). Every figure is
  counted live from the database for a chosen period (last 12 months, this
  year, last year, all time or a custom range):
  - Summary cards: registered applicants (and how many have applied), new
    applications and renewals submitted, approvals and rejections made in
    the period (from the status history), applications pending now, IDs
    expiring within the renewal reminder period and IDs already expired
    without a renewal, appointments scheduled.
  - Charts: applications by month (new vs renewal, every month shown, up
    to 36), by barangay (spellings merged ignoring case/spaces; top 12 plus
    "Other"), by current status, by category, sex and civil status,
    appointments by outcome, and seats booked in open upcoming slots.
  - Charts are plain HTML/CSS (`views/partials/charts.php`) with a hover /
    keyboard tooltip on every mark (`public/js/charts.js`) and a "Show as
    table" view; colours are a validated colour-blind-safe pair. The page
    prints cleanly; detailed lists and CSV export are on Reports.
- **SMS Notification Management** (`admin/sms.php`): sent/failed/logged-only
  counts and a searchable/filterable log of every message, 10 per page.
  A compact table shows the recipient, reference, timestamp and status;
  expand a message to see its text, provider details and errors. Test-mode
  messages are labelled as not sent. Retry remains available only for failed
  messages below the attempt limit, excluding password-reset messages.
  Template editors collapse individually, with the editor reopened and
  submitted text preserved after a validation error. They edit the
  `sms_templates` each notification is rendered from
  (`{ref}`/`{status}`/`{remarks}`/`{when}`/`{location}` placeholders) —
  `SmsService` falls back to its built-in text if a template is emptied out.
- **System Settings** (`admin/settings.php`, administrators only —
  permission `settings.manage`; `services/SettingsService.php`). Every
  setting is defined once in `config/settings.php` (type, default, allowed
  range), stored in `system_settings`, and read anywhere with
  `setting('key')` (one query per request; an unsaved key uses its
  default). Each section is saved on its own, validated strictly (nothing
  in the section is written if any value is wrong), and audit-logged with
  every old → new value:
  - **Basic System Information** — system name (page titles), office
    name, address, hours, email and phone (footer, portal page, Chat
    Support, Help Assistant).
  - **Document Limits** — maximum file size (capped at the server's PHP
    upload limit) and allowed types (JPG, PNG, PDF), enforced on every
    upload path and shown on the upload forms.
  - **Appointments** — default location and slot capacity, the days and
    hours MSWD staff may open slots, how far ahead, the minimum notice to
    book/reschedule/cancel online, and the reminder window.
  - **ID Validity & Renewal** — fixed one-year validity from confirmed
    release for new and renewed IDs, and the renewal reminder period (which
    is also when renewal opens). Existing IDs keep their recorded expiry.
  - **Notification Settings** — SMS on/off, each SMS message on/off
    (`sms_templates.is_enabled`; the password-reset code is always sent),
    and in-app alerts to MSWD staff about new work. Applicants' in-app
    notifications and administrators' security/job alerts are always on.
  - Links to **Application Requirements** (`admin/requirements.php`) and
    the SMS wording (`admin/sms.php`), the **Send Reminders Now** triggers
    with run history, and a read-only server summary.
- **Database & System Monitoring** (`admin/monitoring.php`): per-table row
  counts, storage usage, recent activity, and a real **one-click database
  backup** — a pure-PHP export (no `shell_exec`) written to
  `storage/db_backups/`, downloadable or deletable from the same page.
- **CSV Export** (`admin/export_csv.php`, administrators only —
  `data.export`): the full applications or users list, unfiltered (see
  **Reporting** below for filtered exports).
- **Reporting** (`admin/reports.php` for administrators, `staff/reports.php`
  for MSWD staff — permissions `reports.view` / `reports.export`;
  `controllers/ReportController.php`, `models/Report.php`). Eight reports:
  Applicant, Application, Approval, Rejection, Renewal, Barangay
  Distribution, Appointment and Expiring ID. Each has a date range on the
  date that matters for it (registered, submitted, decided, scheduled,
  expires), a search box, report-specific filters (status, type, account,
  outcome, renewal filed), 50 rows per page with an accurate total, and:
  - **Print** — a clean page with the report name, filters, row count, the
    date/time and who generated it, and a data-privacy notice; every
    matching row, not just the screen page.
  - **CSV export** — every matching row (up to 10,000; narrow the filters
    beyond that), UTF-8 for Excel, with spreadsheet formulas neutralised
    (a cell starting with `=`, `+`, `-` or `@` gets a leading apostrophe).
  - Every print and export is audit-logged with the report, row count and
    filters. Approval/Rejection use the decision date from the status
    history; Barangay Distribution merges spellings and totals each person
    once; the Expiring ID report opens on the renewal reminder window and
    shows days left and whether a renewal was filed.
- **Manage FAQ** (`admin/faq.php`): create/edit/hide/delete the questions
  and answers shown on the applicant's Chat Support page. Entries use
  expandable editors inside a bounded list; hidden entries remain labelled.
- **Audit Log** (`admin/audit_log.php`, administrators only — `audit.view`;
  `services/AuditLogService.php`, `models/AuditLog.php`). Every entry
  records the **user**, their **role**, the **action**, the **target
  record** (type + id), the **timestamp** and **details**, plus IP address
  and browser. What is logged:
  - Sign-in & security: login, logout, registration, **failed sign-ins**
    (wrong password with the attempt number, unknown username — logged
    against no account with the username as typed, never the password —
    deactivated or locked account), **account locked**, password reset
    requested / failed (wrong code) / completed, password changed, access
    denied.
  - Applications: **application created** (the applicant's first draft
    save, new or renewal), draft saved/discarded, submission, renewal
    submission, eligibility assessment, case notes.
  - Documents: upload (one entry per file), resubmission, verification,
    staff viewing a document.
  - Decisions: correction request, approval, rejection, other status
    changes.
  - Appointments: booked, rescheduled, cancelled, missed; interview
    scheduling and results; slot created/reopened/cancelled/deleted.
  - Users: created, edited, role change, activated/deactivated, unlocked,
    access reset; profile and photo changes.
  - Settings: system settings (old → new values), SMS templates, document
    requirements, FAQs, announcements.
  - System: report/CSV/audit-log exports, backups, SMS retries/tests,
    scheduled job runs, QR verification.

  The viewer shows 10 events per page with an accurate total, newest first;
  category tabs, action, role and date filters, a search over user, action
  and details, and a per-account view (click a user's name). Targets show
  as readable names linking to the record (e.g. the application reference
  number, the user's account). **Download CSV** exports every matching
  event (up to 10,000, formula-safe) and is itself audit-logged. The
  dashboard's Activity & Security tiles link into it (access denied, failed
  sign-ins, password resets).

  **Append-only**: the database refuses `UPDATE` and `DELETE` on
  `audit_logs` (triggers from `database/migrate_step26_audit.sql`), so no
  code path, bug or injected query can alter history. Deleting a user still
  works — `user_id` becomes NULL while the snapshotted name and role keep the
  entry readable. Backups include the triggers. A database administrator
  doing deliberate maintenance must opt in for their session with
  `SET @audit_log_maintenance = 1;`.
- A dedicated **Administrator Dashboard**, distinct from MSWD's: registered
  solo parents, total/renewal applications, active users, IDs expiring
  soon, a system-health indicator, SMS status, and quick links into every
  page above.

**Status model**

`applications.status`: `Pending Review` → `Under Verification` →
(`For Revision` | `Interview Scheduled`) → `Approved` → `ID Released`, or
`Rejected` at any review point. Renewals use the same status set.

## Database

`database/schema.sql` is the authoritative structure and
`database/seed.sql` the reference data (**do not** re-run `schema.sql`
against a database with real data — it drops and recreates everything).

The 17 core tables: `roles`, `users`, `applicants`, `applications`,
`document_requirements`, `application_documents`, `application_document_checklists`,
`eligibility_assessments`, `appointments`, `application_status_history`,
`application_decisions`, `qr_application_slips`, `notifications`,
`sms_logs`, `chat_messages`, `audit_logs`, `system_settings`. Supporting
tables: `categories`, `application_children`, `appointment_slots`,
`renewal_reminders`, `sms_templates`, `faqs`.

The app's database connection runs in strict SQL mode, so an invalid
value (e.g. an unknown status) is rejected instead of silently stored
as an empty string.

If you already have a live database from before the applicant/admin/MSWD
dashboard features were added, run the additive migrations instead of
re-importing `schema.sql`. These migrations preserve existing application
records; a few add or update reference data. Run them in order, once each:
```
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_applicant_portal.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_admin_staff_dashboards.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_renewal_reminders.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_notifications.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_login_lockout.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_mvc_auth.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step2_schema.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step8_drafts.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step9_documents.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step10_verification.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step11_appointments.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step12_processing.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step14_renewal.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step15_reminders.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step16_qr_slips.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step18_notifications.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step19_sms.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step20_chat.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step22_users.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step23_settings.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step26_audit.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step27_security.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step30_mobile.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step31_sms_events.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_step33_faqs.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_document_checklists.sql
"C:\xampp\mysql\bin\mysql.exe" -u root virac_solo_parent < database\migrate_category_checklists.sql
```
**Never** run `schema.sql` itself against a database with real data —
it hardcodes `DROP DATABASE IF EXISTS virac_solo_parent`, so doing so
destroys every account and application in it. Only ever run the
`migrate_*.sql` files against a live install.

## Storage

- `storage/uploads/` — applicant-submitted documents, one folder per
  application ID, `.htaccess`-denied (served only via `download.php`).
- `storage/avatars/` — applicant profile photos, one file per user,
  `.htaccess`-denied (served only via `avatar.php`, same owner-or-staff
  access check as `download.php`).
- `storage/staff/` — reserved for future staff-related files (e.g.
  internal documents); not yet written to by any code. Inherits the same
  `.htaccess` protection as `storage/uploads/`.
- `storage/db_backups/` — database backups created from **Database &
  System Monitoring** (`admin/monitoring.php`), `.htaccess`-denied (served
  only via `admin/download_backup.php`).

## Security notes

Security review (Step 27) — what protects each area, as verified live by
`s27security` (role × page matrix, wrong-role and cross-account writes,
CSRF, sessions, passwords, rate limits, headers, an XSS crawl, SQL
injection and malformed input, uploads, QR, secrets):

- **Authentication.** bcrypt (`password_hash`), re-hashed when PHP's
  default changes; an unknown username costs the same time as a wrong
  password (no username probing). 5 failed attempts lock an account for
  15 minutes; on top of that, 50 failed sign-ins from one IP within 15
  minutes (password spraying across accounts) stop all sign-in attempts
  from it for 15 minutes, logged once as "Sign-in Blocked".
- **Passwords.** 8–72 characters with a letter and a digit (bcrypt only
  reads 72 bytes, so longer is refused rather than silently truncated).
  Changing, resetting, or an administrator replacing a password signs the
  account out on every other device (`users.auth_version`); the session
  that made the change carries on under a new session ID.
- **Password reset.** 6-digit SMS code, valid 15 minutes, single use,
  stored only as a bcrypt hash (never readable from the database or a
  backup) and masked in the SMS log. Wrong codes count toward the account
  lockout. At most 3 codes per account per 15 minutes (silently — the
  response never reveals whether an account exists) and 10 requests per
  IP per hour, so it can't be used to flood someone's phone with texts.
- **Mobile-linked accounts (Step 30).** Registration asks for a mobile
  number and texts a 6-digit code to it. Until the code is entered at
  `verify-mobile.php`, the account can only reach My Profile (to correct
  a mistyped number) and the public pages. The dashboard, Apply Online
  and My Applications all send it to the confirmation page. The code
  lasts 10 minutes and is stored only as a bcrypt hash, masked in the SMS
  log. Five wrong entries void it, and an account can be sent at most 3
  codes per 15 minutes. Changing the number — by the applicant on My
  Profile or by an administrator — makes it unconfirmed again, and the
  applicant's own change sends a code to the new number. A confirmed
  number can be used to sign in instead of the username, in `09…` or
  `+63…` form; an unconfirmed one can't. Usernames must contain a letter,
  so a username can never look like someone else's mobile number. If a
  text never arrives, an administrator can mark the number confirmed on
  the account page ("Confirm Mobile") once MSWDO has checked it some
  other way. This is audited. Accounts that existed before Step 30 were
  marked confirmed by the migration, since their numbers had already
  been receiving SMS.
- **Authorization.** Every page names its permission
  (`config/permissions.php`, `Controller::authorize()`); denials are
  audit-logged. Administrators monitor applications read-only — they can't
  change statuses, verify documents, or replace a QR slip. Applicants only
  ever reach their own records: documents, photo, slip, uploads,
  notifications and chat are all owner-checked, and "not yours" looks the
  same as "doesn't exist".
- **Public status lookup.** The "Check Status" tab uses only the reference
  number and allows 10 failed lookups per IP per 15 minutes. It shows the
  masked name, application type, dates, status history, next steps, recorded
  interview or ID release appointment, ID validity and office contacts.
  Document instructions, decision details and private notes remain in the
  signed-in applicant portal. Run `php database/test_public_tracking.php`
  to check guidance, schedules, validity and public field filtering.
- **Registration.** At most 10 new accounts per IP per hour.
- **Rate limits** live in `rate_limit_hits` (`services/RateLimiter.php`),
  keyed by hashes, pruned after a day.
- **CSRF.** Every POST requires the per-session token (a missing, foreign or
  malformed token gets a 400 and changes nothing); nothing changes state
  on GET.
- **Sessions.** HttpOnly, SameSite=Lax cookie (Secure when served over
  HTTPS); strict mode, so a planted session ID is never adopted; new ID at
  every sign-in, password change and sign-out; 30-minute idle and 12-hour
  absolute timeouts; the account is re-checked against the database on
  every request, so a deactivation or role change applies immediately.
- **SQL injection.** Every value is a bound parameter; the only text put
  into SQL is from code-side whitelists (sort orders, report columns,
  filters) or integer-cast. Prepared statements are real (not emulated),
  so stacked queries are impossible.
- **XSS.** Every value in every template goes through `e()`; the crawl
  seeds script payloads in names, addresses, remarks, file names and chat
  and finds none rendered raw.
- **File uploads.** JPG/PNG/PDF only, checked against the file's real
  content (not its name or the browser's type), stored under random names
  in `storage/uploads/` which Apache refuses to serve; files are only
  delivered by `download.php` after the owner/staff check, with `nosniff`
  and `no-store`.
- **Headers.** `X-Content-Type-Options`, `X-Frame-Options`,
  `Referrer-Policy`, a Content-Security-Policy that forbids framing by
  other sites, plugins, `<base>` hijacking and off-site form posts, and a
  Permissions-Policy (camera only for this site's QR scanner). PHP's
  version header is removed. Bootstrap from the CDN is pinned with
  Subresource Integrity, so a tampered CDN file is refused.
- **Sensitive data & secrets.** Apache refuses `config/`, `database/`,
  `storage/`, `vendor/`, source folders, `.git`, `.env`, SQL/MD/JSON files
  and directory listings. Errors show a plain page (details go to
  `storage/logs/`) unless `APP_DEBUG` is on. `SMS_API_KEY` comes from the
  environment or the untracked `config/local.php`, is never shown, and is
  scrubbed from gateway error text. Exports never include password hashes.
- **QR slips.** The QR carries a random 64-hex token (not the reference
  number); only signed-in MSWD staff can verify one; a changed character
  is "unknown", a replaced slip's old code stops working, and repeated
  unknown codes are throttled.
- **Audit trail** — see the Audit Log section: append-only, enforced by
  the database.

For production: serve over HTTPS (the session cookie then becomes
Secure automatically), set `ServerTokens Prod` / `ServerSignature Off` in
Apache so it doesn't advertise its version, and keep `APP_DEBUG` off.

## UI review notes

### Recent updates and verification (October 13, 2026)

Admin sidebar pages share `public/css/admin-management.css`, loaded only
for the administrator portal. Buttons use consistent 44 px minimum targets,
spacing and focus states. Wide tables scroll inside their own containers
with fixed headers, and long lists use bounded scroll areas. Shared staff
views receive these admin styles only when opened through the admin portal.

| Area | Current layout |
|---|---|
| User Management | Compact account table, 10 per page, stacked mobile rows |
| Applicant Records | 10 per page for admin; staff remains at 20 |
| SMS Management | 10 logs per page, expandable message details and template editors |
| Application Monitoring | 10 per page, filters retained, bounded list |
| Public Announcements | Editor beside the list on desktop, 10 announcements per page |
| Eligibility Logs | 10 checks per page with expandable details |
| Audit Logs | 10 events per page with searchable, scrollable results |
| System Settings and FAQ | Collapsible editors; settings links open their target section |
| Notifications, appointments and monitoring | Bounded lists for long histories, schedules, table counts and backups |
| Analytics, reports, dashboard and My Account | Shared button and table styling; Reports retains 50 rows per page |

`public/js/admin-management.js` opens collapsed settings sections when a
section link is followed, including an initial URL fragment or a repeated
click on the current section. Settings validation errors open the affected
section. Export and print workflows retain their existing scope; a shorter
on-screen page does not truncate a report or audit-log export.

Run the relevant regression checks from the project directory:

```powershell
php database/test_admin_management_layout.php
php database/test_staff_review_workflow.php
php database/test_public_announcements.php
php database/test_eligibility_history.php
```

- `test_admin_management_layout.php` uses connection-local temporary tables
  to check SMS and announcement pagination, search/status filters, page
  boundaries, SMS retry restrictions, test-mode labels and populated/empty
  SMS list rendering. It sends no SMS and leaves live records unchanged.
- `test_staff_review_workflow.php` uses a disposable database to check the
  review workflow, new staff access to shared records, preserved staff
  history, one-year expiry for new and renewed IDs, matching QR expiry and
  rejection of duplicate handover confirmation. The database account needs
  permission to create and drop the disposable database.
- The announcement and eligibility-history tests use transactions and roll
  back their fixtures.

These checks passed for the current update. All 17 admin sidebar pages were
also rendered through their controllers without PHP warnings, with shared
assets present and balanced form, details and table markup on the admin
controller pages. PHP syntax, JavaScript syntax and whitespace checks passed.
Visual browser verification of the latest layouts remains pending; it was
unavailable in the current session. Manual follow-up should cover desktop
and mobile widths, list scrolling, pagination, keyboard focus, expandable
editors, settings section links and validation errors.

### Earlier UI review (Step 28)

UI review (Step 28): every page, for each role, at phone (360 px), tablet
(768 px) and desktop (1280 px) widths with real device emulation, both with
no records (empty states) and with realistic records — 306 page views. All
pages: no horizontal page scrolling at any width (wide tables scroll inside
their own box), no JavaScript or console errors, one page heading (`h1`)
each, no unlabeled form fields or buttons, no images without alt text, no
duplicate ids, and every destructive action asks for confirmation.

What changed:
- **Loading state on every form** (`public/js/app.js`). Once a form is
  really submitting (it passed validation and any confirmation), its
  button reads "Please wait…" and repeat clicks are ignored — a double
  click can no longer send an application, upload, chat message or SMS
  twice. Upload pickers that send as soon as a file is chosen show
  "Uploading…". The button's own value still reaches the server, and the
  state resets if the person comes back with the Back button or the
  response was a download. Opt out per form with `data-no-loading`.
- **Help & Support** is a real page (it was a "coming soon" placeholder):
  the MSWDO's contact details and office hours from System Settings, links
  to Chat Support, applying or application tracking, and news,
  and a five-step "how it works". The applicant menu's **Settings** entry
  (also a placeholder) is gone — everything an applicant can change lives
  on My Profile, and old links go there.
- **Keyboard access to uploads.** The document and photo pickers hid their
  file input with `display:none`, so they could not be reached with the
  keyboard. The input is now visually hidden but focusable, and its
  "Choose file / Replace" button shows the focus ring.
- **Page headings.** Applicant sections, the portal tabs, the slip page and
  the staff applicant record now each have exactly one `h1` (the applicant
  section title is still shown in the mobile top bar; on desktop it stays
  available to screen readers). The applicant record shows the applicant's
  name as its title, like every other staff page.
- **Field names.** 37 fields that only had placeholder text (which
  disappears while typing and isn't reliably read out) now have proper
  accessible names — sign-in, registration, password reset, profile
  passwords, staff review forms, settings, FAQ, SMS and announcement forms.
- **Readable small text.** The faint grey used for hints, timestamps and IP
  addresses was 3.1:1 against white; it is now 4.9:1 (WCAG AA).
- **Tap targets on phones.** Section link bars (System Settings, applicant
  record), chat suggestion chips, "Mark as read" and the header title link
  are now at least 32 px tall. Standalone text links ("← Back to…",
  "Forgot password?") stay as they are — they have space around them.

These Step 28 results describe that review pass. Later admin layout changes
are described below; the earlier device-emulation results do not validate
those later changes.

## End-to-end workflow check

Workflow test (Step 29): one applicant, one MSWD staff member and one
administrator went through the whole process using only the real pages, with
no application data seeded:

1. The administrator creates the staff account, which must change its
   password at first sign-in.
2. Staff sign in, change the password and open an interview slot.
3. The applicant registers, signs in, runs the eligibility check, fills in
   the four-step application with six documents, and submits.
4. The application receives a reference number and a QR slip. The applicant
   gets an in-app notice and an SMS, and staff get an in-app notice.
5. The applicant books the slot. The application moves to "Interview
   Scheduled" and the applicant gets a notice and an SMS.
6. Staff review: they verify five documents, flag the sixth and ask for a
   correction. The applicant sees the reason and re-uploads, and staff are
   told about the new upload.
7. Staff verify the new upload, record the interview result, assess
   eligibility, approve the application and release the ID. Approval is
   refused until the checklist is met. The QR slip now expires with the
   ID and scans as valid.
8. The applicant tracks every stage on the dashboard, on the public status
   page, on My ID and in notifications.
9. The administrator sees the same record across the dashboard, monitoring,
   applications, users, analytics, reports, SMS log and audit log, and
   changes a setting and changes it back. Both changes are audited.
   Deactivating the applicant signs them out at once.

What the walk-through changed:
- **Interview results are recorded on or after the interview day.** Before,
  a result could be saved for an interview that hadn't happened yet. That
  marked the appointment completed and satisfied the approval checklist
  early. The staff page now says from which day the result can be recorded.
- **No "Note: none" in SMS.** A status-change text with no staff remarks
  now ends after the status ("…is now ID Released."). Before, it ended
  "Note: none". If an administrator rewords the template so that the
  remarks share a sentence with other details, only the placeholder is
  left empty.

## Final validation

Final validation (Step 30): each objective of the e-SoloParentID system is
listed below with where it lives and which test suite checks it. The
regression run covers every listed suite.

| Objective | Where | Checked by |
|---|---|---|
| Responsive UI | `public/css/`, every page at 360 / 768 / 1280 px | `s28ui`, `s28final` |
| Mobile-linked authentication | `verify-mobile.php`, `AuthService` (codes), sign-in by confirmed mobile, SMS reset codes | `s30mobile`, `s3auth`, `s27security` |
| Online application | `portal.php?tab=apply` (4 steps, saved drafts) | `s8apply`, `s29flow` |
| Digital document submission | Application step 3, Upload Documents, re-upload after correction | `s9docs`, `s10verify`, `s29flow` |
| Eligibility checker | `eligibility.php` (public, before login) | `s7elig` |
| Automated document validation | `DocumentValidationService` (type, real content, size, completeness per category) | `s9docs` |
| Online renewal | Dashboard ID Renewal (`RenewalService`) | `s14renew` |
| Automatic renewal reminder | `cron/renewal-reminders.php` (SMS + in-app, before and after expiry) | `s15remind` |
| Appointment scheduling | Slots (`staff/appointment_slots.php`), applicant booking, staff outcomes, reminders | `s11appt`, `s15remind` |
| Status tracking | Dashboard tracker, status timeline, public lookup (reference number only) | `s13tracker`, `s29flow` |
| MSWD dashboard | `staff/dashboard.php`, application review | `s5dash`, `s12process` |
| QR slip | `slip.php`, `staff/qr_verify.php` | `s16slip`, `s17verify` |
| Analytics | `admin/analytics.php` | `s24analytics` |
| Reporting | `admin/reports.php`, `staff/reports.php` (CSV/print) | `s25reports` |
| Chat support | Help Assistant + hand-off to MSWDO staff | `s20chat`, `s20vis` |
| Record management | Applicant records, application history, notes | `s21records` |
| User management | `admin/users.php`, `admin/user_edit.php` | `s22users`, `s30mobile` |
| SMS API integration | `SMSService` (Semaphore; test mode logs only) | `s19sms` |
| Automated SMS | Submission, status changes, interviews, cancellations, reminders, codes | `s19sms`, `s29flow` |

## Not wired up (needs real credentials/infra, or is a deliberate stub)

- Actual SMS delivery (Semaphore PH/Vonage) — see above; every
  notification is logged regardless.
- The Help Assistant is rule-based (keywords in English and common
  Filipino); it doesn't use an external AI service, so unusual phrasings
  fall back to the FAQ or to "Ask MSWDO staff".

## Eligibility checker history and result views

The public `eligibility.php` checker presents three questions with Back/Next
navigation (the full form also works without JavaScript). ?I'm not sure?
produces a guidance result, never an official approval or rejection.
Results show the selected category's configured requirements and office
contact details. Signed-in applicants can open their own latest 100 checks
through **My eligibility history**.

Each completed check creates a new `eligibility_assessments` row. Successful
GET requests for a result append `eligibility_result_views` events; refreshes
increase views but do not create new checks. Results are accessible only to
the owning applicant or the anonymous session that completed them. Guest
checks and their views are associated with the applicant on subsequent
login/registration. Unclaimed guest checks and views are removed after 30
days during the next check, consistent with the existing retention policy.
No IP address or browser fingerprint is collected for this feature.

**Admin ? Eligibility Logs** (`admin/eligibility_logs.php`) shows checks,
answers, results, first/last viewed, total views and distinct account/session
viewers, with date, result, situation and guest/registered filters. Staff
and applicants cannot open this admin page. Historical results retained
from before this upgrade have no view events; earlier overwritten results
cannot be recovered from the old database.

For another existing installation, back up first and import
`database/migrate_eligibility_history.sql` into its configured database, then
run `php database/sync_schema.php --check`. Fresh installs include the new
structure in `database/schema.sql`.

The pre-feature code/database backup and rollback instructions for this
installation are in `storage/feature_backups/eligibility_20261011_223412/`.

Regression checks: `php database/test_eligibility_history.php` exercises
append-only check history, view counts, guest association, ownership and
admin filtering inside a rolled-back transaction. Local HTTP and browser
checks also verified cross-role access, refresh/HEAD behavior, CSRF,
Next/Back answer retention, the mobile layout, and submission without JS.
The rollback helper and database snapshot were both tested in isolated
locations; the live system was not rolled back.


### Public home navigation and announcements

The home screen provides eligibility, application and tracking quick actions, guide section links, category-configured document requirements and Contact & Help. Public news is available through News & Announcements in the navigation menu. Requirements stay checkbox-based; staff verification remains separate. Office contact details and hours come from System Settings. Active FAQ entries are also shown publicly on Contact & Help; keep FAQ answers suitable for public visitors.

Applicant sign-in accepts only named `next=apply`, `next=applications` or `next=track` destinations. The choice survives registration and mobile verification; arbitrary URLs and Staff/Admin destinations are rejected.

Admins can open **Public Announcements** to create or edit plain-text news. Save as Draft to hide an item. Published items appear only after their publication date and before an optional expiry date; higher priority appears first. These public announcements are separate from account notification broadcasts. The existing five public announcements were retained during this workspace upgrade; review their dates/content and withdraw outdated items using Draft.

For other existing installations, import `database/migrate_public_announcements.sql` before deploying the changed PHP files, or run `php database/sync_schema.php --apply`; both add the new table. The SQL migration also corrects the original upload-based correction FAQ when its answer has not been customized. Fresh installations use `database/schema.sql`. Public news starts empty on other installations until Admin publishes entries.

Checks: `php database/test_public_announcements.php` (transactional), `php database/sync_schema.php --check`. Manually verify Home quick actions as guest and applicant, switch Log In/Create Account after choosing Apply, confirm the return to the application, check tracker labels/help, and publish/draft/expire a test announcement in Admin. Confirm Staff and Applicant cannot open the Admin editor.
