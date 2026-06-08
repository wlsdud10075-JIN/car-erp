<?php

// i18n — Receivables (erp/receivables). progress=domain.progress, role=domain.role reused.
return [
    'title' => 'Receivables',
    'subtitle' => 'Outstanding balance · collection history · risk monitoring',
    'admin_only' => 'Access: managers only',
    'forbidden' => 'You do not have permission to access Receivables.',

    'tab' => [
        'all' => 'All',
        'before_shipping' => 'Pre-shipment unpaid',
        'after_shipping' => 'Post-shipment unpaid',
        'deposit' => 'Deposit (savings used)',
    ],

    'kpi' => [
        'total_sale' => 'Total sales (KRW)',
        'total_paid' => 'Total received',
        'total_unpaid' => 'Total outstanding',
        'risk_count' => 'At-risk count (danger + critical)',
    ],
    'unit_won' => 'KRW',
    'unit_count' => '',

    'search_ph' => 'Search plate · brand',
    'all_salesman' => 'All salesmen',
    'all_buyer' => 'All buyers',
    'all_progress' => 'All statuses',
    'all_risk' => 'All risk levels',
    'ratio_all' => 'Unpaid % ALL',
    'ratio_min' => ':percent%+',

    'risk' => [
        'safe' => 'Safe',
        'caution' => 'Caution',
        'danger' => 'Danger',
        'critical' => 'Critical',
    ],

    'col' => [
        'no' => 'No.',
        'vehicle_no' => 'Plate',
        'salesman' => 'Salesman',
        'buyer' => 'Buyer',
        'sale_total' => 'Sale total',
        'unpaid' => 'Unpaid',
        'unpaid_ratio' => 'Unpaid %',
        'progress' => 'Status',
        'bl' => 'BL',
        'risk' => 'Risk',
        'manager' => 'AR manager',
    ],
    'empty' => 'No vehicles found.',
    'buyer_none' => 'Buyer unassigned',
    'mobile_unpaid' => 'Unpaid',

    // Slide panel (collection history)
    'history' => 'Collection History',
    'manager_section' => 'AR Manager',
    'manager_unassigned' => 'Unassigned',
    'assign' => 'Assign',
    'manager_assigned' => 'AR manager assigned.',
    'form_title_edit' => 'Edit Collection',
    'form_title_add' => 'Add Collection',

    'field' => [
        'date' => 'Collection date',
        'collector' => 'Collector',
        'method' => 'Method',
        'amount' => 'Amount',
        'amount_attr' => 'Collection amount',
        'memo' => 'Memo',
        'select' => 'Select',
    ],
    'method' => [
        'deposit' => 'Deposit',
        'cash' => 'Cash',
        'offset' => 'Offset',
        'other' => 'Other',
    ],
    'method_deposit_full' => 'Deposit (auto-creates final_payment)',
    'memo_ph' => 'Collection notes · contact log, etc.',
    'btn_edit_save' => 'Save changes',
    'btn_add' => 'Add',
    'saved_edit' => 'Collection updated.',
    'saved_add' => 'Collection added.',
    'deleted' => 'Collection deleted.',
    'list_title' => 'Collection history (latest first)',
    'list_empty' => 'No collection history.',
    'mirror_title' => 'Mirrored with final_payments',
    'delete_confirm' => 'Delete this collection record? The mirrored payment record will also be deleted.',
];
