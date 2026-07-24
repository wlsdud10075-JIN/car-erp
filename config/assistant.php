<?php

/*
|--------------------------------------------------------------------------
| 사내 업무 도우미(로컬 LLM 챗봇) 설정 — jin 2026-07-24
|--------------------------------------------------------------------------
| 완전 로컬(Ollama). 데이터 외부 전송 0. board PoC(C:\Users\User\llm-poc) 계승.
|
| ⭐ 이식성: 전부 .env 기반 — 회사 GPU PC로 옮길 때 .env 값만 바꾸면 됨.
|   하드코딩·머신 특정값 없음.
|   - ASSISTANT_ENABLED     기능 on/off (미설정 시 위젯 비노출)
|   - ASSISTANT_OLLAMA_URL  Ollama 엔드포인트 (로컬=http://localhost:11434,
|                           프로덕션=WireGuard 터널 주소)
|   - ASSISTANT_LLM_MODEL   대화 모델 (qwen3:8b)
|   - ASSISTANT_EMB_MODEL   임베딩 모델 (bge-m3)
|   - ASSISTANT_INDEX_PATH  Notion 업무가이드 색인(index.json) 절대경로
|                           (board PoC sync.php 생성물)
*/

return [
    'enabled' => filter_var(env('ASSISTANT_ENABLED', false), FILTER_VALIDATE_BOOL),

    'ollama' => rtrim(env('ASSISTANT_OLLAMA_URL', 'http://localhost:11434'), '/'),
    'llm_model' => env('ASSISTANT_LLM_MODEL', 'qwen3:8b'),
    'emb_model' => env('ASSISTANT_EMB_MODEL', 'bge-m3'),

    // A단계(업무가이드 RAG) 색인 파일. 없으면 A는 "가이드 색인이 없습니다" 안내.
    'index_path' => env('ASSISTANT_INDEX_PATH', ''),
    'rag_topk' => (int) env('ASSISTANT_RAG_TOPK', 3),

    'timeout' => (int) env('ASSISTANT_TIMEOUT', 180),
];
