<?php

return [
    // Default financial terms, copied onto each event when it is created.
    // Money is in cents; percentages are basis points (250 = 2.5%).
    // A currency with no schedule here cannot be used for new events until its
    // fees are decided (open multi-currency question). Platform Settings (#46a)
    // will later override these from the database.
    'fees' => [
        'CAD' => [
            'creation_fee_cents' => 10000,      // CAD 100.00
            'ticket_fee_flat_cents' => 150,     // CAD 1.50 per paid ticket
            'ticket_fee_bps' => 250,            // 2.5%
            'processing_fee_bps' => 300,        // 3%
            'minimum_charge_cents' => 50000,    // CAD 500.00
            'payout_delay_business_days' => 3,
        ],
    ],
];