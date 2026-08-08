<?php

return [

    'channels' => [
        'email' => true,
        'whatsapp' => true,
        'in_app' => true,
    ],

    'default_recipients' => [
        'admin'        => true,
        'deal_owner'   => true,
        'lead_owner'   => true,
        'team_manager' => true,
    ],

    'events' => [
        'lead_created' => [
            'enabled' => true,
            'priority' => 'normal',
        ],
        'lead_assigned' => [
            'enabled' => true,
            'priority' => 'normal',
        ],
        'deal_created' => [
            'enabled' => true,
            'priority' => 'normal',
        ],
        'deal_stage_changed' => [
            'enabled' => true,
            'priority' => 'high',
        ],
        'deal_won' => [
            'enabled' => true,
            'priority' => 'high',
        ],
        'deal_lost' => [
            'enabled' => true,
            'priority' => 'high',
        ],
        'product_added' => [
            'enabled' => false,
            'priority' => 'low',
        ],
        'appointment_scheduled' => [
            'enabled' => true,
            'priority' => 'normal',
        ],

        /*
         * Document lifecycle.
         *
         * Only the outcomes nobody in the CRM can see coming are on by
         * default — the counterparty signing, refusing, or letting the
         * deadline pass. `document_sent` is off because the person who would
         * be notified is the person who just clicked Send.
         */
        'document_signed' => [
            'enabled' => true,
            'priority' => 'high',
        ],
        'document_rejected' => [
            'enabled' => true,
            'priority' => 'high',
        ],
        'document_expired' => [
            'enabled' => true,
            'priority' => 'high',
        ],
        'document_sent' => [
            'enabled' => false,
            'priority' => 'low',
        ],
    ],

    'quiet_hours' => [
        'enabled' => false,
        'from' => null,
        'to' => null,
    ],
];
