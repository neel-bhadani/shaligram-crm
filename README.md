# Shaligram CRM

A lead management CRM for a real estate client in Surat. Leads arrive from 8 sources, including Facebook Lead Ads. Telecallers and salespeople work them through 9 stages, and every lead is tracked until it ends as a booking or a loss.

This file is for the next developer. It describes what the code does today, including the parts that are unfinished or inconsistent.

---

## 1. Stack

Versions below are the installed ones (`composer show --direct`, `node_modules`).

| Layer | Package | Installed |
|---|---|---|
| PHP | `php` constraint `^8.3` | runs on 8.4 |
| Framework | `laravel/framework` | 13.31 |
| SPA bridge | `inertiajs/inertia-laravel` / `@inertiajs/vue3` | 2.0 / 2.3 |
| Routes in JS | `tightenco/ziggy` | 2.6 |
| UI | `vue` | 3.5 |
| CSS | `tailwindcss` + `@tailwindcss/vite` | 4.3 |
| Bundler | `vite` | 8.2 |
| Charts | `chart.js` | 4.5 |
| Exports | `phpoffice/phpspreadsheet`, `dompdf/dompdf` | 5.10, 3.1 |
| Tests | `phpunit/phpunit` | 12.5 |
| Database | MySQL | — |

**Tailwind runs through the Vite plugin, not PostCSS.** `vite.config.js` registers `tailwindcss()` from `@tailwindcss/vite`. `resources/css/app.css` starts with `@import 'tailwindcss';`. There is no `postcss.config.js` and no `tailwind.config.js`. `postcss` and `autoprefixer` are still in `package.json`, but nothing uses them. Do not add a PostCSS config for Tailwind. This setup has broken builds before.

---

## 2. Running it locally

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` before you migrate:

- `.env.example` says `DB_CONNECTION=sqlite`. Change it. The app runs on MySQL, and the dev machine has no SQLite driver. Set `DB_CONNECTION=mysql`, `DB_DATABASE=lead_crm` and your credentials.
- Keep `QUEUE_CONNECTION=database`, `CACHE_STORE=database` and `SESSION_DRIVER=database`.
- Keep `APP_TIMEZONE=Asia/Kolkata`. See below.

```bash
php artisan migrate
php artisan db:seed          # one admin: admin1@gmail.com / 123456789 (AdminSeeder)
npm install
composer run dev             # php artisan dev
```

`composer run dev` runs `php artisan dev`. That starts `serve`, `queue:listen`, `pail` and `npm run dev` together. **It does not start the scheduler.** If you are testing automation or alerts, run `php artisan schedule:work` in another terminal.

Lead stages and lead sources are inserted by a migration (`2026_09_10_000000_create_lead_stages_and_lead_sources_tables.php`), not by a seeder. After you migrate, create at least one project on `/projects` before you add a lead.

### Timezone

Every follow-up datetime is typed, stored and compared in IST. The app does not convert to UTC anywhere. `TodoRequest` validates `scheduled_at` with `after:now`. "Today" on the dashboard, the To-do tabs and the reports is `today()`.

As the code stands:

- `config/app.php` hard-codes `'timezone' => 'Asia/Kolkata'`. It does **not** read `env('APP_TIMEZONE')`.
- `.env` and `.env.example` set `APP_TIMEZONE=Asia/Kolkata`, but nothing reads that line today.

Keep both. The config file is the value that takes effect. The `.env` line protects against the obvious "fix" of changing the config back to `env('APP_TIMEZONE', 'UTC')`. If only one of them says Asia/Kolkata and the config falls back to UTC:

- Follow-ups typed in IST are compared against a UTC clock. `after:now` rejects valid times, or accepts times that have already passed in IST.
- "Today", "Last 7 days" and the Overdue / Due today tabs shift by 5½ hours. Work booked between midnight and 05:30 lands on the wrong day.
- `import:legacy`, `import:lead-18-sep` and `import:lead-vanam-22-sep` refuse to run. They check `config('app.timezone') === 'Asia/Kolkata'`.

---

## 3. The core invariant

```php
Lead::open()->doesntHave('pendingTodo')->count()  // must be 0
```

**Every open lead has exactly one pending follow-up.** A lead is open when its stage is not terminal (`booking_done`, `lost`). A follow-up is a row in `todos` with `status = 'pending'`.

Everything the team does every day depends on this:

- The To-do page tabs (Overdue / Today / Upcoming) list pending todos.
- The dashboard follow-up panels read pending todos.
- The calendar reads pending todos.

An open lead with no pending todo appears on none of these. Nobody will ever call it again, and nothing warns you.

**Current state:** production reads **16**. The local copy of the data also reads 16 (checked 2026-09-29, out of 892 open leads). All 16 come from the legacy import. `php artisan import:legacy --awaiting` lists them. **If the number rises above 16, something new is broken.**

To audit, run `php artisan crm:check-consistency`. It is read-only and safe on production. It reports this count and several related checks: terminal leads with a pending todo, orphaned todos, and stage/history mismatches.

What protects the invariant:

- `LeadFollowUpService` writes every pending todo during normal operation. See §5.
- `TodoRequest::withValidator()` refuses a second pending todo for the same lead on `POST /todos`.

Known ways it can still break:

- `DELETE /todos/{todo}` (`TodoController::destroy`, admin only) cancels a pending todo and does not create a replacement. If an admin cancels the only follow-up on an open lead, the count goes up by one.
- The one-off data commands `followups:create-today`, `import:lead-18-sep` and `import:lead-vanam-22-sep` write pending todos directly, not through the service.

---

## 4. The domain in plain words

### Roles

There are three roles, stored in `users.role`.

| Role | What it does |
|---|---|
| `admin` | Everything. Only admins can manage users, projects, the pipeline (stages and sources), channel partners, automation and integrations. These routes sit in `role:admin` groups in `routes/web.php`. An admin cannot be created from inside the app. Use `AdminSeeder` or the database. |
| `telecaller` | Works the calling stages. Sees only leads assigned to them. By default a telecaller cannot add leads, but `LeadPolicy::update()` lets them edit leads they can see. |
| `salesperson` | Works from the site visit onward. Sees only leads that are assigned to them **and** belong to a project they are linked to through `project_user`. Can add and edit leads. |

On top of the role, each user has five permission toggles in `users.permissions` (JSON). The defaults per role are in `config/crm.php` → `permission_defaults`. `User::can_()` reads the JSON value first and falls back to the role default. For how the two interact, see §7.

New staff can sign up. They cannot log in until an admin approves them (`users.approval_status`).

### Stages

The 9 stages are seeded from `config/crm.php` → `stages`. They live in `lead_stages`, and an admin edits them on the Pipeline screen. The owner role of each stage is `lead_stages.owner_role`. It was seeded from `crm.stage_owner_roles`.

| Stage | Owner |
|---|---|
| `fresh` | telecaller |
| `connected` | telecaller |
| `not_connected` | telecaller |
| `details_shared` | telecaller (moved from salesperson by `2026_09_28_085311_move_details_shared_to_telecaller_desk`) |
| `site_visit_scheduled` | salesperson (**this is the handover point**, `crm.handover_stage`) |
| `site_visit_done` | salesperson |
| `in_discussion` | salesperson |
| `booking_done` | terminal (no new owner; the lead stays with its holder) |
| `lost` | terminal |

A lead's owner comes from the stage it is at. It never depends on who typed it in. `LeadAssignmentService` turns the role into a person:

- **Telecallers** form one company-wide pool.
- **Salespeople** take turns per project, round robin, among the active salespeople linked to that project. Each project's position in the rotation is `projects.last_assigned_salesperson_id`. It is read under a row lock.
- If the project has no active salesperson, the lead goes to any active salesperson, and every admin gets an alert.
- If there is no active salesperson anywhere, the lead stays with its holder.

### The handover is one way

A lead moves from a telecaller to a salesperson when a stage change crosses from a telecaller stage to a salesperson stage, and the current holder is not already a salesperson. See `LeadFollowUpService::handsOver()`.

**After a salesperson owns a lead, no stage change moves it.** This includes going back to `not_connected`, `connected` or `fresh`, and moving to a terminal stage. Only a manual reassignment (`PUT /leads/{lead}/reassign`) takes it off them.

Why: a salesperson is usually in the middle of a conversation with the customer. A backwards step, for example "didn't pick up today" logged as `not_connected`, used to send the lead back to a telecaller. The lead disappeared from the salesperson's list while they were still talking to the customer.

A related rule: the handover requires the *from* stage to be a telecaller stage. So when an admin manually puts a salesperson-stage lead on a telecaller, the next salesperson-stage change does not undo that.

### The to-do cycle

1. A lead is created with its first follow-up. The user picks the date, time and type on the form. Nothing schedules follow-ups automatically. There is no retry ladder and no working-hours clamp.
2. The assignee opens the follow-up and logs the call (`POST /todos/{todo}/complete`). They choose the outcome stage, write remarks, and book the next follow-up.
3. `LeadFollowUpService::complete()` does all of this in **one transaction**, with the lead row locked:
   - marks the todo `completed` and sets `outcome_stage` and `completed_at`
   - moves `leads.stage`
   - hands the lead over if the move crosses a desk
   - cancels any other pending todo and creates the next one
4. If the outcome is terminal, no next follow-up is created and the lead leaves every to-do list.

If any step fails, all of it rolls back. A lead can never end up with its stage moved but no next task.

---

## 5. Services and what each owns

| Class | Responsible for | Do not do this outside it |
|---|---|---|
| `app/Services/LeadFollowUpService.php` | Every stage change, every pending todo, every change of `leads.assigned_to` after creation, the handover, project switches. Writes the activity timeline in the same transaction. Fires automation triggers **after** commit. | Do not write `leads.stage`, `leads.assigned_to` or a pending `todos` row directly. |
| `app/Services/LeadAssignmentService.php` | Decides who a lead belongs to, given a stage, a holder and a project. Owns the per-project salesperson round robin and the "project has no salesperson" alert. | Do not pick an owner yourself. Creation, handover, import and project switch must all get the same answer. |
| `app/Services/LeadCreationService.php` | The shared create path for the Leads form and the bulk import wizard: owner, then `Lead::create`, then `onLeadCreated`, in one transaction. | — |
| `app/Services/IncomingLeadService.php` | Leads that arrive from a machine (the Meta webhook). Checks idempotency on `external_id`, refuses repeat enquiries, creates the lead as `fresh` with a follow-up due now. | Do not create webhook leads any other way. |
| `app/Services/LeadFormRouter.php` | Maps a Meta `form_id` to a project and holder through `lead_form_routes`. Falls back to the integration's default project. Records unmapped forms and alerts admins once per form. | — |
| `app/Services/WhatsApp/WhatsAppSender.php` | Queues WhatsApp messages (`message_logs`) and builds `wa.me` click-to-send links. Has an API `send()` path that is switched off. See §8. | Automation may only `queue()`. It must never send. |
| `app/Services/Automation/RuleEngine.php` (+ `ConditionMatcher`, `ActionRunner`, `LoopGuard`, `RuleCatalog`) | The only place rules are evaluated. Event triggers come from `LeadFollowUpService` after commit. Time triggers come from the hourly `automation:run`. Loop guard and per-rule cooldown apply. Every firing is logged in `automation_logs`. | Do not evaluate rules or dispatch actions from controllers. Actions that move leads call back into `LeadFollowUpService`, marked as the system (`created_by = null`). |
| `app/Exports/DataExporter.php` | The whitelist of export types, columns and formats (CSV, Excel, PDF). Leads go through `visibleTo`, follow-ups through `forUser`. CSV and Excel stream without a row cap. PDF is split into files of `crm.exports.pdf_row_limit` (500) rows because dompdf renders in memory. | Do not build export queries from request input. |
| `app/Services/AlertService.php` | In-app alerts (the bell). Deduplicates the same type, lead and user within `crm.alerts.dedupe_hours` (24). In-app only; there is no mail channel. | — |
| `app/Services/UserHandoverService.php` | Moves a departing user's open leads and pending todos to someone else. | — |

---

## 6. Dashboard numbers: flow and stock

Every number on the dashboard and on `/reports/*` is either a **flow** or a **stock**. Mixing the two is the most common way to get a wrong number.

**Flow: events that happened in a date range.**

- Read from `todos.outcome_stage` and `todos.completed_at`. **Never from `leads.stage`.**
- `leads.stage` only says where a lead is now. A lead that had a site visit on Monday and booked on Thursday has `stage = booking_done`, but it must still count as a site visit this week. The completed todo with `outcome_stage = site_visit_done` records that visit.
- Always count `distinct lead_id`. One lead can reach the same stage twice in a range.
- Stage changes made without a call still create a history row. This covers changes from the lead form and leads created at a stage other than `fresh`. `recordStageChange()` writes a completed todo for them. Without that row, the change would not exist for the dashboard.
- Example: `DashboardController::stageEvents()`. The Site visits, Bookings and Lost cards and the funnel all read this one query.

**Stock: where the pipeline stands now.**

- Read from `leads.stage`. Never filtered by when the stage changed.
- Example: `DashboardController::stagesByLead()`. "Where all enquiries stand" has no date filter. "Enquiries in this period" narrows the population by `leads.created_at` (intake). It still reports current stage, so it is a cohort, not a flow.

**Intake** ("new leads") is `leads.created_at` in range. **Conversion** is a cohort: of the leads created in the range, how many have a completed `booking_done` todo at any time. It cannot exceed 100%.

### Range boundaries

All boundaries are in IST and inclusive at both ends. The code is `DashboardController::preset()` and `ResolvesDateRange::dateWindow()`.

| Range | From | To |
|---|---|---|
| Today | `today()->startOfDay()` (00:00:00) | `today()->endOfDay()` (23:59:59.999999) |
| Last 7 days | `today()->subDays(6)->startOfDay()` | `today()->endOfDay()` |
| Last 30 days | `today()->subDays(29)->startOfDay()` | `today()->endOfDay()` |
| Custom | `from` 00:00:00 | `to` 23:59:59.999999 |

"Last 7 days" counts today as one of the seven. The dashboard defaults to 30. A custom range must not run backwards or end in the future. On the dashboard it also cannot span more than 731 days. The Leads and To-do pages also offer "All time", which means no filter.

`php artisan crm:verify-dashboard` recomputes the dashboard figures independently for a user and range, so you can check them.

---

## 7. Visibility

**Leads: `Lead::scopeVisibleTo($user)`** (`app/Models/Lead.php`)

- A user with `can_('see_all_leads')` sees every lead. Admins have this by default.
- Everyone else sees only leads where `assigned_to` is their user ID.
- A salesperson additionally sees only leads whose project they are linked to through `project_user`.
- Every lead query that runs for a signed-in user must call this scope. `LeadPolicy::view()` applies the same rule to a single lead.

**To-dos: `Todo::scopeForUser($user)`** (`app/Models/Todo.php`)

- `role === 'admin'` sees every todo.
- Everyone else sees todos where `assigned_to` is their user ID.
- Pair it with `hasLead()`, which drops todos whose lead is soft-deleted.

**Permission vs role.** Lead visibility and lead actions (add, edit, delete, export) go through permissions (`can_()`). Todo visibility and several todo actions still go through the **role**. Known places where they disagree:

1. **A non-admin with `see_all_leads`** (for example a sales manager) sees every lead but only their own todos. This affects the To-do page, the calendar, the dashboard follow-up panels, the follow-ups report and follow-up exports. `ReportController::dimensions()` notes this on purpose: the follow-ups "Assigned to" grouping uses `isAdmin()`. `TodoController::store()`/`update()` allow only an admin or the assignee, and `destroy()` allows only an admin.
2. **A salesperson holding a lead outside their projects.** This can only happen through the "no salesperson staffed" fallback. `visibleTo` hides the lead, but `forUser()->hasLead()` does not check lead visibility, so the todo still appears on their To-do page.
3. **`export_data`.** Its hint in `config/crm.php` says "Reserved — nothing reads this yet". That is out of date: `ExportDataController` and `HandleInertiaRequests` use it as the only gate on `/export-data`.

---

## 8. Integrations

### Facebook Lead Ads

Credentials are stored in the database (`integrations` table), not in `.env`. They are edited on `/integrations` (admin only).

Flow:

1. Meta sends `POST /webhooks/facebook/leads` (`routes/webhooks.php`, `MetaWebhookController::handle`). These routes have no `web` middleware, so they have no session and no CSRF check. They are throttled by IP.
2. The controller checks the `X-Hub-Signature-256` HMAC against the app secret. If it does not match, it returns 403.
3. For each `leadgen` in the payload, it dispatches `ProcessMetaLead` to the queue and **returns 200 immediately**.
   - Why: Meta treats a slow response as a failure and redelivers. The Graph fetch takes time. If the lead were processed inline, retries would arrive while the first delivery was still working. The 200 means "received", not "imported".
4. `ProcessMetaLead` (on the queue) fetches the answers from Graph with the stored page access token (`MetaGraphClient::fieldData`). A webhook carries only a `leadgen_id`, never the form answers. It then normalises the fields (`MetaLeadNormaliser`), routes by form (`LeadFormRouter`), and creates the lead (`IncomingLeadService`).
5. **Form-to-project routing:** `lead_form_routes` maps a `form_id` to a project and, optionally, a holder. If the form has no route, or its project is archived, the lead goes to the integration's **fallback project** (`default_project_id`) and the default holder (`assign_to_user_id`).
6. **Unrouted leads** are still created. The form is written into `lead_form_routes` with no project, so it shows up in the Facebook settings modal for an admin to map. Admins get one alert per form. Later leads update that alert's count instead of creating new alerts.
7. Every outcome is logged in `integration_events` and shown on the Integrations page: `created`, `duplicate`, `repeat_enquiry` or `failed`. A failed job retries 3 times and then goes to `failed_jobs`.

Every new webhook lead starts at `fresh` with a call follow-up due now. The Leads page and the bulk import follow the same invariant.

The Integrations page also has "Send test lead". It runs the same job with made-up answers instead of a Graph fetch.

Instagram, WhatsApp and Website cards appear on the page but are marked `built => false` in `config/integrations.php`. Their webhook URLs return 404.

### Two operational traps

**(a) No cron means no leads.** The webhook only queues the lead. A worker must process the queue (`QUEUE_CONNECTION=database`). If no worker is running, leads sit in the `jobs` table forever. Meta shows the delivery as successful, the webhook returned 200, and nothing errors anywhere. The server needs **both**:

- `php artisan schedule:run` every minute. Hourly automation (`automation:run`) and `leads:prune-imports` depend on it.
- A queue worker (`php artisan queue:work`). Leads, test leads, WhatsApp API sends and anything else queued depend on it.

To check: if `SELECT COUNT(*) FROM jobs` keeps growing, the worker is not running.

**(b) The page access token must be the long-lived one.** A short-lived Page Access Token expires in about an hour. Once it expires, every Graph fetch fails, and each lead is logged as `failed` on the Integrations page. The token saved in the CRM must show **"Expires: Never"** in Meta's Access Token Debugger. Production lost a day of real leads to this. After you replace an expired token, replay the lost leads with `php artisan queue:retry all`.

### WhatsApp

- **Outbound only.** Nothing receives WhatsApp messages. There is no webhook, so the CRM never knows about delivered, read, or the customer's 24-hour window. The log says **"Accepted by Meta (wamid …)"**, and never "delivered" or "read". The per-lead panel shows the window as "unknown, template required".
- **Click-to-send.** Always available. Rules and users queue a message rendered from a `MessageTemplate`. "Open in WhatsApp" builds a `wa.me` link and a person presses send. The log records `opened`, never `sent`.
- **API sending** (Graph v26.0; Facebook Lead Ads stays on its own version). Settings are on Automation → Queue: Phone Number ID, WABA ID and access token (encrypted), plus two switches:
  - **Use API sending off:** everything is click-to-send, and API-mode rules fall back to it.
  - **On, automatic sending off:** API messages from rules wait in the Queue for "Send by API".
  - **On, automatic sending on:** the worker sends them straight away.
  - A user sending from a lead's WhatsApp panel is sending on purpose, and does not wait for the second switch.
- **Templates only.** "Sync from Meta" pulls `{waba}/message_templates` into `whatsapp_templates`. A `MessageTemplate` is sent by API only when it is linked to an **APPROVED** Meta template with the same number of `{{n}}` variables. Parameters come from the stored `placeholder_map` order, rendered when queued and stored on the row.
- **Guards on API-mode rules.** A rule can't be saved on an unapproved or mismatched template. Terminal stages (booked, lost) are skipped unless the rule opts in. The same message with the same values to the same number is skipped inside the rule's cooldown, even when it comes through a different lead. Leads without a usable number are logged as skipped, with the reason.
- **Errors.** 190 (token), 131026 (not on WhatsApp) and 131047 (window) fail once, with a plain-English reason. Rate limits, 5xx and timeouts retry with backoff. Anything else stores Meta's raw error.
- **Depends on the queue worker.** Sends never happen inline; they go through `SendWhatsAppMessage` on the queue. Without the worker (the second cron entry in §11), API messages sit at "Waiting to be sent by API" forever and nothing errors.

---

## 9. Uniqueness and duplicates

`(mobile_number, project_id)` is **no longer unique**. Migration `2026_09_28_163644_allow_duplicate_mobile_numbers_on_leads_table` replaced the unique index with a plain index on `mobile_number`. The add/edit lead form now **warns** about a duplicate (`POST /leads/check-duplicate`) and does not block it.

Some paths still refuse a duplicate number and project in application code:

- **Webhook leads:** `IncomingLeadService` returns `repeat_enquiry` and creates nothing. It holds a cache lock per project and number, so simultaneous deliveries cannot both pass the check.
- **Project switch:** `ValidatesProjectSwitch` refuses to move a lead onto a project where its number already has a lead.
- **Bulk import wizard:** `LeadImportPlanner::duplicates()` marks such rows `skip_duplicate_db` and skips duplicates within the file.

What guarantees idempotency:

| Path | Key |
|---|---|
| Meta webhook | `leads.external_id` = the `leadgen_id`. It has a unique index and is checked with `withTrashed()`, so a deleted lead does not come back on a retry. A unique violation from a race is caught and reported as `duplicate`. |
| Legacy / one-off JSON imports | `_import_key` (`"source_file:row"`) in the JSON is recorded in `lead_import_records`, which is unique on `(source_file, source_row)`. A second run skips rows that are already recorded. |
| Bulk import wizard | Each row's `import_key` is stored in `lead_import_records.source_file` and checked before the row is imported (`skip_imported`). |
| Logging a call | `TodoController::complete()` returns 422 if the todo is no longer `pending`. The service locks the lead row. |
| Adding a follow-up | `TodoRequest` refuses a second pending todo. |
| Double-clicking a form | The submit buttons in `LeadFormModal.vue` and `CompleteTaskModal.vue` are disabled while `form.processing` is true. |

Gap: nothing on the server prevents the same person from being created twice from the add-lead form. Since the unique index was dropped, a double submit that gets past the disabled button creates two leads.

---

## 10. Testing

The suite runs on **MySQL**, not SQLite. The dev machine has no SQLite driver.

```bash
DB_CONNECTION=mysql DB_DATABASE=lead_crm_test php artisan test --compact
# or, with the forced MySQL config:
php artisan test -c phpunit.mysql.xml
```

**Never point the tests at `lead_crm`.** The tests use `RefreshDatabase`, which drops and rebuilds whatever database they connect to. `phpunit.xml` defaults to SQLite `:memory:` without `force`, so shell variables override it. `phpunit.mysql.xml` forces `lead_crm_test`.

Run a single file: `DB_CONNECTION=mysql DB_DATABASE=lead_crm_test php artisan test --compact tests/Feature/LeadRoutingTest.php`.

**Known failures: 15 out of 1058** (10 failures and 5 errors, measured 2026-09-29 on `feature/intgration-correct`). All 15 were already failing before the current work. Do not chase them as regressions. If the count is anything other than 15, look at what changed.

| Tests | Why they fail |
|---|---|
| `AdminSeederTest` (1) | Expects the admin's name to be `Admin`. `AdminSeeder` now writes `Sagar`. |
| `AlertSystemTest` (7), `AlertReadRedirectTest` (1) | Written for an older alert bell. They expect an `alertBell.unread` prop and an `alerts.read` route without an `{alert}` parameter, and neither exists any more. |
| `CalendarPageTest` (3) | Date-window and status-filter expectations that no longer match, plus a fixture that creates `admin@example.test` twice. |
| `ExportDataTest` (1) | A byte-level assertion on the PDF output that does not match the current dompdf output. |
| `SyncLeadAssigneesTest`, `TodayLeadFollowupTest` (1 each) | They force a failure with trigger syntax that only SQLite accepts. On MySQL this is a syntax error. |

`tests/Feature/QA/*Probe.php` are QA probes, not regular `*Test.php` files. The `*.mjs`/`*.test.js` files in `tests/Unit` are frontend tests and are not run by PHPUnit.

---

## 11. Deployment

Production runs on Hostinger. **The repository does not record the server path or the crontab.** Fill these in here the first time you log in. Until then, `<app-path>` below is a placeholder, not a real path.

Production `.env` must have:

- `APP_ENV=production`
- `APP_DEBUG=false`. With `true`, stack traces are shown to anyone, including the public webhook URL.
- `APP_TIMEZONE=Asia/Kolkata` (see §2)
- `QUEUE_CONNECTION=database`

Required cron entries (see §8, trap a):

```cron
* * * * * cd <app-path> && php artisan schedule:run >> /dev/null 2>&1
```

You also need a queue worker that is always running, or is restarted by cron if the host has no supervisor. Without it, no Facebook lead is ever imported and no WhatsApp API message is ever sent. Production runs it from cron:

```cron
* * * * * cd <app-path> && php artisan queue:work --stop-when-empty --tries=3 --max-time=55 >> /dev/null 2>&1
```

`public/build/` is committed to git. Run `npm run build` and commit the result with any frontend change, or production will serve the old assets.

A deploy is normally:

```bash
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan optimize
php artisan queue:restart
```

**Never run these on production:**

- `php artisan migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe`. They drop the live data.
- `php artisan test`. `RefreshDatabase` would rebuild the production database.
- `php artisan import:legacy --fresh` (especially with `--force`). It deletes previously imported leads and their todos, including leads that have been worked since.
- `php artisan followups:create-today` and `leads:sync-assignees`. These were one-off fixes with hard-coded project and staff names. Running them again reassigns live work.
- `npm run dev`. It writes `public/hot`, and the site then tries to load assets from a Vite dev server.
- `php artisan db:seed` on a live install without first checking that `admin1@gmail.com` exists with a changed password. `AdminSeeder` creates it with `123456789` if it is missing.

Safe on production, and useful: `crm:check-consistency`, `crm:verify-dashboard`, `import:legacy --awaiting`, `queue:retry`, `queue:failed`.

---

## 12. Directory map

| Path | What is there |
|---|---|
| `app/Services/` | The business rules. Start with `LeadFollowUpService` and `LeadAssignmentService`. |
| `app/Services/Automation/` | Rule engine, condition matcher, action runner, loop guard, rule catalogue. |
| `app/Services/LeadImport/` | The upload-a-spreadsheet wizard: read, detect columns, plan, store, import. |
| `app/Services/LegacyImport/` | The one-time import of the client's old sheets (`import:legacy`). |
| `app/Services/WhatsApp/` | Message queueing and template rendering. |
| `app/Exports/` | `DataExporter`: every export type, column and format. |
| `app/Http/Controllers/` | One controller per page. `Concerns/` has the shared date-range and filter logic. `Webhooks/` has the Meta endpoint. |
| `app/Http/Requests/` | Validation and authorisation per form. `Concerns/` is shared between them. |
| `app/Jobs/` | `ProcessMetaLead` (live), `SendWhatsAppMessage` (never dispatched). |
| `app/Console/Commands/` | Audits (`crm:*`), automation, imports and one-off data fixes. |
| `app/Models/` | Eloquent models. `Lead` and `Todo` hold the visibility scopes. |
| `app/Support/CrmTaxonomy.php` | Reads stages and sources from the DB, with config as the fallback. |
| `config/crm.php` | Stages, sources, roles, permissions, handover, reports, alerts. Read this early. |
| `config/integrations.php`, `config/automation.php` | Provider list, Meta Graph settings, rule vocabulary. |
| `routes/web.php`, `routes/webhooks.php`, `routes/console.php` | Pages, the public webhook, the schedule. |
| `resources/js/Pages/` | One Vue page per route. |
| `resources/js/Components/`, `composables/`, `lib/` | Shared UI, composables and small helpers. |
| `database/migrations/` | Schema. Several migrations also move data; read them before rolling back. |
| `tests/Feature/` | The PHPUnit suite. |
| `old-data/`, `lead-18-sep-data/`, `lead-vanam-22-sep-data/` | JSON input for the import commands. Real customer data. |
| `scripts/` | `clean_legacy_data.py`, which prepared `old-data/`, and its report. |
| `public/build/` | Committed Vite build output. |
