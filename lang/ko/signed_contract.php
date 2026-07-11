<?php

return [
    'request_btn' => '전자서명 요청',
    'modal' => [
        'hint' => '아래 서명 링크를 바이어에게 카카오톡·이메일 등으로 전달하세요. 바이어가 링크에서 계약서를 확인하고 서명합니다.',
        'copy' => '복사',
        'expire' => '링크는 7일 후 만료됩니다. 다시 발급하면 이전 링크는 무효화됩니다.',
    ],
    'notify' => [
        'delete_locked' => '서명 완료된 전자계약은 삭제할 수 없습니다. (법적 증거물)',
        'issued' => '전자서명 링크를 발급했습니다. 아래 링크를 바이어에게 전달하세요.',
        'scope_denied' => '접근 권한이 없는 차량이 포함되어 있습니다.',
    ],
    'issue' => [
        'empty' => '차량을 선택하세요.',
        'too_many' => '한 계약서에 최대 :max대까지 가능합니다.',
        'export_only' => '수출 채널 차량에서만 전자서명을 발급할 수 있습니다.',
        'mixed_buyer' => '동일 바이어의 차량만 함께 발급할 수 있습니다.',
        'mixed_currency' => '동일 통화의 차량만 함께 발급할 수 있습니다.',
        'no_buyer' => '바이어가 지정된 차량만 발급할 수 있습니다.',
    ],
    'sign' => [
        'invalid' => '서명 이미지가 올바르지 않습니다. 다시 서명해 주세요. · Invalid signature, please sign again.',
    ],
    'mail' => [
        'subject' => '[전자서명 확인 / Signed] :no',
        'body' => "판매계약서(:no)에 대한 전자서명이 완료되었습니다.\n서명본(PDF)을 첨부합니다. 본 메일은 전자서명 확인 및 전달 증빙입니다.\n\nYour electronic signature for sales contract :no has been completed.\nThe signed copy (PDF) is attached. This email serves as confirmation and delivery evidence.",
    ],
];
