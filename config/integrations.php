<?php

return [

    /*
    |--------------------------------------------------------------------------
    | The platforms the Integrations page offers
    |--------------------------------------------------------------------------
    |
    | One entry, one card. `built` is the whole difference between a card that
    | configures and a card that says "Coming soon" and is disabled — no Vue
    | file names a provider or decides which of them work.
    |
    | Instagram lead ads are delivered by the SAME Meta webhook and the same
    | Graph endpoint as Facebook: a lead form on an Instagram ad arrives on the
    | page's `leadgen` subscription exactly as a Facebook one does. Turning it
    | on is this flag plus letting the Meta settings form write a second row —
    | it is not a second integration to build, which is why it is listed here
    | with `meta` as its family rather than as an unrelated platform.
    */
    'providers' => [

        'facebook' => [
            'name'        => 'Facebook Lead Ads',
            'description' => 'Leads from Facebook lead forms arrive here the moment somebody submits one.',
            'family'      => 'meta',
            'built'       => true,
        ],

        'instagram' => [
            'name'        => 'Instagram Lead Ads',
            'description' => 'Same Meta system as Facebook — a configuration change once Facebook is approved, not a separate build.',
            'family'      => 'meta',
            'built'       => false,
        ],

        'whatsapp' => [
            'name'        => 'WhatsApp Business',
            'description' => 'Enquiries from a WhatsApp Business catalogue or click-to-chat ad.',
            'family'      => 'whatsapp',
            'built'       => false,
        ],

        'website' => [
            'name'        => 'Website form',
            'description' => 'The enquiry form on the project website, posting straight into the CRM.',
            'family'      => 'website',
            'built'       => false,
        ],
    ],

    /*
     | What an incoming enquiry can end up as, and how each reads in the
     | activity log. The keys are what IntegrationEvent stores; nothing else
     | writes a result string.
     |
     | `duplicate` and `repeat_enquiry` are deliberately separate. The first is
     | Meta delivering the same leadgen_id twice — a retry, and nothing
     | happened. The second is a real person enquiring twice about the same
     | project from a different ad, which is worth a call and worth seeing.
     */
    'results' => [
        'created'        => ['label' => 'Lead created', 'tone' => 'good'],
        'duplicate'      => ['label' => 'Duplicate',    'tone' => 'muted'],
        'repeat_enquiry' => ['label' => 'Repeat enquiry', 'tone' => 'warn'],
        'failed'         => ['label' => 'Failed',       'tone' => 'bad'],
        // a lead from Meta's Lead Ads Testing Tool that could not be imported
        'test'           => ['label' => 'Meta test',    'tone' => 'muted'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta
    |--------------------------------------------------------------------------
    */
    'meta' => [
        /*
         | Pinned, never "latest". Meta deprecates a Graph version roughly every
         | two years and changes field shapes between them; a floating version
         | would move under a working integration without a deploy.
         */
        'graph_version' => env('META_GRAPH_VERSION', 'v21.0'),
        'graph_base'    => env('META_GRAPH_BASE', 'https://graph.facebook.com'),

        // Meta gives up on a delivery it considers slow, then retries it. The
        // webhook answers 200 and queues, so this only bounds the Graph call
        // the queued job makes.
        'timeout' => 15,

        /*
         | The lead-form field names this application understands.
         |
         | Meta does not fix these: a client builds the form in Ads Manager and
         | names the questions themselves, so `full_name` and `first_name` +
         | `last_name` are both common, and a form can carry anything else
         | besides. Anything not listed here is logged and dropped rather than
         | failing the lead — a lead with an unrecognised "preferred_bhk"
         | question is still a lead, and losing it to be strict would be the
         | wrong trade.
         */
        'field_aliases' => [
            'full_name'   => ['full_name', 'name', 'your_name'],
            'first_name'  => ['first_name', 'given_name'],
            'last_name'   => ['last_name', 'family_name', 'surname'],
            'phone'       => ['phone_number', 'phone', 'mobile_number', 'mobile'],
            'email'       => ['email', 'email_address'],
        ],
    ],

    /*
     | The activity log shows this many rows. Fifty is about two screens and
     | comfortably more than a busy day.
     */
    'log_limit' => 50,
];
