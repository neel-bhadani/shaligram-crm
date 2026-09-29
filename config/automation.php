<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The whole vocabulary of a rule
    |--------------------------------------------------------------------------
    |
    | Everything a rule may say is written down in this file, and nowhere else.
    | The server validates against these arrays, the engine executes from them,
    | and the builder's dropdowns and the live preview sentence are rendered
    | from them — so a trigger that is not here cannot be stored, cannot run,
    | and cannot be offered. No Vue file names a trigger, a condition or an
    | action.
    |
    | It is a fixed vocabulary on purpose. There is no free text, no expression
    | language and no nesting, because the person writing these runs a builder's
    | office: every bit of power added here is a rule they can no longer read
    | back in plain words and confirm.
    |
    | `phrase` is that plain-words form. Each entry carries the fragment it
    | contributes to the sentence above the builder — "When a lead is created",
    | "the source is Facebook", "assign it round-robin to a telecaller" — and
    | resources/js/lib/rulePhrase.js is the single thing that joins them up.
    | Two implementations of that sentence, one here and one there, would drift
    | apart within a month and the preview would start lying.
    |
    | `hint` is the line printed under the dropdown. It is written for the admin,
    | not the developer: "what has to happen before this rule runs", never
    | "the event that dispatches evaluation".
    |
    */

    'triggers' => [

        /*
         | Event triggers. These fire from LeadFollowUpService as leads actually
         | move — see App\Services\Automation\RuleEngine, which is the one place
         | that evaluates rules. Nothing polls for them.
         */

        'lead_created' => [
            'label' => 'A new lead is added',
            'phrase' => 'When a lead is created',
            'kind' => 'event',
            'hint' => 'Runs the moment a lead is saved — typed in on the Leads page, or arriving on its own from Facebook.',
            'params' => [],
        ],

        'stage_changed' => [
            'label' => 'A lead moves to a stage',
            'phrase' => 'When a lead moves to {stage}',
            'kind' => 'event',
            'hint' => 'Runs when somebody moves a lead INTO the stage you pick — not while it sits there.',
            'params' => [
                'stage' => [
                    'label' => 'Which stage',
                    'type' => 'select',
                    'options' => 'stages',
                    'required' => true,
                    'hint' => 'The stage the lead arrives at.',
                ],
            ],
        ],

        'lead_assigned' => [
            'label' => 'A lead is given to someone',
            'phrase' => 'When a lead is assigned to somebody',
            'kind' => 'event',
            'hint' => 'Runs when a lead changes hands — including the automatic handover to a salesperson when a site visit is booked.',
            'params' => [],
        ],

        /*
         | Time triggers. Nothing happens to a lead when it becomes overdue;
         | there is no event to hang these on, so the hourly command
         | `automation:run` asks the question instead. See RunAutomation.
         */

        'follow_up_overdue' => [
            'label' => 'A follow-up is overdue',
            'phrase' => 'When a follow-up is more than {days} overdue',
            'kind' => 'time',
            'hint' => 'Checked every hour. Counts from the date the follow-up was due, not from when the lead was added.',
            'params' => [
                'days' => [
                    'label' => 'Overdue by how many days',
                    'type' => 'number',
                    'min' => 1,
                    'max' => 90,
                    'default' => 3,
                    'required' => true,
                    'unit' => 'days',
                    'hint' => 'A follow-up one hour late is not a problem. Three days late is.',
                ],
            ],
        ],

        'stage_idle' => [
            'label' => 'A lead sits in a stage too long',
            'phrase' => 'When a lead has been sitting at {stage} for {days}',
            'kind' => 'time',
            'hint' => 'Checked every hour. This is about a lead that has stopped moving, whether or not anybody is calling it.',
            'params' => [
                'stage' => [
                    'label' => 'Which stage',
                    'type' => 'select',
                    'options' => 'stages',
                    'required' => true,
                    'hint' => 'The stage the lead is stuck in.',
                ],
                'days' => [
                    'label' => 'For how many days',
                    'type' => 'number',
                    'min' => 1,
                    'max' => 365,
                    'default' => 7,
                    'required' => true,
                    'unit' => 'days',
                    'hint' => 'Counted from the day it entered that stage.',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Conditions
    |--------------------------------------------------------------------------
    |
    | ANDed, always. Every condition on a rule has to be true or the rule does
    | not run. There is no OR and there are no groups: "Facebook leads OR
    | Instagram leads" is two rules, and two rules an admin can read beat one
    | rule they have to decode.
    |
    | `column` is a real column on `leads` and is never interpolated from user
    | input — the stored rule names a KEY, the key is looked up here, and only
    | the trusted `column` beside it reaches a query. This is the same shape
    | config('crm.reports') uses, for the same reason.
    */

    'conditions' => [

        'source' => [
            'label' => 'Source',
            'phrase' => 'the source is {value}',
            'column' => 'source',
            'options' => 'sources',
            'hint' => 'Where the lead came from — the same list as the Source box on the lead form.',
        ],

        'project' => [
            'label' => 'Project',
            'phrase' => 'the project is {value}',
            'column' => 'project_id',
            'options' => 'projects',
            'hint' => 'Only leads enquiring about this project.',
        ],

        'stage' => [
            'label' => 'Stage',
            'phrase' => 'the stage is {value}',
            'column' => 'stage',
            'options' => 'stages',
            'hint' => 'Where the lead is right now, at the moment the rule runs.',
        ],

        'assigned_role' => [
            'label' => 'Assigned to a',
            'phrase' => 'it is assigned to a {value}',
            'column' => 'assigned_role',
            'options' => 'roles',
            /*
             | Lowercased in the preview sentence. "Telecaller" is a label on a
             | dropdown and a proper noun there; "assigned to a Telecaller"
             | mid-sentence reads like a mail merge. Stages, sources and
             | project names keep their capitals, which is why this is a flag
             | rather than a rule applied to everything.
             */
            'lower' => true,
            'hint' => 'Whether the lead is currently with a telecaller or a salesperson.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Actions
    |--------------------------------------------------------------------------
    |
    | Run in the order the admin arranged them, all inside one transaction: a
    | rule either does everything it said it would or it does nothing, so a lead
    | can never be left half-processed with its stage moved and its follow-up
    | missing.
    |
    | Two of these — change_stage and create_follow_up — do NOT write the
    | database themselves. They call LeadFollowUpService, which is the only
    | thing in the application allowed to write `leads.stage` or a pending
    | to-do, and which is what keeps "every open lead has exactly one pending
    | follow-up" true whether the change came from a person or from a rule.
    |
    | `when` on a parameter is the builder's conditional display: the alert's
    | role box appears only once "Everyone with a role" is chosen. It is
    | presentation — AutomationRuleRequest validates the same relationship.
    */

    'actions' => [

        'assign_user' => [
            'label' => 'Give it to one person',
            'phrase' => 'give it to {user_id}',
            'hint' => 'Always the same person. Use this when one desk owns a source outright.',
            'params' => [
                'user_id' => [
                    'label' => 'Who',
                    'type' => 'select',
                    'options' => 'users',
                    'required' => true,
                    'hint' => 'Only active staff are listed. A rule pointing at somebody who leaves is skipped and logged, not silently dropped.',
                ],
            ],
        ],

        'assign_round_robin' => [
            'label' => 'Share it out among a role',
            'phrase' => 'assign it round-robin to a {role}',
            'hint' => 'Takes it in turns across everybody active in that role, so nobody gets two in a row. Salespeople take turns within the lead\'s project, among the people ticked on its page.',
            'params' => [
                'role' => [
                    'label' => 'Which desk',
                    'type' => 'select',
                    'options' => 'roles',
                    'required' => true,
                    'lower' => true,
                    'hint' => 'Telecallers do the first call; salespeople take over at the site visit.',
                ],
            ],
        ],

        'change_stage' => [
            'label' => 'Move it to another stage',
            'phrase' => 'move it to {stage}',
            'hint' => 'Changes the stage exactly as a person would, and is recorded in the lead history as done by automation.',
            'params' => [
                'stage' => [
                    'label' => 'Move to',
                    'type' => 'select',
                    'options' => 'stages',
                    'required' => true,
                    'hint' => 'Careful: a rule that moves a stage can set off another rule that watches for that move.',
                ],
            ],
        ],

        'create_follow_up' => [
            'label' => 'Create a follow-up',
            'phrase' => 'create a {todo_type} follow-up in {hours}',
            'hint' => 'Puts a task on the assigned person\'s Follow-ups page. If the lead already has one pending, that one is replaced.',
            'params' => [
                'hours' => [
                    'label' => 'In how many hours',
                    'type' => 'number',
                    'min' => 1,
                    'max' => 720,
                    'default' => 24,
                    'required' => true,
                    'unit' => 'hours',
                    'hint' => 'Counted from the moment the rule runs. 1 for "right away", 24 for "tomorrow".',
                ],
                /*
                 | `todo_type`, not `type`. Every action in a stored rule is
                 | `{"type": "create_follow_up", ...}` — the action's own key is
                 | `type`, so a parameter called `type` would overwrite it and
                 | the engine would be handed an action whose type is "call".
                 | Nothing would throw; the rule would simply stop existing.
                 */
                'todo_type' => [
                    'label' => 'What kind',
                    'type' => 'select',
                    'options' => 'todo_types',
                    'default' => 'call',
                    'required' => true,
                    'lower' => true,
                    'hint' => 'The same list as the Follow-up box on the lead form.',
                ],
                'remarks' => [
                    'label' => 'Note on the task',
                    'type' => 'text',
                    'required' => false,
                    'hint' => 'What the person should do. Shown on their Follow-ups page.',
                ],
            ],
        ],

        'raise_alert' => [
            'label' => 'Raise an alert',
            'phrase' => 'alert {recipient}',
            'hint' => 'A message under the bell. It is not a task — reading it does not change the lead.',
            'params' => [
                'recipient' => [
                    'label' => 'Who should know',
                    'type' => 'select',
                    'options' => 'alert_recipients',
                    'default' => 'lead_owner',
                    'required' => true,
                    'hint' => 'Nobody is ever alerted about a lead they are not allowed to see — those are skipped quietly.',
                ],
                'recipient_role' => [
                    'label' => 'Which role',
                    'type' => 'select',
                    'options' => 'roles',
                    'required' => true,
                    'lower' => true,
                    'when' => ['recipient' => 'role'],
                    'hint' => 'Everybody active in this role gets it.',
                ],
                'recipient_user_id' => [
                    'label' => 'Which person',
                    'type' => 'select',
                    'options' => 'users',
                    'required' => true,
                    'when' => ['recipient' => 'user'],
                    'hint' => 'One named person.',
                ],
                'severity' => [
                    'label' => 'How loud',
                    'type' => 'select',
                    'options' => 'severities',
                    'default' => 'info',
                    'required' => true,
                    'hint' => 'Colour only. It does not change who gets it or when.',
                ],
                'title' => [
                    'label' => 'Alert headline',
                    'type' => 'text',
                    'required' => true,
                    'hint' => 'One line. You can use {lead_name} and {project} here.',
                ],
                'body' => [
                    'label' => 'More detail',
                    'type' => 'textarea',
                    'required' => false,
                    'hint' => 'Optional second line, shown when the alert is opened.',
                ],
            ],
        ],

        'queue_whatsapp' => [
            'label' => 'Queue a WhatsApp message',
            'phrase' => 'queue the {template_id} WhatsApp message',
            'hint' => 'Puts the message in the Queue tab for somebody to open and send. It is never sent on its own.',
            'params' => [
                'template_id' => [
                    'label' => 'Which message',
                    'type' => 'select',
                    'options' => 'templates',
                    'required' => true,
                    'hint' => 'Written on the Templates tab. The lead\'s name and project are filled in when the message is queued.',
                ],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Who an alert can be addressed to
    |--------------------------------------------------------------------------
    |
    | Resolved by AlertService, which drops anybody who cannot see the lead
    | before writing a row. That check is not a nicety — an alert's title
    | carries the lead's name, so an alert to the wrong person leaks exactly
    | the thing scopeVisibleTo exists to protect.
    */

    'alert_recipients' => [
        'lead_owner' => ['label' => 'The person the lead is assigned to', 'phrase' => 'whoever the lead belongs to'],
        'admins' => ['label' => 'All admins',                          'phrase' => 'all admins'],
        'role' => ['label' => 'Everybody in a role',                 'phrase' => 'every {recipient_role}'],
        'user' => ['label' => 'One named person',                    'phrase' => '{recipient_user_id}'],
    ],

    'severities' => [
        'info' => ['label' => 'Information', 'hint' => 'Worth knowing. Grey.'],
        'warning' => ['label' => 'Warning',     'hint' => 'Somebody should look. Amber.'],
        'urgent' => ['label' => 'Urgent',      'hint' => 'Deal with it today. Red.'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Loop protection
    |--------------------------------------------------------------------------
    |
    | A rule that changes a stage fires the stage-changed trigger. Another rule
    | watching that stage can change it back, which fires the trigger again.
    | Left alone, two innocent-looking rules will move one lead between two
    | stages until the request times out, writing a to-do and an alert every
    | time round.
    |
    | Two independent caps, because they stop two different things:
    |
    |   max_touches_per_chain   how far one original event may cascade. Counted
    |                           per lead, in memory, for the length of one
    |                           chain. This is what stops the ping-pong inside
    |                           a single request.
    |
    |   cooldown_minutes        how often one rule may act on one lead at all.
    |                           Read from automation_logs, so it holds across
    |                           requests, across the hourly command and across
    |                           a queue worker — which the in-memory counter
    |                           cannot.
    |
    | Every suppression is written to automation_logs AND alerted to admins.
    | A silently throttled rule is the most confusing thing this feature can
    | do: the admin sees a rule that is switched on, matches, and did nothing.
    */

    'loop_protection' => [
        'max_touches_per_chain' => 3,
        'cooldown_minutes' => 60,

        /*
         | Time triggers get a longer one, and it is not a contradiction of the
         | line above — 1440 minutes is comfortably more than "never twice in an
         | hour", it is the same rule set further out.
         |
         | It has to be. The hourly command asks "which follow-ups are three days
         | overdue" sixty minutes after it last asked, and gets back the same
         | leads: on the event cooldown alone, a single stalled lead would write
         | twenty-four lines to the activity log every day and the Activity tab
         | would be useless for finding anything. Alert deduplication already
         | keeps the BELL quiet; this is what keeps the LOG readable.
         */
        'time_trigger_cooldown_minutes' => 1440,

        // suppressions are noisy by nature, so admins are told once and then
        // left alone for the day — AlertService dedupes on top of this
        'alert_admins' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | WhatsApp
    |--------------------------------------------------------------------------
    */

    'whatsapp' => [

        /*
         | Meta bills per conversation and the categories are not close in
         | price. Utility is roughly an eighth of marketing in India, which
         | turns a daily broadcast from an annoyance into a real line on the
         | bill — so the admin picks the category themselves, with the
         | difference on screen, rather than having one chosen for them.
         |
         | No rupee figures. Meta's rate card moves by country and by month and
         | a number printed here would be wrong within a quarter; the ratio is
         | what changes the decision and it has been stable for years.
         */
        'categories' => [
            'utility' => [
                'label' => 'Utility',
                'cost_note' => 'Cheapest — roughly an eighth of the price of a marketing message.',
                'hint' => 'A message about something the customer already started: a booking, a visit they asked for, a brochure they requested.',
            ],
            'marketing' => [
                'label' => 'Marketing',
                'cost_note' => 'Most expensive — around eight times a utility message.',
                'hint' => 'Offers, launches, festive greetings. Anything the customer did not ask for.',
            ],
            'authentication' => [
                'label' => 'Authentication',
                'cost_note' => 'Priced like utility, but only for one-time passcodes.',
                'hint' => 'One-time passcodes only. Not used by this CRM — listed because Meta will ask.',
            ],
        ],

        /*
         | What may go in a template body, and what each one turns into. The
         | `example` is what the placeholder picker shows beside the name — an
         | admin reading "{owner_phone}" has to be told it becomes a phone
         | number, not left to find out after fifty messages have gone out.
         */
        'placeholders' => [
            'lead_name' => ['label' => "The customer's full name", 'example' => 'Rahul Mehta'],
            'first_name' => ['label' => 'Just their first name',    'example' => 'Rahul'],
            'project' => ['label' => 'The project they asked about', 'example' => 'Skyline Residency'],
            'owner_name' => ['label' => 'Your staff member handling them', 'example' => 'Priya Shah'],
            'owner_phone' => ['label' => "That staff member's mobile", 'example' => '+91 98200 00002'],
            'stage' => ['label' => 'Where the lead has reached', 'example' => 'Site visit done'],
        ],

        // wa.me is the click-to-chat host. It takes the number as bare digits
        // with the country code and no punctuation of any kind — a single
        // space or dash and the link opens WhatsApp on nothing.
        'link_base' => 'https://wa.me/',

        /*
         | The API half. Everything is built and nothing is switched on: there
         | are no credentials yet, and there will not be until the client
         | confirms which WhatsApp product they actually have.
         |
         | This matters more than it sounds. The free WhatsApp Business APP on
         | a phone has no API at all — no amount of configuration makes it send
         | programmatically. Sending from software needs WhatsApp Business
         | PLATFORM access through Meta or a provider, which is an approval
         | process and a monthly bill.
         |
         | Credentials live in the `integrations` table under this provider,
         | where the settings column is `encrypted:array`. They are never
         | rendered to the browser — see MessageTemplateController and
         | Integration::maskedSetting().
         */
        'provider' => 'whatsapp',
        'secret_keys' => ['access_token'],
        'api' => [
            'base' => env('WHATSAPP_API_BASE', 'https://graph.facebook.com'),
            'version' => env('WHATSAPP_API_VERSION', 'v21.0'),
            'timeout' => 15,
        ],

        /*
         | Auto-send is off, and off is the default in the code rather than
         | only in the seeded row: a fresh install with no settings row at all
         | must queue, not send. Flipping it is an admin setting on the Queue
         | tab and it cannot be flipped while the API is unconfigured.
         */
        'auto_send_default' => false,
    ],

    /*
     | The Activity tab shows this many rows, and the Queue this many messages.
     | Both are "the recent past", not an archive — the tables keep everything.
     */
    'log_limit' => 100,
    'queue_limit' => 100,
];
