<?php

return [

    'stages' => [
        'fresh' => 'Fresh',
        'connected' => 'Connected',
        'not_connected' => 'Not connected',
        'details_shared' => 'Details shared',
        'site_visit_scheduled' => 'Site visit scheduled',
        'site_visit_done' => 'Site visit done',
        'in_discussion' => 'In discussion',
        'booking_done' => 'Booking done',
        'lost' => 'Lost',
    ],

    'terminal_stages' => ['booking_done', 'lost'],

    /*
     | What kind of development a project is. Two of them, and the only place
     | the words are written down — ProjectRequest validates against these keys
     | and the project modal's dropdown renders from them, so the rule and the
     | control cannot disagree.
     |
     | The column already defaults to `residential`, so every project that
     | existed before this screen did reads as one without a backfill.
     */
    'project_types' => [
        'residential' => 'Residential',
        'commercial' => 'Commercial',
    ],

    'stage_colors' => [
        'fresh' => '#8A94A0',
        'connected' => '#2F6FB0',
        'not_connected' => '#C2711A',
        'details_shared' => '#5B58B8',
        'site_visit_scheduled' => '#8145A8',
        'site_visit_done' => '#0F766E',
        'in_discussion' => '#B4881B',
        'booking_done' => '#1E7A45',
        'lost' => '#B23A38',
    ],

    /*
    |--------------------------------------------------------------------------
    | The stage colour palette
    |--------------------------------------------------------------------------
    |
    | The only colours the Stages screen offers. A FIXED LIST rather than a free
    | hex field, and the reason is that a stage's colour is not decoration: it
    | is the same swatch in a badge, a chip, a funnel band, a left border on a
    | lead row and a bar on four charts, and it has to stay legible against a
    | white card, against its own 12% tint behind the badge text, and beside the
    | eight other stages on the same axis. A colour picker produces #FFFF00 on
    | white and a pipeline nobody can read, and it produces two stages one
    | shade apart that nobody can tell from each other.
    |
    | The first nine are exactly the colours the stages shipped with, so the
    | seeded rows all land on a palette entry rather than on "custom". The rest
    | are the room to add a stage without repeating one.
    |
    | This stays in config on purpose. It is a design constraint on the screen
    | rather than data the screen manages — an admin choosing which colours
    | exist is the free-hex problem with an extra step.
    */
    'stage_palette' => [
        '#8A94A0', // grey
        '#2F6FB0', // blue
        '#C2711A', // amber
        '#5B58B8', // indigo
        '#8145A8', // violet
        '#0F766E', // teal
        '#B4881B', // gold
        '#1E7A45', // green
        '#B23A38', // red
        '#0E7490', // cyan
        '#9D174D', // rose
        '#4D7C0F', // olive
        '#6D28D9', // purple
        '#B45309', // bronze
        '#334155', // slate
    ],

    'sources' => [
        'walk_in' => 'Walk-in',
        'incoming_call' => 'Incoming call',
        'referral' => 'Referral',
        'facebook' => 'Facebook',
        'instagram' => 'Instagram',
        'whatsapp' => 'WhatsApp',
        'broker' => 'Broker',
        'hoarding' => 'Hoarding',
    ],

    /*
     | The two kinds of row in `channel_partners`, and the only place the words
     | are written down — ChannelPartnerRequest validates against these keys and
     | the modal's dropdown renders from them, so the rule and the control
     | cannot disagree.
     |
     | A firm is an organisation a lead can come through directly. A broker is a
     | person, working alone or under one of those firms. Nothing nests deeper
     | than that: a broker's parent must be a firm, and a firm has no parent.
     */
    'channel_partner_types' => [
        'firm' => 'Firm',
        'broker' => 'Broker',
    ],

    'lost_reasons' => [
        'budget' => 'Budget',
        'location' => 'Location',
        'competitor' => 'Chose competitor',
        'not_serious' => 'Not serious',
        'no_response' => 'No response',

        /*
         | The reasons the legacy Master Sheet recorded, from its "Lost-…"
         | statuses. Added for `import:legacy`: LeadRequest and
         | CompleteTodoRequest validate `reason` against these keys, so an
         | imported lost lead carrying one that is not here could never be
         | saved again. `location_issue` sits beside `location` rather than
         | replacing it — which one to keep is the client's call, not the
         | import's.
         */
        'not_interested' => 'Not interested',
        'call_unanswered' => 'Call unanswered',
        'other' => 'Other',
        'location_issue' => 'Location issue',
        'duplicate' => 'Duplicate',
        'configuration_issue' => 'Configuration issue',
        'booked_elsewhere' => 'Booked elsewhere',
        'choice_unavailable' => 'Choice not available',
        'switched_off' => 'Switched off',
        'general_enquiry' => 'General enquiry',
        'misclick' => 'Misclick',
        'ready_to_move' => 'Wants ready to move',

        /*
         | The remaining reasons found across MYCO and the 12-Sep-Lead CSV
         | when `import:legacy` merged all three legacy sources. Same
         | reasoning as the block above: these keys are on imported lost
         | leads, so LeadRequest/CompleteTodoRequest must accept them.
         */
        'not_qualified' => 'Not qualified',
        'plan_hold_cancel' => 'Plan hold & cancel',
        'other_cast' => 'Other cast',
        'wrong_number' => 'Wrong number',
        'old_property_sale' => 'Old property sale',
        'lost_to_broker' => 'Lost to broker',
        'possession_timeline_issue' => 'Possession timeline issue',
        'vastu' => 'Vastu',
        'upcoming_project' => 'Upcoming project',

        /*
         | Set only by LeadController::transfer(), never chosen by hand on the
         | lead form — the lead is not really lost, the person went looking at
         | a different project, and this is what keeps that distinct from
         | every reason above it. See LeadFollowUpService::transferToProject().
         */
        'transferred_project' => 'Interested in another project',
    ],

    'todo_types' => [
        'call' => 'Call',
        'whatsapp' => 'WhatsApp',
        'meeting' => 'Meeting',
        'site_visit' => 'Site visit',
    ],

    /*
     | How close two of one person's follow-ups have to be before the form says
     | so, in minutes, either side of the time being picked.
     |
     | One number, and deliberately one number. Not a duration per type — a call
     | and a site visit are not the same length, but nothing in this application
     | records how long anything took, so a per-type table would be inventing
     | data to make a warning look precise. Thirty minutes either side is a
     | working assumption the client can change in one place.
     |
     | It warns and nothing else. There is no validation rule reading this, no
     | refusal on save, and there must not be one: two follow-ups half an hour
     | apart are often perfectly deliberate, and the person booking them is
     | better placed to know that than a number in a config file.
     */
    'follow_up_clash_minutes' => 30,

    /*
     |--------------------------------------------------------------------------
     | Follow-ups are scheduled by hand
     |--------------------------------------------------------------------------
     |
     | Nothing in this file decides when the next task is due any more. The user
     | picks the date and the type every time a lead is added or a call is
     | logged, so there are no intervals, no retry ladder and no working-hours
     | clamp to configure: a datetime a person chose is saved exactly as it was
     | entered. See LeadFollowUpService, which is the only thing that writes a
     | pending to-do.
     |
     */

    /*
     | How a staff member's role reads in a table cell. Shorter than the role
     | key itself — "Sales" rather than "Salesperson" — because it sits on a
     | sub-line under the name and has to stay out of the way. No Vue file
     | spells these out.
     */
    'role_labels' => [
        'admin' => 'Admin',
        'telecaller' => 'Telecaller',
        'salesperson' => 'Sales',
    ],

    /*
     | The same three roles, written out in full.
     |
     | `role_labels` above is abbreviated on purpose — it sits on a sub-line
     | under a name in a table cell and has to stay out of the way. That
     | abbreviation is wrong everywhere the role appears in a sentence:
     | "assign it round-robin to a sales" is not English, and the Automation
     | page's plain-words rule preview is built out of exactly these fragments.
     |
     | Two maps rather than one, because they are answering two different
     | questions — "what is the shortest thing that still identifies this role"
     | and "what do you call one of these people". RuleCatalog reads this one;
     | every table still reads the one above.
     */
    'role_words' => [
        'admin' => 'Admin',
        'telecaller' => 'Telecaller',
        'salesperson' => 'Salesperson',
    ],

    /*
     |--------------------------------------------------------------------------
     | Per-user permissions
     |--------------------------------------------------------------------------
     |
     | Five booleans, not a permission system. Role stays the default and the
     | thing every screen reads; these only override it for one person — a sales
     | manager who should see the whole pipeline, a telecaller trusted to add
     | leads. A user whose toggles are untouched behaves exactly as their role
     | always did, which is what makes this safe to add to a live database.
     |
     | `permission_defaults` is the fallback table and the only place a role's
     | baseline is written down. User::can_() reads the JSON column first and
     | lands here when a key is unset — so an admin is not listed with five
     | `true`s by hand, they simply have every key on.
     |
     | Adding a key means adding it in both places. A key present in `permissions`
     | but missing from a role's defaults reads as false for that role, which is
     | the safe direction to fail in.
     */
    'permissions' => [
        'add_leads' => [
            'label' => 'Can add leads',
            'hint' => 'Create new leads from the Leads page.',
        ],
        'edit_leads' => [
            'label' => 'Can edit leads',
            'hint' => 'Change a lead they can already see.',
        ],
        'delete_leads' => [
            'label' => 'Can delete leads',
            'hint' => 'Soft delete a lead. Off for everyone by default.',
        ],
        'see_all_leads' => [
            'label' => 'Can see all leads',
            'hint' => 'Sees every lead like an admin, not only their own. For a sales manager.',
        ],
        'export_data' => [
            'label' => 'Can export data',
            'hint' => 'Reserved — nothing reads this yet.',
        ],
    ],

    'permission_defaults' => [
        'admin' => [
            'add_leads' => true,
            'edit_leads' => true,
            'delete_leads' => true,
            'see_all_leads' => true,
            'export_data' => true,
        ],
        'salesperson' => [
            'add_leads' => true,
            'edit_leads' => true,
            'delete_leads' => false,
            'see_all_leads' => false,
            'export_data' => false,
        ],
        'telecaller' => [
            'add_leads' => false,
            'edit_leads' => false,
            'delete_leads' => false,
            'see_all_leads' => false,
            'export_data' => false,
        ],
    ],

    /*
     | The roles the user management screen may hand out. Admin is deliberately
     | not among them: an admin is made in the seeder or the database, never
     | from inside the app, so a compromised admin session cannot mint another
     | one. UserRequest validates against this list and the modal renders from
     | it, so the rule and the dropdown cannot disagree.
     */
    'staff_roles' => ['telecaller', 'salesperson'],

    // the stage that hands a lead from telecaller to salesperson
    'handover_stage' => 'site_visit_scheduled',

    /*
     | Which desk a new lead lands on, by the stage it is saved at — never by
     | who typed it in. A walk-in added after the site visit has nothing for a
     | telecaller to call about, whoever adds it.
     |
     | Only the calling stages are listed; every other open stage takes the
     | default below, and a terminal stage takes nobody — it stays with
     | whoever added it, because no follow-up work remains.
     |
     | SEED VALUES, not the live mapping. The migration that added
     | `lead_stages.owner_role` copied these into the table, and from then on
     | the admin edits the mapping on the Stages screen — which warns, without
     | refusing, when a stage past the handover is put on the telecaller desk.
     | CrmTaxonomy reads these only when the table cannot answer, and
     | LeadStage fills a new open stage from the default.
     |
     | LeadAssignmentService is the one thing that turns this into a person.
     */
    'stage_owner_roles' => [
        'fresh' => 'telecaller',
        'connected' => 'telecaller',
        'not_connected' => 'telecaller',
    ],

    'stage_owner_role_default' => 'salesperson',

    /*
     | The stages a telecaller does not work: the handover stage and everything
     | past it. The user management screen reads this, to warn an admin who is
     | about to demote a salesperson still holding leads at these stages —
     | those leads do not move on their own, and the person left holding them
     | would no longer be doing that job. LeadAssignmentService falls back to
     | it only when there is no handover stage to measure positions from.
     */
    'advanced_stages' => [
        'site_visit_scheduled',
        'site_visit_done',
        'in_discussion',
        'booking_done',
    ],

    // round_robin | admin
    'handover_mode' => 'round_robin',

    /*
     | Dialling code for the tel: and wa.me links on the to-do rows and in the
     | log-call modal. Stored mobile numbers are the bare 10 digits, so this is
     | what turns one into something a phone can dial. It reaches the front end
     | through each page's `options` prop — no Vue file names it.
     */
    'country_code' => '+91',

    /*
    |--------------------------------------------------------------------------
    | Reports
    |--------------------------------------------------------------------------
    |
    | The Reporting section is two pages, not ten: /reports/leads and
    | /reports/followups, each with a "Group by" control. Every sidebar link
    | under Reports points at one of those two with a different query string,
    | and this block is the list of dimensions those controls offer.
    |
    | Adding a dimension is an entry here plus, for a lead dimension, whatever
    | it needs to name its groups in ReportController::leadGroups(). No new
    | route, no new page, no new Vue file.
    |
    | `column` is a real column on the table being grouped — leads for the lead
    | dimensions, todos for the follow-up ones — and it is never interpolated
    | from user input: the request names a KEY, the key is validated against
    | these arrays, and only then does the trusted `column` beside it reach a
    | query.
    |
    | `admin` marks a dimension that is only meaningful to someone who can see
    | past their own rows — grouping by assigned-to when every row is yourself
    | is one row. It hides the option, and ReportController drops the dimension
    | on the way in as well, so a typed query string cannot select it either.
    | Neither is the privacy boundary: scopeVisibleTo and scopeForUser are, and
    | they apply whatever dimension is chosen.
    */
    'reports' => [

        /*
         | `column` is the real column each dimension groups by. Nothing here is
         | interpolated from a request: the request names a KEY, the key is
         | validated against these arrays, and only the trusted `column` beside
         | it reaches a query.
         */
        'lead_dimensions' => [
            'stage' => ['label' => 'Stage',       'column' => 'stage'],
            'source' => ['label' => 'Source',      'column' => 'source'],
            'project' => ['label' => 'Project',     'column' => 'project_id'],
            /*
             | The point of the channel-partner feature: which broker actually
             | brings business.
             |
             | Not marked `admin`, and the same grouping every other lead
             | dimension gets. It is worth saying why, because /channel-partners
             | IS admin-only: what that page holds is phone numbers, addresses,
             | contact people and the buttons that edit and delete them. Partner
             | NAMES are not secret from the staff who file leads — the lead
             | form's picker has to offer them or a broker lead cannot be
             | attributed at all — so a grouping that lists the same names adds
             | nothing an authorised user could not already read.
             |
             | The counts were never the question: scopeVisibleTo is inside the
             | closure in ReportController::leads(), so a telecaller opening this
             | gets a breakdown of their own leads, exactly as By source gives
             | them theirs.
             |
             | If that judgement is ever revisited, `'admin' => true` here is the
             | whole change: dimensions() drops it from the control and
             | leadFilters() refuses it from a typed query string, and
             | AppLayout's wideLeads test hides the sidebar link to match.
             */
            'channel_partner' => ['label' => 'Channel partner', 'column' => 'channel_partner_id'],
            'assigned_to' => ['label' => 'Assigned to', 'column' => 'assigned_to', 'admin' => true],
        ],

        'follow_up_dimensions' => [
            'type' => ['label' => 'Type',        'column' => 'type'],
            'assigned_to' => ['label' => 'Assigned to', 'column' => 'assigned_to', 'admin' => true],
        ],

        /*
         | The four status filters, and the date column each one is measured on.
         |
         | This is the single most breakable thing in the follow-ups report.
         | Completed asks "when was it done", so it filters completed_at; the
         | three pending ones ask "when is it due", so they filter scheduled_at.
         | Filtering a pending bucket on completed_at would match nothing at all
         | — the column is null until the call is logged — and filtering
         | Completed on scheduled_at would count a call planned inside the range
         | and closed long after it.
         |
         | The keys are the To-do page's own tab names, which is what lets a
         | drill-through hand the row straight to ?tab=<key> and land on the
         | same set of rows the report counted.
         */
        'follow_up_statuses' => [
            'overdue' => ['label' => 'Waiting longer', 'column' => 'scheduled_at'],
            'today' => ['label' => 'Due today',      'column' => 'scheduled_at'],
            'upcoming' => ['label' => 'Upcoming',       'column' => 'scheduled_at'],
            'completed' => ['label' => 'Completed',      'column' => 'completed_at'],
        ],

        'default_range' => '30',
    ],

    /*
    |--------------------------------------------------------------------------
    | Date ranges
    |--------------------------------------------------------------------------
    |
    | The three presets every date control offers, in the order they are drawn,
    | plus Custom which the control adds itself. The dashboard and both report
    | pages render this same list through the same component, so there is one
    | answer to "what windows can I choose" and one place to change it.
    |
    | The keys are what ResolvesDateRange::dateWindow() turns into boundaries
    | and what dateRangeRules() validates against, so a preset that is not in
    | that method cannot be added here and quietly do nothing.
    |
    | No All time: every figure on a report page is "in the selected period",
    | and a range that is not a period would make half of them mean something
    | else. The Leads and To-do pages offer their own All time option on top of
    | these, because a list is not a measurement.
    */
    /*
     | A LIST, not a key => label map, and that is not a style choice.
     |
     | Two of the three keys — '7' and '30' — are integer-like strings, and a
     | PHP array keyed that way arrives in JavaScript as an object whose
     | integer-like keys iterate first, in ascending numeric order, whatever
     | order they were written in. A map here renders as "Last 7 days, Last 30
     | days, Today": the control silently reorders itself and no amount of
     | rewriting this file fixes it. A list has one order and keeps it.
     */
    'date_ranges' => [
        ['key' => 'today', 'label' => 'Today'],
        ['key' => '7',     'label' => 'Last 7 days'],
        ['key' => '30',    'label' => 'Last 30 days'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts
    |--------------------------------------------------------------------------
    |
    | An alert is a message to a person inside the CRM. It is not a follow-up:
    | it is not scheduled work, it belongs to nobody's day, and reading one
    | changes nothing about the lead. See App\Models\Alert.
    |
    | The four built-in alerts need no rule and cannot be switched off — they
    | are the things an office would notice for itself if it had time to look.
    | The hourly `automation:run` command raises them.
    |
    | `dedupe_hours` is the number that decides whether anybody uses this
    | feature. The hourly command asks "which follow-ups are more than three
    | days overdue" every hour, and the answer is the same lead every hour: at
    | 24 alerts a day per lead the bell is noise by tomorrow morning and the
    | admin stops looking at it. The same type, for the same lead and the same
    | person, is refused within this window — refused at creation, in
    | AlertService::raise(), not filtered out on display. Filtering would leave
    | the bell counting rows nobody can see.
    |
    | The vocabulary of a RULE's alerts — recipients, severities — lives in
    | config/automation.php with the rest of the rule catalogue. This block is
    | only the thresholds the built-in ones measure against.
    */
    'alerts' => [

        // a follow-up this many days past its date is worth telling somebody
        'overdue_days' => 3,

        // a lead that has not moved stage in this many days has stalled
        'stuck_days' => 7,

        // and neither is worth telling them twice a day
        'dedupe_hours' => 24,

        /*
         | How many the bell drops down. Ten is about one screen; the rest are
         | on the Alerts page, which is what the "See all" link is for.
         */
        'bell_limit' => 10,

        /*
         | In-app only. There is deliberately no mail channel here and no
         | notification class behind these: this application runs on
         | MAIL_MAILER=log, where a queued mail writes a line to a file and
         | nothing arrives — which looks exactly like a broken feature and is
         | worse than not offering it.
         */
    ],
];
