<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>자동차 등록증 재발급 신청서 — {{ $vehicle->vehicle_number }}</title>
    <style>
        @include('documents._korean_fonts')

        * { font-family: 'NotoSansKR', sans-serif; }
        @page { margin: 12mm 12mm; }
        body {
            font-size: 9pt;
            line-height: 1.4;
            color: #000;
            margin: 0;
        }
        .form-meta {
            font-size: 7pt;
            margin-bottom: 4px;
        }
        .form-meta .right {
            float: right;
        }
        h1.title {
            font-size: 16pt;
            font-weight: bold;
            text-align: center;
            margin: 6px 0 8px 0;
            letter-spacing: 3px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
        }
        table.form td {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: middle;
            font-size: 9pt;
            word-wrap: break-word;
        }
        .label {
            background: #f3f3f3;
            text-align: center;
            font-weight: normal;
        }
        .label-vert {
            background: #f3f3f3;
            text-align: center;
            font-weight: normal;
            font-size: 8.5pt;
        }
        .reason-row td {
            font-size: 8.5pt;
        }
        .applicant-line {
            margin: 14px 0 4px 0;
            font-size: 9.5pt;
        }
        .date-line {
            text-align: center;
            margin: 14px 0 6px 0;
            font-size: 9.5pt;
        }
        .signer-block {
            margin-top: 4px;
            line-height: 1.7;
            font-size: 9pt;
        }
        .signer-block .row {
            display: block;
            text-align: right;
        }
        .receiver {
            margin-top: 14px;
            text-align: center;
            font-size: 11pt;
            font-weight: bold;
            letter-spacing: 6px;
        }
        .notes {
            margin-top: 14px;
            font-size: 8pt;
            line-height: 1.5;
        }
        .notes .note-row {
            margin: 2px 0;
        }
        .footer-meta {
            margin-top: 12px;
            text-align: center;
            font-size: 7pt;
            color: #666;
        }
    </style>
</head>
<body>

<div class="form-meta">
    ■ 자동차등록규칙 [별지 제17호서식] &lt;개정 2017. 10. 26.&gt; &nbsp;&nbsp; 민원24(www.minwon.go.kr)에서도 신청할 수 있습니다.
</div>

<h1 class="title">자동차 등록증 재발급 신청서</h1>

{{-- 접수번호 / 접수일시 / 발급일시 / 처리기간 --}}
<table class="form">
    <tr>
        <td class="label" style="width:11%;">접수 번호</td>
        <td style="width:14%;">&nbsp;</td>
        <td class="label" style="width:11%;">접수 일시</td>
        <td style="width:14%;">&nbsp;</td>
        <td class="label" style="width:11%;">발급 일시</td>
        <td style="width:14%;">&nbsp;</td>
        <td class="label" style="width:11%;">처리 기간</td>
        <td style="width:14%; text-align:center;">즉시</td>
    </tr>
</table>

{{-- 소유자 --}}
<table class="form" style="margin-top:4px;">
    <tr>
        <td class="label-vert" rowspan="3" style="width:6%;">소유자</td>
        <td class="label" style="width:18%;">성명(명칭)</td>
        <td style="width:30%;">{{ $vehicle->nice_reg_owner_name ?: '' }}</td>
        <td class="label" style="width:22%;">주민등록번호<br>(법인등록번호)</td>
        <td>{{ $vehicle->nice_reg_owner_rrn ?: '' }}</td>
    </tr>
    <tr>
        <td class="label">주소</td>
        <td colspan="3">{{ $vehicle->nice_reg_owner_addr ?: '' }}</td>
    </tr>
</table>

{{-- 자동차 정보 --}}
<table class="form" style="margin-top:4px;">
    <tr>
        <td class="label" style="width:30%;">자동차<br>등록 번호</td>
        <td style="text-align:center; width:34%;">{{ $vehicle->vehicle_number ?: '' }}</td>
        <td style="text-align:center; width:4%;">/</td>
        <td style="text-align:center;">{{ $vehicle->nice_reg_vin ?: '' }}</td>
    </tr>
</table>

{{-- 재발급 사유 --}}
<table class="form" style="margin-top:4px;">
    <tr class="reason-row">
        <td class="label" style="width:18%;">재발급 사유</td>
        <td>[ <strong>v</strong> ] 분실ㆍ멸실</td>
        <td>[ &nbsp; ] 훼손</td>
        <td>[ &nbsp; ] 등록증에 변경사항 기재란 부족</td>
    </tr>
    <tr class="reason-row">
        <td class="label">분실 사유</td>
        <td colspan="3">분실</td>
    </tr>
</table>

<div class="date-line">{{ $today }}</div>

<div class="signer-block">
    <span class="row">신청인 성명 :&nbsp;&nbsp; <strong>{{ $vehicle->nice_reg_owner_name ?: '' }}</strong> &nbsp;&nbsp; (서명 또는 인)</span>
    <span class="row">생년월일 :&nbsp;&nbsp; {{ $vehicle->nice_reg_owner_rrn ?: '' }}</span>
</div>

<div class="receiver">시 흥 시 장 &nbsp;&nbsp; 귀하</div>

{{-- 첨부서류 / 수수료 --}}
<table class="form" style="margin-top:14px;">
    <tr>
        <td class="label" style="width:18%;">첨부서류</td>
        <td>&nbsp;</td>
        <td class="label" style="width:10%;">수수료</td>
        <td style="width:24%;">700원<br>(전자민원창구는 600원)</td>
    </tr>
</table>

<div class="notes">
    <div class="note-row"><strong>유의사항</strong></div>
    <div class="note-row">※ 신청인이 자동차의 공동 소유자 중 한 명이고 다른 공동 소유자의 신분을 확인할 수 있는 주민등록증, 운전면허증 또는 외국인등록사실증명 등 신분증명서의 사본을 제출하는 경우에는 공동 소유자의 위임장 없이도 신청할 수 있습니다.</div>
</div>

<div class="footer-meta">210mm × 297mm [백상지 또는 중질지 80g/㎡]</div>

</body>
</html>
