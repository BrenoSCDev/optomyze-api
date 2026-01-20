<?php

return [

    'channels' => [
        'email' => true,
        'whatsapp' => true,
        'in_app' => true,
    ],

    'default_recipients' => [
        'deal_owner' => true,
        'lead_owner' => true,
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
    ],

    'quiet_hours' => [
        'enabled' => false,
        'from' => null,
        'to' => null,
    ],
];
