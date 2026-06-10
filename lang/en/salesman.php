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
        'account' => 'Linked Account',
        'account_none' => '-- Not linked --',
        'linked_none' => 'Not linked',
        'settlement_type' => 'Settlement Type',
        'type_unset' => 'Not set — enter in User management',
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
