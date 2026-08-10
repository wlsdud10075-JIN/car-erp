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
        'warehouse_out_date' => '출고일',
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
        'purchase_registration_type' => '매입등록',
        'purchase_evidence_subtype' => '증빙유형',
        'is_dealer_purchase' => '매매상',
        'buyer_undecided' => '바이어 미정 매입',
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
        'cost_inspection' => '점검비',
        'cost_performance' => '성능비',
        'cost_repair' => '정비비용',
        'cost_advertising' => '광고비용',
        'parts_amount' => '부품',
        'purchase_vat_amount' => '매입세액',
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
        'advance_payment2' => '송금 수수료',   // 2026-05-28 — 구 '선수금2' → 송금 수수료(fee) 재용도화
        'savings_used' => '적립금 사용',

        // 수출통관
        'export_buyer_id' => '통관 바이어',
        'export_consignee_id' => '통관 컨사이니',
        'forwarding_company_id' => '포워딩사',
        'export_declaration_amount' => '면장금액',
        'shipping_date' => '선적일',
        'arrival_date' => '도착일자',
        // 2026-07-31 — 감사로그에 쌓이는데 매핑이 없어 영문으로 노출되던 것들.
        'eta_date' => '도착 예정일(ETA)',
        'c_no' => '컨테이너 번호',
        'savings_earned' => '적립금 적립액',
        'purchase_fee_holder' => '매도비 예금주',
        'purchase_fee_bank' => '매도비 은행',
        'purchase_fee_account' => '매도비 계좌번호',
        // 이벤트가 column_name 자리에 테이블명을 넣는 경우(purchase_payment_after_paid).
        'purchase_balance_payments' => '매입 잔금',
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
        // 감사 로그용 논리 라벨(실제 vehicles 컬럼 아님) — 관리자가 영문 없이 읽게.
        'unpaid_override_stage' => '미수 우회 단계',
        'unlock_reason' => '잠금해제 사유',
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
    'salesmen' => [
        'name' => '영업담당자명',
        'type' => '정산 분류',
        'per_unit_tier_enabled' => '차등 정산(tier) 적용',
        'is_active' => '활성 상태',
        'phone' => '전화번호',
        'email' => '이메일',
        'initials' => '이니셜',
        'memo' => '메모',
    ],

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
        'is_inherited' => '승계받은 바이어',
        'unsecured_limit_krw' => '무담보 한도',
        'inherited_from_salesman_id' => '승계 전 담당자',
        'inherited_at' => '승계일',
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

    // ─── cash_snapshots (통장 마감잔액 — 자금현황 입력 이력, 2026-07-31) ──────
    'cash_snapshots' => [
        'snapshot_date' => '기준일',
        'balance_krw' => '통장 잔액(원화)',
        'balance_usd' => '통장 잔액(달러)',
        'balance_eur' => '통장 잔액(유로)',
        'inventory_krw' => '재고(선적 전)',
        'receivable_krw' => '미수',
        'payable_krw' => '매입 미지급',
        'advance_krw' => '가수금(갚을 돈)',
        'auction_deposit_krw' => '경매 보증금',
        'advance_payment_krw' => '선수금(선적 전 수령)',
        'unsold_inventory_krw' => '미판매 재고',
        'fx_usd' => '적용 환율(달러)',
        'fx_eur' => '적용 환율(유로)',
        'entered_by' => '입력자',
    ],

    // ─── settings (기능 설정) ────────────────────────────────────────────
    'settings' => [
        'value' => '설정값',
        'lock_shipping_entry' => '선적 진입 락',
        'capital_principal' => '투입 원금',
    ],

    // ─── settlement_payout_batches (정산 지급 배치) ───────────────────────
    'settlement_payout_batches' => [
        'month' => '귀속월',
        'status' => '배치 상태',
        'amount' => '조정 금액',
        'total_payout' => '지급 총액',
        'settlement_count' => '정산 건수',
        'current_level' => '현재 승인 단계',
    ],

    // ─── user_delegations (휴가 대리 위임) ───────────────────────────────
    'user_delegations' => [
        'from_user_id' => '위임한 사람',
        'to_user_id' => '대리인',
        'is_active' => '위임 중',
        'ends_at' => '복귀 예정일',
        'reason' => '사유',
    ],

    // ─── signed_contracts (전자서명) ─────────────────────────────────────
    'signed_contracts' => [
        'status' => '서명 상태',
        'contract_no' => '계약번호',
        'recipient_email' => '수신 이메일',
        'revoked_at' => '무름 시각',
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
        'ForwardingInvoice' => '포워딩사 인보이스',
        'Salesman' => '영업담당자',
        'SavingsStatus' => '적립금',
        'ReceivableHistory' => '채권 이력',
        'InterVehicleTransfer' => '차량 간 이체',
        'ApprovalRequest' => '승인 요청',
        'User' => '사용자',
        'Country' => '국가',
        'Port' => '항구',
        // 2026-07-31 — 운영 감사로그에 실제로 쌓여 있는데 매핑이 없어 영문으로 노출되던 것들.
        'CashSnapshot' => '통장 잔액',
        'Setting' => '기능 설정',
        'SettlementPayoutBatch' => '정산 지급 배치',
        'SignedContract' => '전자서명 계약',
        'UserDelegation' => '업무 위임',
    ],

    // ─── audit_logs.action 한글 ─────────────────────────────────────────
    'actions' => [
        'created' => '생성',
        'updated' => '수정',
        'deleted' => '삭제',
        'restored' => '복원',
        'force_deleted' => '완전 삭제',
        // 커스텀 액션 — 최고관리자가 바로 알아보는 업무 용어로 (2026-07-09 한글화 보강, jin)
        'approved' => '승인',
        'rejected' => '반려',
        'ledger_field_unlocked' => '확정금액 수정 허용',
        'bulk_cost_applied' => '비용 일괄 기입',
        'lock_toggle_changed' => '락 켜기/끄기',
        'lock_threshold_changed' => '락 수치 변경',
        'unpaid_override_approved' => '미수 우회 승인',
        'forwarding_invoice_paid' => '운임 지급 청산',
        'forwarding_invoice_unpaid' => '운임 청산 취소',
        'payment_type_converted' => '결제 유형 변경',
        'payout_adjustment_added' => '정산 지급액 조정 추가',
        'payout_adjustment_removed' => '정산 지급액 조정 취소',
        'payout_approved_via_link' => '정산 지급 승인(카톡)',
        'payout_rejected_via_link' => '정산 지급 반려(카톡)',
        'inbound_purchase_sync' => '매입 자동 등록(board)',
        // 2026-07-31 — 운영 감사로그 실측으로 확인된 미매핑 액션(영문 노출분).
        'assistant_query' => '챗봇 질문',
        'purchase_gate_override' => '매입 게이트 예외 승인',
        'vehicle_deleted_with_reason' => '차량 삭제(사유 기재)',
        'capital_report_viewed' => '자금 보고서 열람',
        'purchase_payment_after_paid' => '지급완료 후 매입금 변경',
        'overpay_converted_to_savings' => '초과입금 → 적립금 전환',
        'bulk_shipping_date_applied' => '선적일·도착일 일괄 기입',
        'signing_session_revoked' => '전자서명 요청 무름',
        'cash_balance_entered' => '통장 잔액 입력',
        'capital_recaptured' => '자금현황 재계산',
        // 2026-08-07 — 휴가 대리 위임(담당 영업 스코프). 켜고 끄는 건 본인.
        'delegation_activated' => '담당 영업 위임 시작',
        'delegation_deactivated' => '담당 영업 위임 종료',
    ],

    /*
     * ─── 챗봇 질문 유형 (2026-07-31) ───────────────────────────────────
     * ⚠️ action='assistant_query' 인 감사로그는 column_name 에 **컬럼이 아니라 질문 유형(intent)** 을 넣는다
     *   (AssistantService::classify). auditable_type 은 User 라 vehicles/users 사전으로는 절대 안 풀린다.
     *   권한 밖 질문은 '{intent}(denied)' 로 저장되므로 접미사를 떼고 조회한다.
     */
    'assistant_intents' => [
        'guide' => '업무 가이드',
        'system_guide' => '시스템 안내',
        'capital_status' => '자금 현황',
        'break_even' => '손익분기',
        'sales_by_salesman' => '담당자별 매출',
        'receivable_by_salesman' => '담당자별 미수',
        'receivable_by_buyer' => '바이어별 미수',
        'receivable_summary' => '미수 요약',
    ],

    // ─── 감사 로그 '변경' 열 값 한글화 (2026-07-09 jin — 최고관리자가 값도 알아보게) ──────
    // 테이블.컬럼 별 enum 원문값 → 한글. 매핑 없으면 원문 그대로.
    'value_maps' => [
        'settlements' => [
            'settlement_status' => ['pending' => '대기', 'calculating' => '계산중', 'confirmed' => '확정', 'paid' => '지급'],
            'secondary_status' => ['pending' => '2차 대기', 'closed' => '2차 마감'],
            'settlement_type' => ['ratio' => '비율제(프리랜서)', 'per_unit' => '건당(사내직원)'],
        ],
        'final_payments' => [
            'type' => ['deposit_down' => '계약금', 'interim' => '중도금', 'advance_1' => '선수금1', 'fee' => '송금 수수료', 'balance' => '잔금'],
        ],
        'purchase_balance_payments' => [
            'type' => ['down' => '계약금', 'balance' => '잔금'],
        ],
        'savings_statuses' => [
            'transaction_type' => ['EARNED' => '적립', 'USED' => '사용', 'REFUND' => '반환', 'ADJUSTMENT' => '조정', 'CANCELLED' => '취소'],
        ],
        'users' => [
            'permission' => ['super' => '시스템관리자', 'admin' => '최고관리자', 'manager' => '업무관리자', 'user' => '일반사용자'],
            'type' => ['employee' => '사내직원', 'freelance' => '프리랜서'],
        ],
        'approval_requests' => [
            'status' => ['pending' => '대기', 'approved' => '승인', 'rejected' => '반려'],
        ],
        'vehicles' => [
            'sales_channel' => ['export' => '수출', 'heyman' => '헤이맨', 'carpul' => '카풀'],
            'shipping_method' => ['RORO' => 'RORO', 'CONTAINER' => '컨테이너'],
        ],
    ],

    // boolean 컬럼 (1/0 → 예/아니오) — 감사 로그 값 표시용
    'boolean_columns' => ['is_deregistered', 'is_export_cleared', 'dhl_request', 'is_active'],
];
