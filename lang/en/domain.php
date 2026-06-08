<?php

// i18n — shared domain labels (reused across screens).
// ⚠️ Keys are the Korean internal identifiers. Only the value (display) is translated.
// progress_status_cache, filters and match() comparisons keep using the Korean key.
return [
    'pipeline_title' => 'Vehicle Pipeline',

    // Vehicle progress status display labels. Key = Korean progress status.
    'progress' => [
        '매입중' => 'Purchasing',
        '매입완료' => 'Purchased',
        '말소완료' => 'Deregistered',
        '판매중' => 'Selling',
        '판매완료' => 'Sold',
        '선적중' => 'Loading',
        '선적완료' => 'Loaded',
        '통관중' => 'Clearing',
        '통관완료' => 'Cleared',
        '거래완료' => 'Completed',
        // v3 grandfather
        '수출통관중' => 'Clearing',
        '수출통관완료' => 'Cleared',
    ],

    // "Next action" label per progress status.
    'next_action' => [
        '매입중' => 'Enter purchase info',
        '매입완료' => 'Deregister',
        '말소완료' => 'Register sale',
        '판매중' => 'Confirm payment',
        '판매완료' => 'Enter loading location',
        '선적중' => 'Upload export declaration',
        '선적완료' => 'Complete clearance',
        '통관중' => 'Upload B/L',
        '통관완료' => 'Upload B/L',
        '수출통관중' => 'Upload export license',
        '수출통관완료' => 'Process shipment',
        '거래완료' => 'Send DHL',
        'none' => '-',
    ],

    // role (stored / middleware comparison value stays the Korean key — display only).
    'role' => [
        '영업' => 'Sales',
        '수출통관' => 'Export Clearance',
        '재무' => 'Finance',
        '관리' => 'Management',
    ],

    // Sales channel (sales_channel enum — value is English code, display only).
    'channel' => [
        'export' => 'Export',
        'heyman' => 'Heyman',
        'carpul' => 'Carpul',
    ],
];
