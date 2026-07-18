<?php

// Phase 2 — monthly settlement payout batch approval queue i18n.
return [
    'title' => 'Monthly Payout Approval',
    'cancel_loss' => [
        'title' => 'Purchase cancellation loss (freelancer share)',
        'empty' => 'No unsettled cancellation losses in this period.',
        'salesman' => 'Salesperson',
        'vehicles' => 'Vehicles (share)',
        'subtotal' => 'Share subtotal',
        'grand_total' => 'Grand total',
        'prefill' => 'Fill adjustment',
        'settle' => 'Mark settled',
        'settle_confirm' => "Mark this salesperson's losses as settled and hide from the summary. Proceed?",
        'note' => 'Enter the subtotal (negative) as the batch adjustment, then click "Mark settled" ONLY after that batch is finally approved (do not mark if it is rejected — avoids losing the charge). "Mark settled" prevents double charging. Freelancers only (employee losses borne by the company).',
        'reason' => 'Purchase cancellation loss share: :plates',
        'pick_batch' => 'Click "Add adjustment" on the target batch first.',
        'settled' => 'Marked as settled.',
    ],
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
