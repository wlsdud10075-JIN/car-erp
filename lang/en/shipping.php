<?php

// Shipping requests screen (board sales portal -> car-erp). Batch grouping / status tracking.
return [
    'title' => 'Shipping Requests',
    'subtitle' => 'Shipping instructions submitted by sales from board — tracked per request batch',
    'empty' => 'No shipping requests.',

    'filter' => [
        'all' => 'All',
        'requested' => 'Requested',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ],
    'status' => [
        'requested' => 'Requested',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ],

    'requested_by' => 'Requested by',
    'vehicles_n' => ':n vehicles',

    'action' => [
        'start' => 'Mark in progress',
        'done' => 'Mark done',
        'cancel' => 'Cancel',
        'open_in_vehicles' => 'Open :count in Vehicles',
    ],

    'confirm' => [
        'cancel' => 'Cancel this shipping request (:n vehicles)? Sales can re-request from board. Proceed?',
    ],

    'doc' => [
        'label' => 'Batch documents',
        'invoice_packing' => 'Invoice & Packing',
        'contract' => 'Contract',
    ],

    'toast' => [
        'updated' => 'Shipping request status updated.',
        'cancelled' => 'Shipping request cancelled.',
        'bl_issued' => 'B/L issued (bulk-applied) to bundle vehicles.',
        'change_accepted' => 'Change request accepted; bundle released for re-planning by sales.',
        'change_rejected' => 'Change request rejected.',
    ],

    'bl' => [
        'status_requested' => 'B/L requested',
        'status_issued' => 'B/L issued',
        'type' => [
            'original' => 'Original',
            'surrender' => 'Surrender',
        ],
        'issue' => 'Issue B/L',
        'issue_title' => 'Issue B/L — bulk apply to :n vehicles',
        'field_type' => 'B/L type',
        'field_number' => 'B/L No',
        'field_container' => 'Container No',
        'field_vessel' => 'VSL',
        'apply' => 'Issue (bulk apply)',
        'cancel' => 'Close',
        'not_fully_paid' => 'Fully-paid required before issuing (bundle has unpaid)',
        'requested_hint' => 'Sales-requested type',
    ],

    'fin' => [
        'fully_paid' => 'Paid',
        'unpaid' => 'Unpaid',
        'fx_missing' => ':n missing FX',
        'surrender_warning' => '⚠ Surrender + unpaid — title leverage released',
    ],

    'change' => [
        'flag' => 'Change requested',
        'accept' => 'Accept (release)',
        'reject' => 'Reject',
        'confirm_accept' => 'Accepting releases this bundle for sales to re-plan. Proceed?',
    ],
];
