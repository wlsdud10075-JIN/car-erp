<?php

return [
    'title' => 'Morning check',
    'no_record' => 'no record',
    'none' => 'none',
    'stale' => ':date (no update for :days days)',
    'count' => ':n',

    'job_failed' => 'Scheduled job failed',
    'job_recovered' => 'Scheduled job recovered',
    'job_no_reason' => 'No reason was recorded.',
    'item' => [
        'exchange' => 'Closing rates',
        'alimtalk_sent' => 'Alerts sent',
        'alimtalk_failed' => 'Undelivered',
        'holidays' => 'Holiday sync',
        'db_backup' => 'Database backup',
        'assistant_index' => 'Assistant index',
    ],

    'why' => [
        'exchange' => 'Rates are not being updated, so sale balances autofill with a stale rate.',
        'alimtalk_sent' => 'No alerts have gone out for a while. Check the recipient settings and the sending account.',
        'alimtalk_failed' => 'Some alerts were sent but never delivered. Check the alert log for the reason.',
        'holidays' => 'The holiday list is not being updated. The API key may have expired.',
        'db_backup' => 'There is no backup file from today. Nothing to restore from.',
        'assistant_index' => 'The assistant reference material is not being updated.',
    ],
];
