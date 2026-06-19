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
    ],
];
