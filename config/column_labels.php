<?php

/**
 * 회의확장씬 보강 (2026-05-23) — 새회의.txt #1 / 사용자 지적.
 *
 * 사용자 노출 컬럼 → 한글 라벨 매핑 사전.
 * - audit_logs UI: column_name 한글 표시
 * - validation 메시지: attribute 라벨 한글화 (lang/ko/validation.php 의 attributes 키)
 * - exception 메시지 등에서 라벨 필요한 곳도 활용 가능
 *
 * 운영 화면에 노출되는 핵심 컬럼만 매핑. 시스템 internal(timestamps, FK id 등)은 영문 그대로.
 *
 * 사용법:
 *   $label = config('column_labels.vehicles.sale_price'); // "판매가"
 *   $label = column_label('Vehicle', 'sale_price');       // helper (별도 정의 시)
 */

return [

    // ─── vehicles ───────────────────────────────────────────────────────
    'vehicles' => [
        'vehicle_number' => '차량번호',
        'brand' => '브랜드',
        'model_type' => '차종',
        'year' => '연식',
        'cc' => '배기량',
        'kg' => '중량',
        'sales_channel' => '판매 채널',
        'currency' => '통화',
        'exchange_rate' => '환율',
        'progress_status_cache' => '진행상태',
        'is_deregistered' => '말소완료',

        // 매입
        'purchase_date' => '매입일',
        'purchase_seller' => '매입처',
        'purchase_seller_bank' => '매입처 은행',
        'purchase_seller_account' => '매입처 계좌',
        'purchase_seller_holder' => '매입처 예금주',
        'purchase_bank_memo' => '매입 계좌 메모',
        'purchase_remittance_memo' => '송금 메모',
        'purchase_price' => '매입가',
        'selling_fee' => '매도비',
        'nice_reg_owner_name' => '소유자',
        'nice_reg_owner_rrn' => '주민(법인)등록번호',

        // 비용 9개
        'cost_deregistration' => '말소비',
        'cost_license' => '면허비',
        'cost_towing' => '탁송비',
        'cost_carry' => '캐리비',
        'cost_shoring' => '쇼링비',
        'cost_insurance' => '보험료',
        'cost_transfer' => '이전비',
        'cost_extra1' => '기타비1',
        'cost_extra2' => '기타비2',
        'deregistration_document' => '말소신청서',

        // 판매
        'sale_date' => '판매일',
        'buyer_id' => '바이어',
        'consignee_id' => '컨사이니',
        'sale_price' => '판매가',
        'tax_dc' => 'TAX D/C',
        'commission' => '커미션',
        'auto_loading' => '자동하역비',
        'transport_fee' => '운임비',
        'sale_other_costs' => '기타 판매비용',

        // 입금
        'deposit_down_payment' => '계약금 입금',
        'interim_payment' => '중도금',
        'advance_payment1' => '선수금1',
        'advance_payment2' => '선수금2',
        'savings_used' => '적립금 사용',

        // 수출통관
        'export_buyer_id' => '통관 바이어',
        'export_consignee_id' => '통관 컨사이니',
        'forwarding_company_id' => '포워딩사',
        'export_declaration_amount' => '면장금액',
        'shipping_date' => '선적일',
        'arrival_date' => '도착일자',
        'shipping_method' => '운송 방식',
        'port_of_loading' => 'Port of Loading',
        'discharge_port_id' => '도착 항구',
        'incoterms' => 'Incoterms',
        'export_declaration_document' => '수출신고서',
        'is_export_cleared' => '수출통관 완료',

        // 선적 / B/L
        'bl_buyer_id' => '선적 바이어',
        'bl_consignee_id' => '선적 컨사이니',
        'bl_number' => 'B/L번호',
        'container_no' => '컨테이너 No',
        'bl_loading_location' => '반입지',
        'bl_vsl' => 'VSL',
        'bl_document' => 'B/L 문서',
        'bl_issue_date' => 'B/L발행일',

        // DHL
        'dhl_recipient_name' => 'DHL 수취인',
        'dhl_sender_name' => 'DHL 발송인',
        'dhl_weight' => 'DHL 중량',
        'dhl_size' => 'DHL 크기',
        'dhl_request' => 'DHL 발송 신청',

        // 담당
        'salesman_id' => '영업담당자',
        'receivable_manager_id' => '채권 담당자',
        'progress_status_rule_version' => '진행상태 규칙 버전',
    ],

    // ─── final_payments / purchase_balance_payments ─────────────────────
    'final_payments' => [
        'amount' => '잔금',
        'exchange_rate' => '입금 시점 환율',
        'amount_krw' => 'KRW 환산',
        'payment_date' => '입금일',
        'note' => '비고',
        'type' => '구분',
        'confirmed_at' => '재무 확정 시각',
        'confirmed_by_user_id' => '재무 확정자',
        'finance_note' => '재무 메모',
        'transfer_id' => '차량 간 이체',
    ],

    'purchase_balance_payments' => [
        'amount' => '매입 잔금',
        'payment_date' => '지급일',
        'note' => '비고',
        'type' => '구분',
        'confirmed_at' => '재무 확정 시각',
        'finance_note' => '재무 메모',
    ],

    // ─── settlements ────────────────────────────────────────────────────
    'settlements' => [
        'settlement_type' => '정산 방식',
        'settlement_ratio' => '정산 비율',
        'per_unit_amount' => '건당 금액',
        'other_deduction' => '기타 공제',
        'settlement_status' => '정산 상태',
        'secondary_status' => '2차 정산 상태',
        'exchange_difference_krw' => '환차익',
        'exchange_rate_at_close' => '2차 정산 환율',
        'confirmed_at' => '확정 시각',
        'paid_at' => '지급 시각',
        'secondary_closed_at' => '2차 마감 시각',
        'salesman_id' => '영업담당자',
        'note' => '비고',
    ],

    // ─── buyers / consignees ────────────────────────────────────────────
    'buyers' => [
        'name' => '바이어명',
        'country_id' => '국가',
        'salesman_id' => '영업담당자',
        'contact_name' => '담당자명',
        'contact_email' => '이메일',
        'contact_phone' => '전화번호',
        'address' => '주소',
        'memo' => '메모',
        'is_active' => '활성 상태',
    ],

    'consignees' => [
        'name' => '컨사이니명',
        'country_id' => '국가',
        'id_type' => 'ID 종류',
        'id_value' => 'ID 번호',
        'contact_name' => '담당자명',
        'contact_email' => '이메일',
        'contact_phone' => '전화번호',
        'address' => '주소',
        'memo' => '메모',
    ],

    // ─── savings_statuses ───────────────────────────────────────────────
    'savings_statuses' => [
        'currency' => '통화',
        'transaction_type' => '거래 유형',
        'savings' => '적립금 변동액',
        'balance' => '잔액',
        'note' => '메모',
    ],

    // ─── users ──────────────────────────────────────────────────────────
    'users' => [
        'name' => '이름',
        'email' => '이메일',
        'permission' => '권한',
        'role' => '역할',
        'manager_user_id' => '담당 관리자',
        'type' => '유형',
        'is_active' => '활성 상태',
    ],

    // ─── approval_requests ──────────────────────────────────────────────
    'approval_requests' => [
        'action_type' => '액션 유형',
        'status' => '승인 상태',
        'reason' => '사유',
        'decision_note' => '결정 사유',
        'requested_at' => '요청 시각',
        'decided_at' => '결정 시각',
    ],

    // ─── 모델 클래스명 → 한글 ─────────────────────────────────────────────
    // audit_logs.auditable_type 표시에 사용
    'models' => [
        'Vehicle' => '차량',
        'FinalPayment' => '판매 잔금',
        'PurchaseBalancePayment' => '매입 잔금',
        'Settlement' => '정산',
        'Buyer' => '바이어',
        'Consignee' => '컨사이니',
        'ForwardingCompany' => '포워딩사',
        'Salesman' => '영업담당자',
        'SavingsStatus' => '적립금',
        'ReceivableHistory' => '채권 이력',
        'InterVehicleTransfer' => '차량 간 이체',
        'ApprovalRequest' => '승인 요청',
        'User' => '사용자',
        'Country' => '국가',
        'Port' => '항구',
    ],

    // ─── audit_logs.action 한글 ─────────────────────────────────────────
    'actions' => [
        'created' => '생성',
        'updated' => '수정',
        'deleted' => '삭제',
        'restored' => '복원',
        'force_deleted' => '완전 삭제',
    ],
];
