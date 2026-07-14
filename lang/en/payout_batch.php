<?php

// Phase 2 — monthly settlement payout batch approval queue i18n.
return [
    'title' => 'Monthly Payout Approval',
    'subtitle' => 'Bundle a month of confirmed settlements through the approval ladder (Manager → Representative)',
    'status' => [
        'pending' => 'Pending',
        'approved' => 'Approved · Paid',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
    ],
    'count' => ':n items',
    'submitter' => 'Submitter',
    'next_level' => 'Awaiting :role',
    'confirm_approve' => 'Approve this batch? (If final Representative approval, all settlements are paid immediately)',
    'approve' => 'Approve',
    'reject' => 'Reject',
    'reject_reason_ph' => 'Reject reason (required)',
    'reject_confirm' => 'Reject',
    'rejected_reason' => 'Reject reason: :reason',
    'no_salesman' => 'No salesman',
    'empty' => 'No batches.',
    'notify' => [
        'approved' => 'Approved.',
        'rejected' => 'Rejected. Settlements can be re-batched.',
        'reason_required' => 'Enter a reject reason.',
    ],

    // Monthly batch manual adjustment (jin 2026-07-08)
    'adjust' => [
        'title' => 'Manual adjustment (clawback / special pay)',
        'salesman' => 'Salesman',
        'amount' => 'Amount (- for negative)',
        'reason' => 'Reason (required)',
        'add' => 'Add',
        'add_line' => 'Add adjustment',
        'hint' => 'Negative = clawback/deduction, positive = special pay. Reflected in the salesman subtotal and the batch total (the underlying settlements are untouched). Approved together with the batch.',
        'invalid' => 'Enter salesman, a non-zero amount, and a reason.',
        'added' => 'Adjustment added.',
        'removed' => 'Adjustment removed.',
        'reflected' => 'adj. applied',
    ],
];
