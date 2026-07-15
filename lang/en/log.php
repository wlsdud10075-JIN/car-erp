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

    // Alimtalk send log (2026-07-13)
    'at_title' => 'Alimtalk Send Log',
    'at_subtitle' => 'KakaoTalk alimtalk send & delivery results. :count total',
    'at_search' => 'Phone · template · vehicle no.',
    'at_all_status' => 'All send statuses',
    'at_all_report' => 'All delivery results',
    'at_only_attention' => 'Needs attention only',
    'at_empty' => 'No alimtalk send logs.',
    'at_ack' => 'Acknowledge',
    'at_ack_all' => 'Acknowledge all undelivered',
    'at_acked' => 'Acknowledged',
    'at_col' => [
        'time' => 'Sent at',
        'template' => 'Template',
        'phone' => 'Phone',
        'status' => 'Send',
        'report' => 'Delivery',
        'vehicle' => 'Vehicle',
        'detail' => 'Detail/Error',
    ],
    'at_status' => [
        'sent' => 'Accepted',
        'failed' => 'Failed',
        'skipped' => 'Skipped',
    ],
    'at_report' => [
        'delivered' => 'Delivered',
        'undelivered' => 'Undelivered',
        'pending' => 'Checking',
    ],

    // Buyer document mail send log (2026-07-15)
    'md_title' => 'Mail Send Log',
    'md_subtitle' => 'Records of vehicle documents mailed to buyers. :count total',
    'md_search' => 'Recipient email · subject · vehicle no. · sender',
    'md_all_status' => 'All send statuses',
    'md_all_channel' => 'All send channels',
    'md_empty' => 'No mail send logs.',
    'md_doc_unit' => '',
    'md_col' => [
        'time' => 'Sent at',
        'sender' => 'Sender',
        'vehicle' => 'Vehicle',
        'to' => 'Recipient',
        'channel' => 'Channel',
        'subject' => 'Subject',
        'documents' => 'Attachments',
        'status' => 'Send',
    ],
    'md_status' => [
        'sent' => 'Sent',
        'failed' => 'Failed',
    ],
    'md_channel' => [
        'gmail' => 'Gmail',
        'ses' => 'AWS SES',
    ],
];
