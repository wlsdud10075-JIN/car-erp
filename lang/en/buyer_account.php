<?php

// Buyer account (2026-09-05) — see docs/design/buyer-cash-ledger.md
// NOTE: deliberately not "settlement" — in this ERP that word means salesman payout.
return [
    'title' => 'Buyer account',
    'subtitle' => 'Where the buyer\'s money went, and what is still outstanding by shipment grouping.',

    'pick_buyer' => 'Buyer',
    'pick_placeholder' => 'Choose a buyer',
    'pick_first' => 'Choose a buyer to see cash on hand and outstanding amounts.',
    'no_buyers' => 'No buyer has outstanding amounts or remaining cash.',

    'cash_title' => 'Cash on hand (by currency)',
    'received' => 'Received',
    'allocated' => 'Applied to vehicles',
    'remaining' => 'Remaining',
    'no_cash' => 'No receipts recorded.',

    'usage_title' => 'How the cash was used',
    'usage_note' => 'Which vehicles each receipt was applied to. Always shown in full, regardless of the search — vehicles here may not appear under Outstanding (already fully paid from cash).',
    'not_used_yet' => 'Not applied yet.',
    'col_used_at' => 'Applied on',
    'col_used_amount' => 'Applied amount',
    'usage_more' => 'Show :count older receipts',
    'sort_unpaid_note' => 'Sorted in the vehicle currency (grouped by currency).',

    'unpaid_title' => 'Outstanding (by currency)',
    'no_unpaid' => 'Nothing outstanding.',
    'unpaid_note' => ':count vehicle(s) with an outstanding balance',

    'groups_title' => 'Outstanding by grouping',
    'unassigned' => 'Unassigned',
    'axis' => [
        'container' => 'Container no.',
        'declaration' => 'Export decl. no.',
        'bl' => 'B/L no.',
        'vessel' => 'Vessel',
    ],

    'vehicles_title' => 'Vehicles with an outstanding balance',
    'col_vehicle' => 'Vehicle',
    'col_vin' => 'VIN',
    'col_progress' => 'Stage',
    'col_total' => 'Total sale',
    'col_received' => 'Received',
    'col_unpaid' => 'Outstanding',
    'col_count' => 'Units',
    'col_vehicles' => 'Vehicles',

    'export' => 'Download Excel',
];
