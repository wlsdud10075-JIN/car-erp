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
        '선적중' => 'Enter shipping date',
        '선적완료' => 'Enter ETA',
        '통관중' => 'Complete clearance',
        '통관완료' => 'Upload B/L',
        '수출통관중' => 'Upload export license',
        '수출통관완료' => 'Process shipment',
        '거래완료' => 'Send DHL',
        'none' => '-',
    ],

    // 진행상태 뜻 — pill·뱃지 호버(title). 조건이 바뀌면 Vehicle::getProgressStatusAttribute 와 같이 고칠 것 (2026-10-20).
    'progress_hint' => [
        '매입중' => 'No purchase price yet or purchase balance still owed (the filter shows every vehicle with an unpaid purchase)',
        '매입완료' => 'Purchase balance fully paid',
        '말소완료' => 'Deregistration checked + certificate (the filter shows every vehicle with a deregistration date, except completed)',
        '판매중' => 'Sale price entered, payment still outstanding',
        '판매완료' => 'Sale fully paid',
        '선적중' => 'Loading location or export declaration entered',
        '선적완료' => 'Shipping date entered (vessel booked)',
        '통관중' => 'Shipping date + ETA entered — shown with the In transit badge',
        '통관완료' => 'Export clearance checked',
        '거래완료' => 'B/L uploaded (or FOB forwarder + fully paid, or cancellation penalty paid)',
        '수출통관중' => 'Legacy (v3) stage',
        '수출통관완료' => 'Legacy (v3) stage',
    ],

    // role (stored / middleware comparison value stays the Korean key — display only).
    'role' => [
        '영업' => 'Sales',
        '수출통관' => 'Export Clearance',
        '재무' => 'Finance',
        '관리' => 'Management',
        '검차' => 'Inspector',
    ],

    // Sales channel (sales_channel enum — value is English code, display only).
    'channel' => [
        'export' => 'Export',
        'heyman' => 'Heyman',
        'carpul' => 'Carpul',
    ],

    // Sailing phase display labels. Key = Vehicle::SAILING_* constant (stored / compared value).
    'sailing' => [
        '운항중' => 'In transit',
        '도착예정' => 'Due to arrive',
    ],
    'sailing_hint' => 'Vehicles that have both a sailing date and an ETA - based on the estimated date',
    'sailing_hint_on' => ' - click again to clear',
];
