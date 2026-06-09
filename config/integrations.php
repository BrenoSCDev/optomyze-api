<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Integration Webhooks
    |--------------------------------------------------------------------------
    |
    | Outbound N8N webhook URLs fired when key CRM entities are created.
    | Set these in your .env file. A null/empty value disables the webhook.
    |
    | Leads: both the entity webhook and the WhatsApp-specific webhook fire on
    | lead creation — the WhatsApp one is intended for sending an automated
    | message to the lead's phone number via N8N.
    |
    */

    'webhooks' => [
        'lead_created'         => env('N8N_WEBHOOK_LEAD_CREATED'),
        'lead_whatsapp'        => env('N8N_WEBHOOK_LEAD_WHATSAPP'),
        'deal_created'         => env('N8N_WEBHOOK_DEAL_CREATED'),
        'appointment_created'  => env('N8N_WEBHOOK_APPOINTMENT_CREATED'),
    ],

];
