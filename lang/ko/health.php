<?php

// 매일 아침 점검 요약 (텔레그램) — jin 2026-09-11.
// 🚫 한글 문장에 영문을 섞지 말 것. 시스템관리자가 읽지만 어려운 말을 쓸 이유는 없다.
return [
    'title' => '아침 점검',
    'no_record' => '기록 없음',
    'none' => '없음',
    'stale' => ':date (:days일째 갱신 없음)',
    'count' => ':n건',
    'board_stalled' => '정체 :s건 · ERP 미도착 :m건',
    'board_integrity' => '완료인데 ERP 번호 없음 :a건 · ERP 번호 있는데 미완료 :b건',
    'board_audit_failed' => 'ERP 대조 실패 (:err)',
    'board_deleted_note' => '(ERP 에서 지운 차 :d건 — 참고)',

    'job_failed' => '정기 작업 실패',
    'job_recovered' => '정기 작업 복구',
    'job_no_reason' => '사유가 기록되지 않았습니다.',
    'deploy_failed' => '배포 실패',
    'deploy_failed_why' => '서버에 새 코드가 올라가지 않았습니다. 런 로그를 보고 고친 뒤 다시 push 하세요. 점검모드로 남아 있으면 서버에서 artisan up 을 확인하세요.',
    'item' => [
        'exchange' => '마감환율',
        'alimtalk_sent' => '알림톡 발송',
        'alimtalk_failed' => '도착 실패',
        'holidays' => '공휴일 수집',
        'db_backup' => '데이터 백업',
        'assistant_index' => '챗봇 자료',
        'board_sync_stalled' => 'board→ERP 전송',
        'board_sync_integrity' => 'board→ERP 정합성',
    ],

    // X 일 때만 붙는 한 줄 — 「그래서 무슨 일이 생기나」를 적는다. 숫자만 보면 사람은 안 움직인다.
    'why' => [
        'exchange' => '환율이 갱신되지 않아 판매 잔금에 옛 환율이 그대로 들어갑니다.',
        'alimtalk_sent' => '알림톡이 한동안 나가지 않았습니다. 수신자 설정과 발송 계정을 확인하세요.',
        'alimtalk_failed' => '보냈지만 도착하지 않은 알림톡이 있습니다. 알림톡 로그에서 사유를 확인하세요.',
        'holidays' => '공휴일 목록이 갱신되지 않았습니다. 인증키 사용 기간이 끝났을 수 있습니다.',
        'db_backup' => '오늘 백업 파일이 없습니다. 되돌려야 할 때 쓸 것이 없습니다.',
        'assistant_index' => '챗봇이 참고하는 자료가 갱신되지 않았습니다.',
        'board_sync_stalled' => '낙찰됐는데 ERP 로 넘어가지 않은 차가 있습니다. board 매입내역의 전송 오류를 확인하세요. (넘어간 뒤 ERP 에서 지운 차는 실패가 아니라 참고 건수로만 붙습니다.)',
        'board_sync_integrity' => 'board 와 ERP 의 전송 상태가 서로 맞지 않는 차가 있습니다. 같은 차가 두 번 만들어졌거나 번호가 어긋났을 수 있습니다.',
    ],
];
