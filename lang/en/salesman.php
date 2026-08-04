<?php

// i18n — Salesman management (erp/salesmen/index). Shared in common.php.
return [
    'title' => 'Salesmen',
    'total' => ':count people',
    'create_btn' => 'Add Salesman',
    'search_ph' => 'Name · email · phone',
    'empty' => 'No salesmen.',
    'cashflow' => 'Cashflow',
    'cashflow_view' => 'View cashflow',
    'carryover_badge' => 'Unconsumed carryover',
    'delete_confirm' => 'Delete salesman :name?',
    'saved' => 'Salesman saved.',
    'deleted' => 'Salesman deleted.',
    'edit_title' => 'Edit Salesman',
    'create_title' => 'Add Salesman',

    'col' => [
        'name' => 'Name',
        'account' => 'Linked Account',
    ],
    'field' => [
        'name' => 'Name',
        'name_ph' => 'e.g. John Kim',
        'initials' => 'Initials',
        'initials_ph' => 'e.g. JK',
        'initials_note' => 'Used as Proforma Invoice No. prefix — {initials}MU{VIN digits}',
        'account' => 'Linked Account',
        'account_none' => '-- Not linked --',
        'linked_none' => 'Not linked',
        'settlement_type' => 'Settlement Type',
        'type_unset' => 'Not set — enter in User management',
        'per_unit_tier' => 'Apply tiered settlement',
        'per_unit_tier_hint' => 'When on: 200,000 KRW for total margin ≥ 1M, or 25% of total margin when purchase total ≥ 100M. When off: flat 100,000 KRW per unit (0 for loss-making vehicles). Inherited-buyer deals are always 50,000 KRW regardless of this setting.',
        'type_no_account' => 'No login account linked',
    ],

    'master_banner' => 'Name/email/settlement type are changed in :link. This screen is for supplementary info (phone/memo/active) only.',
    'type_note' => 'Settlement type is changed in :link (role=Sales)',
    'users_link' => 'User Management',

    'type' => [
        'employee' => 'Employee',
        'freelance' => 'Freelancer',
    ],
    'type_suffix' => [
        'employee' => '(per-unit)',
        'freelance' => '(ratio)',
    ],
];
