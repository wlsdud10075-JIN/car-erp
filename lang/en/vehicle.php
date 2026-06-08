<?php

// i18n — Vehicle management (erp/vehicles/index). List view. (slide panel keys added later)
return [
    'title' => 'Vehicles',
    'total' => ':count vehicles',
    'per_page' => ':count / page',
    'create_btn' => 'Add Vehicle',

    'search_placeholder' => 'Vehicle no. · brand · model · owner · export decl. no.',
    'search_btn' => 'Search',
    'all_salesmen' => 'All Salesmen',
    'all_buyers' => 'All Buyers',
    'filter_all' => 'All',

    'date_type' => [
        'purchase' => 'Purchase Date',
        'sale' => 'Sale Date',
        'shipping' => 'Shipping Date',
        'bl' => 'B/L Date',
    ],

    'columns' => 'Columns',
    'show_columns' => 'Visible Columns',
    'reset_defaults' => 'Reset to defaults',

    'col' => [
        'number' => 'Vehicle No.',
        'brand_model' => 'Brand/Model',
        'status' => 'Status',
        'purchase_date' => 'Purchased',
        'sale_date' => 'Sold',
        'shipping_date' => 'Shipped',
        'bl_issue_date' => 'B/L Date',
        'salesman' => 'Salesman',
        'buyer' => 'Buyer',
        'channel' => 'Channel',
        'currency_rate' => 'Currency/Rate',
        'purchase_price' => 'Buy Price',
        'sale_price' => 'Sell Price',
        'unpaid_amount' => 'Outstanding',
        'unpaid_ratio' => 'Paid %',
    ],

    'fully_paid' => 'Paid',
    'deleted' => 'Vehicle deleted.',
    'delete' => 'Delete',
    'delete_confirm' => 'Delete vehicle :number?',
    'empty' => 'No vehicles.',

    'shipdoc_select_title' => 'Multi-select for shipping docs (export vehicles)',
    'selected' => ':count selected',
    'max30' => 'Up to 30 vehicles per document',
    'clear_selection' => 'Clear selection',
    'shipdoc' => [
        'container_invoice_packing' => 'Container Invoice & Packing',
        'container_contract' => 'Container Contract',
        'roro_invoice_packing' => 'RORO Invoice & Packing',
        'roro_contract' => 'RORO Contract',
    ],
];
