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
    'resend' => [
        'btn' => 'Resend request',
        'btn_wait' => 'Resend in :min min',
        'hint' => 'Sends the approval request message again to the approver at the current step (with a fresh link). The batch itself does not change.',
        'confirm' => 'Send the approval request to :role again?',
        'done' => 'Approval request sent again to :role.',
        'wait' => 'Just sent — you can resend in :min minute(s).',
        'not_pending' => 'Only a batch awaiting approval can be resent.',
        'forbidden' => 'Only payout-batch submitters and the system administrator can resend.',
        'last_sent' => 'Last approval request :at (:ago)',
        'delivery' => [
            'delivered' => 'Delivered',
            'sent' => 'Sent · not yet confirmed',
            'failed' => 'Failed',
            'skipped' => 'Not sent (settings)',
        ],
    ],
    'adjust' => [
        'readonly_hint' => 'Adjustments are fixed when the monthly batch is submitted from Settlements. To change them, reject this batch and submit again from Settlements.',
        'title' => 'Manual adjustment (clawback / special pay)',
        'reflected' => 'adj. applied',
    ],

    // Margin rate / base salary / monthly take-home (jin 2026-09-18) — display only.
    'margin' => [
        'label' => 'Margin rate',
        'batch_total' => 'Whole batch',
        'none' => '—',
        'hint' => 'Total margin divided by sales amount in KRW. Domestic vehicles have no margin rate, so they show a dash and are left out of the subtotal.',
        'pay' => [
            'base_salary' => 'Base salary',
            'settlement' => 'Settlement',
            'take_home' => 'Monthly take-home',
            'deposit' => 'Deposit held',
            'base_salary_total' => 'Base salary total',
            'expected_transfer' => 'Expected transfer this month',
            'expected_hint' => 'Payout total plus employee base salaries (people in this batch, plus active salaried employees with no settlement this month). It is shown so the whole outgoing amount can be seen at once, and never enters approval or company profit.',
            'salary_only' => 'No settlement · base salary only',
        ],
    ],
];
