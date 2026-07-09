<?php

// i18n — Log screens (document access log + audit log). Chrome only.
// ColumnLabel(model/column/action), config('column_labels'), DocumentAccessLog labels are DB technical labels, left untranslated.
return [
    // Document access log
    'doc_title' => 'Document Download Audit Log',
    'doc_subtitle' => 'PIPA §29 safeguard — access records for documents containing RRN. :count total',
    'doc_search' => 'Accessor · vehicle no.',
    'all_doc_types' => 'All document types',
    'doc_empty' => 'No access logs.',
    'doc_col' => [
        'time' => 'Time',
        'accessor' => 'Accessor',
        'vehicle' => 'Vehicle',
        'document' => 'Document',
        'ip' => 'IP',
    ],

    // Audit log
    'audit_title' => 'Audit Log',
    'audit_subtitle' => 'Change tracking (since queue 11-4 — earlier actions not recorded).',
    'audit_search' => 'Vehicle no. · actor name',
    'total' => ':count total',
    'all_users' => 'All users',
    'all_actions' => 'All actions',
    'all_columns' => 'All columns',
    'reset_filters' => 'Reset filters',
    'system' => 'System',
    'audit_empty_filtered' => 'No audit logs match the filters.',
    'audit_empty' => 'No audit logs.',
    'audit_col' => [
        'time' => 'Time',
        'user' => 'User',
        'target' => 'Target',
        'action' => 'Action',
        'column' => 'Column',
        'change' => 'Before → After',
        'ip' => 'IP',
        'approval' => 'Approval',
    ],
];
