<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>자동차말소등록신청서 — {{ $vehicle->vehicle_number }}</title>
    <style>
        @include('documents._korean_fonts')

        * {
            font-family: 'NotoSansKR', sans-serif;
        }
        @page {
            margin: 14mm 12mm;
        }
        body {
            font-size: 9pt;
            line-height: 1.4;
            color: #000;
            margin: 0;
        }
        .form-meta {
            font-size: 8pt;
            margin-bottom: 4px;
        }
        .form-meta .right {
            float: right;
        }
        h1.title {
            font-size: 18pt;
            font-weight: bold;
            text-align: center;
            margin: 6px 0 10px 0;
            letter-spacing: 4px;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
        }
        table.form td,
        table.form th {
            border: 1px solid #000;
            padding: 4px 6px;
            vertical-align: middle;
            font-size: 9pt;
            word-wrap: break-word;
        }
        table.form th {
            background: #f3f3f3;
            font-weight: normal;
            text-align: center;
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
        .reasons td {
            font-size: 8.5pt;
            padding: 3px 6px;
        }
        .checkbox {
            font-family: 'NotoSansKR', sans-serif;
        }
        .applicant-section {
            margin-top: 14px;
            font-size: 9.5pt;
        }
        .applicant-section p {
            margin: 0 0 6px 0;
        }
        .date-line {
            text-align: center;
            margin: 18px 0 14px 0;
            font-size: 10pt;
        }
        .signer-block {
            margin-top: 8px;
            text-align: right;
            line-height: 1.8;
        }
        .signer-block .row {
            display: block;
        }
        .receiver {
            margin-top: 14px;
            text-align: center;
            font-size: 10pt;
            font-weight: bold;
        }
        .poa-title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin: 26px 0 6px 0;
            letter-spacing: 12px;
        }
        .poa-body {
            text-align: center;
            margin: 0 0 10px 0;
            font-size: 9pt;
        }
        .stamp-cell {
            text-align: center;
            font-size: 8.5pt;
            color: #555;
        }
        .small {
            font-size: 8pt;
        }
        .nowrap { white-space: nowrap; }
    </style>
</head>
<body>

<div class="form-meta">
    ■ 자동차등록규칙 [별지 제17호서식]
    <span class="right">(앞 쪽)</span>
</div>

<h1 class="title">자동차말소등록신청서</h1>

{{-- 접수번호 / 접수일 / 발급일 / 처리기간 --}}
<table class="form">
    <tr>
        <td class="label" style="width:14%;">접수번호</td>
        <td style="width:22%;">&nbsp;</td>
        <td class="label" style="width:12%;">접수일</td>
        <td style="width:18%;">&nbsp;</td>
        <td class="label" style="width:12%;">발급일</td>
        <td style="width:10%;">&nbsp;</td>
        <td class="label" style="width:12%;">처리기간</td>
        <td style="width:10%; text-align:center;">즉시</td>
    </tr>
</table>

{{-- 소유자 --}}
<table class="form" style="margin-top:4px;">
    <tr>
        <td class="label-vert" rowspan="3" style="width:6%;">소유자</td>
        <td class="label" style="width:20%;">성명(명칭)</td>
        <td colspan="3">{{ $vehicle->nice_reg_owner_name ?: '' }}</td>
    </tr>
    <tr>
        <td class="label">주민(법인)등록번호</td>
        <td style="width:38%;">&nbsp;</td>
        <td class="label" style="width:14%;">전화번호</td>
        <td>&nbsp;</td>
    </tr>
    <tr>
        <td class="label">사용본거지(차고지)</td>
        <td colspan="3">{{ $vehicle->nice_reg_owner_addr ?: '' }}</td>
    </tr>
</table>

{{-- 차량 정보 --}}
<table class="form" style="margin-top:4px;">
    <tr>
        <td class="label" style="width:20%;">자동차등록번호</td>
        <td class="label" style="width:36%;">차대번호</td>
        <td class="label" style="width:24%;">주행거리</td>
    </tr>
    <tr>
        <td style="text-align:center;">{{ $vehicle->vehicle_number ?: '' }}</td>
        <td style="text-align:center;">{{ $vehicle->nice_reg_vin ?: '' }}</td>
        <td style="text-align:center;">{{ $vehicle->mileage ? number_format($vehicle->mileage).' km' : '' }}</td>
    </tr>
</table>

{{-- 말소등록의 원인 --}}
<table class="form reasons" style="margin-top:4px;">
    <tr>
        <td class="label-vert" rowspan="6" style="width:6%;">말소등록의<br>원인</td>
        <td colspan="2">[ ] 폐차(용도폐지, 「여객자동차 운수사업법」에 따른 차령 초과 등)</td>
    </tr>
    <tr>
        <td style="width:50%;">[<strong>v</strong>] 수출 예정 &nbsp;&nbsp; [ ] 도난</td>
        <td>[ ] 천재지변ㆍ교통사고ㆍ화재ㆍ폭파ㆍ매몰 등의 사고</td>
    </tr>
    <tr>
        <td>[ ] 압류등록된 차량으로서 차령 초과</td>
        <td>[ ] 연구ㆍ시험 사용 목적</td>
    </tr>
    <tr>
        <td>[ ] 사고 원인의 규명 등 특수용도 사용 목적</td>
        <td>[ ] 섬지역에서의 해체</td>
    </tr>
    <tr>
        <td>[ ] 외교용 또는 SOFA차량으로서 내국민에게 양도</td>
        <td>[ ] 도로 외의 지역에서의 한정사용 목적</td>
    </tr>
    <tr>
        <td>[ ] 그 밖에 국토교통부장관이 인정하는 사유</td>
        <td>[ ] 멸실 사실을 인정받은 경우</td>
    </tr>
</table>

{{-- 말소사실 증명서 --}}
<table class="form" style="margin-top:4px;">
    <tr>
        <td class="label-vert" rowspan="2" style="width:6%;">말소사실<br>증명서</td>
        <td style="width:47%;">[<strong>V</strong>] 발급 필요</td>
        <td>[ ] 발급 불필요</td>
    </tr>
</table>

<div class="applicant-section">
    <p>「자동차관리법」 제13조제1항, 「자동차등록령」 제31조제1항 및 「자동차등록규칙」 제37조에 따라 자동차말소등록을 신청합니다.</p>
</div>

<div class="date-line">{{ $today }}</div>

<div class="signer-block">
    <span class="row">주소 :&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
    <span class="row">신청인 &nbsp; 성명 :&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp; (서명 또는 인)</span>
    <span class="row">주민등록번호 :&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;</span>
</div>

<div class="receiver">
    특별시장ㆍ광역시장ㆍ도지사ㆍ특별자치도지사 또는 시장ㆍ군수ㆍ구청장 귀하
</div>

{{-- 위임장 --}}
<div class="poa-title">위 임 장</div>
<div class="poa-body">위 자동차의 말소등록에 따른 모든 행위를 수임자(신청인)에게 위임한다</div>

<table class="form">
    <tr>
        <td class="label-vert" rowspan="3" style="width:8%;">위임자</td>
        <td class="label" style="width:14%;">주소</td>
        <td>경기도 시흥시 산기대학로 163, 328호(정왕동)</td>
        <td class="stamp-cell" rowspan="3" style="width:14%;">인감날인</td>
    </tr>
    <tr>
        <td class="label">성명</td>
        <td>주식회사 싼카</td>
    </tr>
    <tr>
        <td class="label">사업자번호</td>
        <td>662-81-00898</td>
    </tr>
    <tr>
        <td class="label-vert" rowspan="3" style="width:8%;">수임자</td>
        <td class="label">주소</td>
        <td>&nbsp;</td>
        <td class="stamp-cell" rowspan="3">&nbsp;</td>
    </tr>
    <tr>
        <td class="label">성명</td>
        <td>&nbsp;</td>
    </tr>
    <tr>
        <td class="label">사업자번호</td>
        <td>&nbsp;</td>
    </tr>
</table>

</body>
</html>
