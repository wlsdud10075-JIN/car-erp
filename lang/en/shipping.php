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

    'tab' => [
        'shipping' => 'Shipping / B/L',
        'cost' => '2nd-stage Cost (License)',
    ],

    // 2nd-stage cost tab — license fee n/1 across bundle
    'license' => [
        'tab_hint' => 'Bundles awaiting 2nd settlement (one month after completion). License fee arrives as one lump per shipment and is split n/1 across the vehicles. Management sees only their team bundles.',
        'empty' => 'No bundles awaiting 2nd settlement.',
        'batch_n' => ':n bundles',
        'not_entered_n' => ':n unfilled',
        'badge_not_entered' => 'License unfilled',
        'badge_entered' => 'Filled',
        'enter_btn' => 'License n/1',
        'form_title' => 'License n/1 — split across :n',
        'total_label' => 'License total',
        'preview' => ':n × :each',
        'preview_hint' => 'Enter the total to see the n/1 preview.',
        'apply_btn' => 'Apply',
        'invalid_total' => 'Enter a valid license total.',
        'applied' => 'License fee entered for :count vehicle(s) via n/1',
        'applied_partial' => 'License entered for :ok, :skip skipped (permission/unmatched)',
    ],

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
        'guard_mismatch' => '⚠ Double-guard — sales requested :req but :cur is selected. Verify before uploading the B/L document.',
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
