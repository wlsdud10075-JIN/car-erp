<?php

// i18n — Work / user dashboard (erp/dashboard).
return [
    'view_label' => [
        '영업' => 'My Sales Tasks',
        '수출통관' => 'My Export Clearance Tasks',
        '재무' => 'My Finance Tasks',
        '관리' => 'My Management Tasks',
    ],

    'mode_by_salesman' => 'By Salesman',
    'mode_by_role' => 'By Role',
    'salesman' => 'Salesman',
    'all' => 'All',

    'salesman_missing_title' => 'No salesman linked to this account',
    'salesman_missing_sub' => 'Ask an administrator to link a salesman to your account.',

    'fx_title' => 'Live Exchange Rates',
    'fx_sub' => '(T/T buying · per KRW · 1h cache)',
    'fx_refresh' => 'Refresh',
    'fx_fail' => '⚠ Live rate lookup failed — enter manually when adding balance payments.',
    'fx_refreshed' => 'Exchange rates refreshed',

    'actions_title' => 'Action Items',
    'empty' => [
        '수출통관' => ['title' => 'No pending items', 'sub' => 'Clearance / shipping / DHL flow is clear'],
        '재무' => ['title' => 'No collection or finance items', 'sub' => 'All receivables / settlements are clear'],
        'default' => ['title' => 'No action items', 'sub' => 'Everything is up to date'],
    ],

    'active_title' => 'Active Vehicles',
    'view_all' => 'View all →',
    'no_active' => 'No active vehicles.',
    'none' => 'None',
    'unit_vehicle' => '',
    'unit_count' => '',
    'per_page' => ':count',
    'subtitle_only' => ':name only',
    'subtitle_all' => 'All vehicles',

    'col' => [
        'number' => 'Vehicle No.',
        'salesman' => 'Salesman',
        'status' => 'Status',
        'next' => 'Next Action',
        'purchase_date' => 'Purchased',
        'unpaid_purchase' => 'Unpaid (Buy)',
        'unpaid_sale' => 'Unpaid (Sell)',
    ],

    'month_label' => ':nn',

    'kpi' => [
        'sales' => [
            'active' => ['l' => 'In Progress', 'h' => 'excluding completed'],
            'month_buy' => ['l' => 'Purchases (Month)', 'h' => 'by :month purchases'],
            'month_sale' => ['l' => 'Sales Registered (Month)', 'h' => ':month sales registered (regardless of payment)'],
            'month_done' => ['l' => 'Completed (Month)', 'h' => 'by :month completion'],
        ],
        'clearance' => [
            'derg_wait' => ['l' => 'Deregistration Pending', 'h' => 'purchase paid → not deregistered'],
            'clr_req_wait' => ['l' => 'Clearance Request Pending', 'h' => 'sale fully paid → license not uploaded'],
            'decl_wait' => ['l' => 'Export Declaration Pending', 'h' => 'clearing'],
            'ship_wait' => ['l' => 'Shipment Pending', 'h' => 'license done → loading location empty'],
            'dhl_wait' => ['l' => 'DHL Dispatch Pending', 'h' => 'B/L issued → not requested'],
        ],
        'settlement' => [
            'pur_unpaid' => ['l' => 'Total Unpaid Purchases', 'h' => 'sum of in-progress purchase balances'],
            'sale_unpaid' => ['l' => 'Total Unpaid Sales', 'h' => 'vehicles with exchange rate only'],
            'wait' => ['l' => 'Settlement Pending', 'h' => 'pending status'],
            'fx_missing' => ['l' => 'FX Rate Missing', 'h' => 'foreign sale → no rate'],
            'blocked' => ['l' => 'Settlement Blocked (Unpaid)', 'h' => 'completed but unpaid → collect :amount to settle'],
        ],
        'management' => [
            'appr_wait' => ['l' => 'Approvals Pending', 'h' => '4 actions combined (enabled in queue 14-3)'],
            'settle_wait' => ['l' => 'Settlement Pending', 'h' => 'pending + confirmed'],
            'risk' => ['l' => 'Receivable Risk', 'h' => 'danger / critical grade'],
            'clr_stuck' => ['l' => 'Clearance Stuck', 'h' => 'sold 30+ days → license not uploaded'],
        ],
    ],

    'act' => [
        'sales' => [
            'purchase_unpaid' => ['l' => 'Unpaid Purchases', 'd' => 'purchase price set, balance unpaid'],
            'sale_unpaid' => ['l' => 'Unpaid Sales', 'd' => 'sold, amount not yet collected'],
            'clearance_needed' => ['l' => 'Clearance Request Needed', 'd' => 'sale fully paid → license not uploaded'],
            'shipping_needed' => ['l' => 'Shipment Needed', 'd' => 'clearance done → B/L not processed'],
            'dhl_needed' => ['l' => 'DHL Dispatch Pending', 'd' => 'loaded → DHL not requested'],
            'settlement_wait' => ['l' => 'Settlement Pending', 'd' => 'method not set or needs review'],
        ],
        'clearance' => [
            'deregistration_needed' => ['l' => 'Deregistration Needed', 'd' => 'purchase done → not deregistered'],
            'clearance_request_needed' => ['l' => 'Clearance Request Needed', 'd' => 'sale fully paid → license not uploaded'],
            'clearance_info_missing' => ['l' => 'Clearance Buyer/Date Missing', 'd' => 'sale started → export_buyer or shipping_date missing'],
            'eta_missing' => ['l' => 'Data Fix — ETA Missing', 'd' => 'shipped but arrival date empty → fill it to schedule the clearance alarm'],
            'forwarding_missing' => ['l' => 'Forwarder Not Set', 'd' => 'clearance started → no forwarder'],
            'export_declaration_upload_needed' => ['l' => 'Export Declaration Upload', 'd' => 'clearing → no declaration'],
            'shipping_process_needed' => ['l' => 'Shipment Needed', 'd' => 'sold → loading location empty'],
            'bl_upload_needed' => ['l' => 'B/L Upload Needed', 'd' => 'clearance done → B/L not uploaded'],
            'dhl_dispatch_needed' => ['l' => 'DHL Dispatch Pending', 'd' => 'completed → not requested'],
        ],
        'settlement' => [
            'purchase_unpaid' => ['l' => 'Unpaid Purchases', 'd' => 'purchase price set → balance unpaid'],
            'sale_unpaid' => ['l' => 'Unpaid Sales', 'd' => 'sold → amount outstanding'],
            'exchange_rate_missing' => ['l' => 'FX Rate Needed', 'd' => 'foreign sale → rate not entered'],
            'settlement_create_needed' => ['l' => 'Settlement Creation Needed', 'd' => 'completed → no settlement'],
            'settlement_confirm_needed' => ['l' => 'Settlement Confirmation Needed', 'd' => 'settlement = pending'],
            'settlement_pay_needed' => ['l' => 'Settlement Payment Needed', 'd' => 'settlement = confirmed'],
            'receivable_risk' => ['l' => 'Receivable Risk', 'd' => 'danger / critical grade'],
        ],
        'management' => [
            'approval_wait' => ['l' => 'Approval Items', 'd' => 'to be enabled in queue 14-3'],
            'settlement_confirm_needed' => ['l' => 'Settlement Confirmation Needed', 'd' => 'settlement = pending → paid after approval'],
            'settlement_pay_needed' => ['l' => 'Settlement Payment Needed', 'd' => 'settlement = confirmed → paid after approval'],
            'receivable_risk' => ['l' => 'Receivable Risk', 'd' => 'danger/critical → review credit limit'],
            'clearance_stuck' => ['l' => 'Clearance Stuck', 'd' => 'sold 30+ days → check progress'],
        ],
    ],
];
