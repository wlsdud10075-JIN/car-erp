<?php

// Shipping requests screen (board sales portal -> car-erp). Batch grouping / status tracking.
return [
    'title' => 'Shipping Requests',
    'subtitle' => 'Shipping instructions submitted by sales from board — tracked per request batch',
    'empty' => 'No shipping requests.',

    'filter' => [
        'active' => 'To do',
        'all' => 'All',
        'requested' => 'Requested',
        'in_progress' => 'In progress',
        'done' => 'Done',
    ],
    'search_ph' => 'Buyer · consignee · vehicle no.',
    'empty_search' => 'No results.',
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
        'tab_hint' => 'Bundles awaiting 2nd settlement (one month after completion). Grouped by settlement attribution month (e.g. May batch → paid 6/10), matching the settlement screen. License fee arrives as one lump per shipment and is split n/1 across the vehicles. Management sees only their team bundles.',
        'pay_label' => 'paid',
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
        'entry_locked' => 'Start locked',
        'entry_locked_tip' => 'Vehicles under 50% paid: :vehicles — need full payment / manager approval, or remove them and re-bundle',
        'done' => 'Mark done',
        'cancel' => 'Cancel',
        'open_in_vehicles' => 'Open :count in Vehicles',
        'download_dereg' => 'Download :count Deregistration Docs',
        'no_dereg' => 'No deregistration docs uploaded in this bundle',
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
        'bl_issued' => 'B/L no · vessel bulk-applied to bundle vehicles.',
        'decl_applied' => 'Export declaration number bulk-applied to :count bundle vehicles.',
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
        'issue' => 'Enter B/L no · vessel',
        'issue_title' => 'B/L no · vessel — bulk apply to :n vehicles',
        'field_type' => 'B/L type',
        'field_number' => 'B/L No',
        'field_container' => 'Container No',
        'field_vessel' => 'Vessel (VSL)',
        'apply' => 'Bulk apply',
        'cancel' => 'Close',
        'not_fully_paid' => 'Fully-paid required before entry (bundle has unpaid)',
        'blocked_vehicles' => 'B/L blocked — unpaid vehicles: :vehicles. Pay 100% or get manager B/L approval before issuing.',
        'requested_hint' => 'Sales-requested type',
        'guard_mismatch' => '⚠ Double-guard — sales requested :req but :cur is selected. Verify before uploading the B/L document.',
    ],

    // 🔒 (나)+(a) shipping entry lock — bundle start blocked
    'lock' => [
        'entry_blocked' => 'Cannot start shipping — vehicles under 50% paid: :vehicles. Pay 50%+ or get manager approval. (Whole bundle waits — to proceed, remove the unpaid vehicles and re-bundle.)',
    ],

    // Export declaration number bulk apply (one shared number → whole bundle)
    'decl' => [
        'enter' => 'Enter export decl. no',
        'title' => 'Export declaration no — bulk apply to :n vehicles',
        'field_number' => 'Export declaration no',
        'placeholder' => 'e.g. 12345-67-890123X',
        'hint' => 'The same number is applied to every vehicle in the bundle.',
        'apply' => 'Bulk apply',
        'cancel' => 'Close',
        'invalid' => 'Enter an export declaration number.',
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
