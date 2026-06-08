<?php

// i18n — Approval queue (erp/approvals). Model labels (action_label/status_label/ApprovalRequest::TYPES) left untranslated (model layer).
return [
    'title' => 'Approval Queue',
    'subtitle' => ':count pending · 6 actions combined (same-buyer outstanding · settlement payout · sensitive action · 50% rule exception · fund transfer · transfer void)',
    'all_actions' => 'All actions',

    'filter' => [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'all' => 'All',
    ],
    'col' => [
        'created' => 'Created',
        'action' => 'Action',
        'requester' => 'Requester',
        'target' => 'Target',
        'reason' => 'Reason',
        'status' => 'Status',
        'decider' => 'Decider',
        'handle' => 'Handle',
    ],
    't' => [
        'source' => 'Source',
        'target' => 'Target',
        'vehicle' => 'Vehicle',
        'buyer' => 'Buyer #:id',
        'unassigned' => '(unassigned)',
        'overlap' => ':count outstanding ₩:amount',
        'overlap_vehicles' => 'Outstanding vehicles:',
        'void' => '⊘ Void transfer #:id',
        'restore' => 'reversal',
    ],
    'tstatus' => [
        'approved_awaiting' => 'Approved (awaiting finance)',
        'voided_awaiting' => 'Void approved (awaiting finance)',
        'void_rejected' => 'Finance void rejected',
        'executed' => 'Transfer complete',
        'voided' => 'Transfer voided',
        'finance_rejected' => 'Finance rejected',
    ],

    'approve' => 'Approve',
    'reject' => 'Reject',
    'handled' => 'Handled',
    'empty' => 'No approval requests match the filter.',

    'modal' => [
        'approve_title' => 'Confirm Approval',
        'reject_title' => 'Enter Rejection Reason',
        'approve_desc' => 'Approve this request? The reason is optional.',
        'reject_desc' => 'A rejection reason of 5+ characters is required.',
        'memo_ph' => 'Memo (optional)',
        'reject_ph' => 'Rejection reason (required)',
    ],

    'toast' => [
        'no_perm' => 'No approval permission.',
        'already' => 'Already handled or no longer exists.',
        'self' => 'You cannot handle a request you submitted (SoD violation).',
        'reject_min' => 'Enter a rejection reason of 5+ characters.',
        'fail' => 'Failed: :error',
        'done_approve' => 'Approved + action executed.',
        'done_reject' => 'Rejected.',
    ],
];
