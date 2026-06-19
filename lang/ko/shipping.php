<?php

// 선적요청 화면 (board 영업포털 → car-erp). 배치별 묶음·상태 추적.
return [
    'title' => '선적요청',
    'subtitle' => '영업이 board에서 올린 선적 지시 — 요청 묶음별 상태 추적',
    'empty' => '선적요청이 없습니다.',

    'filter' => [
        'all' => '전체',
        'requested' => '요청',
        'in_progress' => '진행중',
        'done' => '완료',
    ],
    'status' => [
        'requested' => '요청',
        'in_progress' => '진행중',
        'done' => '완료',
    ],

    'requested_by' => '요청 영업',
    'vehicles_n' => ':n대',

    'action' => [
        'start' => '진행중으로',
        'done' => '완료 처리',
        'open_in_vehicles' => '차량관리에서 :count대 보기',
    ],

    'doc' => [
        'label' => '묶음 서류',
        'invoice_packing' => 'Invoice & Packing',
        'contract' => '계약서',
    ],

    'toast' => [
        'updated' => '선적요청 상태를 변경했습니다.',
    ],
];
