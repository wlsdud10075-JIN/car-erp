<?php

// i18n — Port master (admin/ports). Shared in common.php.
return [
    'title' => 'Ports',
    'subtitle' => 'CIPL dropdown options — :count total',
    'create_btn' => 'Add Port',
    'search_ph' => 'Name · code',
    'all_types' => 'All types',
    'empty' => 'No ports.',
    'deactivate' => 'Deactivate',
    'activate' => 'Activate',
    'edit_title' => 'Edit Port',
    'create_title' => 'Add Port',

    'col' => [
        'type' => 'Type',
        'name' => 'Port Name',
        'code' => 'Code',
    ],
    'field' => [
        'type' => 'Type',
        'name' => 'Port Name',
        'name_ph' => 'e.g. BUSAN, KOREA / Pyeongtaek',
        'code' => 'Code',
        'code_ph' => 'e.g. 020-77-002',
        'code_note' => 'Berth code (number in parentheses) — leave empty if none.',
        'active_note' => 'Active (shown in dropdowns)',
        'allow_shipping_wait' => 'Allow shipping-wait route',
        'allow_shipping_wait_note' => 'RORO vehicles bound for this discharge port bypass the clearance/shipping 50% entry gate without an override (port-wait paperwork). Receivable stays pre-shipping until a warehouse-out date is entered.',
    ],

    'badge' => [
        'shipping_wait' => 'Shipping-wait',
    ],

    'type' => [
        'loading' => 'Port of Loading',
        'unloading' => 'Loading Location (KR berth)',
        'discharge' => 'Discharge Port',
    ],

    'dup' => 'A port with the same name already exists in this type.',
    'saved' => 'Port saved.',
    'activated' => 'Activated',
    'deactivated' => 'Deactivated',
];
