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
    'type_ratio' => 'Freelance :ratio%',
    'type_per_unit' => 'Employee per-unit',
    'empty' => 'No batches.',
    'notify' => [
        'approved' => 'Approved.',
        'rejected' => 'Rejected. Settlements can be re-batched.',
        'reason_required' => 'Enter a reject reason.',
    ],

    // Monthly batch manual adjustment (jin 2026-07-08)
    'adjust' => [
        'readonly_hint' => 'Adjustments are fixed when the monthly batch is submitted from Settlements. To change them, reject this batch and submit again from Settlements.',
        'title' => 'Manual adjustment (clawback / special pay)',
        'reflected' => 'adj. applied',
    ],
];
