<?php

return [
    'title' => 'Morning check',
    'no_record' => 'no record',
    'none' => 'none',
    'stale' => ':date (no update for :days days)',
    'count' => ':n',
    'board_stalled' => 'stalled :s · missing in ERP :m',
    'board_integrity' => 'synced without ERP id :a · ERP id without synced :b',
    'board_audit_failed' => 'ERP check failed (:err)',
    'board_deleted_note' => '(deleted in ERP: :d — info)',

    'job_failed' => 'Scheduled job failed',
    'job_recovered' => 'Scheduled job recovered',
    'job_no_reason' => 'No reason was recorded.',
    'deploy_failed' => 'Deploy failed',
    'deploy_failed_why' => 'The new code did not reach the server. Read the run log, fix, and push again. If the site is still in maintenance mode, check artisan up on the server.',
    'item' => [
        'exchange' => 'Closing rates',
        'alimtalk_sent' => 'Alerts sent',
        'alimtalk_failed' => 'Undelivered',
        'holidays' => 'Holiday sync',
        'db_backup' => 'Database backup',
        'assistant_index' => 'Assistant index',
        'board_sync_stalled' => 'board→ERP sync',
        'board_sync_integrity' => 'board→ERP consistency',
    ],

    'why' => [
        'exchange' => 'Rates are not being updated, so sale balances autofill with a stale rate.',
        'alimtalk_sent' => 'No alerts have gone out for a while. Check the recipient settings and the sending account.',
        'alimtalk_failed' => 'Some alerts were sent but never delivered. Check the alert log for the reason.',
        'holidays' => 'The holiday list is not being updated. The API key may have expired.',
        'db_backup' => 'There is no backup file from today. Nothing to restore from.',
        'assistant_index' => 'The assistant reference material is not being updated.',
        'board_sync_stalled' => 'Some won listings never reached the ERP. Check the board transfer errors. (Vehicles deleted in the ERP after transfer are not failures; they appear as an info count.)',
        'board_sync_integrity' => 'Board and ERP disagree on transfer state for some vehicles. A vehicle may have been created twice or its id is wrong.',
    ],
];
