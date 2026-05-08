<!DOCTYPE html>
<html lang="ko">
<head>
    <meta charset="UTF-8">
    <title>자동차양도증명서 — {{ $vehicle->vehicle_number }}</title>
    <style>
        @include('documents._korean_fonts')

        * { font-family: 'NotoSansKR', sans-serif; }
        @page { margin: 8mm 10mm; }
        body {
            font-size: 7.5pt;
            line-height: 1.3;
            color: #000;
            margin: 0;
        }
        .form-meta {
            font-size: 7pt;
            margin-bottom: 2px;
        }
        .form-meta .right {
            float: right;
            font-weight: bold;
        }
        h1.title {
            font-size: 13pt;
            font-weight: bold;
            text-align: center;
            margin: 4px 0 6px 0;
            letter-spacing: 1px;
        }
        h2.section {
            font-size: 10.5pt;
            font-weight: bold;
            text-align: center;
            margin: 10px 0 4px 0;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
        }
        table.form td {
            border: 1px solid #000;
            padding: 2px 4px;
            vertical-align: middle;
            font-size: 7.5pt;
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
            font-size: 7pt;
        }
        .small {
            font-size: 7pt;
            color: #555;
        }
        .stamp-cell {
            text-align: center;
            font-size: 7pt;
            color: #555;
        }
        .terms {
            font-size: 6.5pt;
            line-height: 1.4;
            margin-top: 6px;
        }
        .terms p { margin: 1px 0; }
        .common-notes {
            font-size: 7pt;
            margin-top: 4px;
        }
        .common-notes .note-title {
            font-weight: bold;
            margin-top: 4px;
        }
        .common-notes ul {
            margin: 2px 0 4px 16px;
            padding: 0;
        }
        .common-notes li { margin: 1px 0; }
        .signature-row {
            margin-top: 6px;
            padding-top: 4px;
            border-top: 1px solid #000;
            font-size: 8pt;
        }
        .footer-meta {
            margin-top: 4px;
            text-align: center;
            font-size: 6.5pt;
            color: #666;
        }
    </style>
</head>
<body>

<div class="form-meta">
    ■ [별지 제16호서식]
    <span class="right">매도인용</span>
</div>

<h1 class="title">자동차양도증명서 (자동차매매업자거래용)</h1>

{{-- 지역/일련번호 + 제시번호 + 매도번호 --}}
<table class="form">
    <tr>
        <td class="label" style="width:18%;">지역 및 일련번호</td>
        <td style="width:24%;">경기 31-24-{{ str_pad((string) $vehicle->id, 5, '0', STR_PAD_LEFT) }}</td>
        <td class="label" style="width:18%;">중고자동차 제시번호</td>
        <td style="width:18%;">&nbsp;</td>
        <td class="label" style="width:10%;">매도번호</td>
        <td>&nbsp;</td>
    </tr>
</table>

{{-- 계약 당사자 (양도인 / 양수인=㈜싼카) --}}
<table class="form" style="margin-top:3px;">
    <tr>
        <td class="label-vert" rowspan="3" style="width:5%;">계약<br>당사자</td>
        <td class="label" style="width:5%;">양도인<br>(을)</td>
        <td class="label" style="width:10%;">성명(명칭)</td>
        <td style="width:22%;">{{ $vehicle->nice_reg_owner_name ?: '' }}</td>
        <td class="stamp-cell" style="width:8%;">(서명 또는 인)</td>
        <td class="label" style="width:5%;">양수인<br>(을)</td>
        <td class="label" style="width:10%;">성명(명칭)</td>
        <td style="width:22%;">㈜싼카</td>
        <td class="stamp-cell">(서명 또는 인)</td>
    </tr>
    <tr>
        <td class="label">전화번호</td>
        <td colspan="2">&nbsp;</td>
        <td class="label">전화번호</td>
        <td colspan="3">031-499-1988</td>
    </tr>
    <tr>
        <td class="label">주소</td>
        <td colspan="2">{{ $vehicle->nice_reg_owner_addr ?: '' }}</td>
        <td class="label">주소</td>
        <td colspan="3">경기 시흥시 산기대학로 163 A동 328호 (정왕동)</td>
    </tr>
</table>

{{-- 자동차매매업자 (㈜싼카 고정) --}}
<table class="form" style="margin-top:3px;">
    <tr>
        <td class="label-vert" rowspan="3" style="width:5%;">자동차<br>매매업자</td>
        <td class="label" style="width:18%;">등록번호 및 상호</td>
        <td colspan="3">02-4115-000476 &nbsp;&nbsp; ㈜싼카</td>
        <td class="stamp-cell" rowspan="2" style="width:8%;">[직인]</td>
    </tr>
    <tr>
        <td class="label">대 표 자</td>
        <td colspan="3">조태신</td>
    </tr>
    <tr>
        <td class="label">취 급 자</td>
        <td colspan="3">{{ $vehicle->salesman?->name ?? '' }}</td>
        <td class="stamp-cell">(서명 또는 인)</td>
    </tr>
</table>

{{-- 계약연월일 --}}
<table class="form" style="margin-top:3px;">
    <tr>
        <td class="label" style="width:18%;">계약연월일</td>
        <td>{{ $vehicle->purchase_date ? $vehicle->purchase_date->format('Y년 m월 d일') : '' }}</td>
    </tr>
</table>

<h2 class="section">중고자동차 매매계약서</h2>

{{-- 차량 정보 --}}
<table class="form">
    <tr>
        <td class="label" style="width:14%;">자동차등록번호</td>
        <td style="width:24%;">{{ $vehicle->vehicle_number ?: '' }}</td>
        <td class="label" style="width:10%;">주행거리</td>
        <td style="width:14%; text-align:right;">{{ $vehicle->mileage ? number_format($vehicle->mileage) : '' }}&nbsp;km</td>
        <td class="label" style="width:8%;">차종</td>
        <td>{{ $vehicle->model_type ?: '' }}</td>
    </tr>
    <tr>
        <td class="label">차대번호</td>
        <td>{{ $vehicle->nice_reg_vin ?: '' }}</td>
        <td class="label">차명</td>
        <td colspan="3">{{ $vehicle->brand ?: '' }} {{ $vehicle->nice_spec_model ?: '' }}</td>
    </tr>
</table>

{{-- 매매금액 --}}
<table class="form" style="margin-top:3px;">
    <tr>
        <td class="label-vert" rowspan="2" style="width:6%;">매매금액</td>
        <td class="label" style="width:8%;">계약금</td>
        <td colspan="2">&nbsp;</td>
        <td class="label" style="width:8%;">잔금</td>
        <td>일금 &nbsp; <strong>{{ $vehicle->purchase_price ? number_format($vehicle->purchase_price) : '' }}</strong> &nbsp; 원정</td>
    </tr>
    <tr>
        <td class="label">중도금</td>
        <td colspan="2">&nbsp;</td>
        <td class="label">잔금일자</td>
        <td>{{ $vehicle->purchase_date ? $vehicle->purchase_date->format('Y년 m월 d일') : '' }}</td>
    </tr>
    <tr>
        <td class="label" style="width:6%;">등록비 및<br>대행수수료</td>
        <td class="label">등록비</td>
        <td>일금 &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; 원정</td>
        <td class="label">대행수수료</td>
        <td colspan="2">일금 &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; 원정</td>
    </tr>
    <tr>
        <td class="label">관리비용</td>
        <td colspan="2">일금 &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; 원정</td>
        <td class="label">압류 및<br>저당권 등록여부</td>
        <td colspan="2">&nbsp;</td>
    </tr>
    <tr>
        <td class="label">매매알선수수료</td>
        <td colspan="5">일금 &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; &nbsp; 원정</td>
    </tr>
    <tr>
        <td class="label">자동차인도일</td>
        <td colspan="5">{{ $vehicle->purchase_date ? $vehicle->purchase_date->format('Y년 m월 d일') : '' }}</td>
    </tr>
</table>

{{-- 약관 1~10조 (요약 표시) --}}
<div class="terms">
    <p><strong>제1조(당사자표시)</strong> 양도인을 "갑"이라 하고, 양수인을 "을"이라 한다.</p>
    <p><strong>제2조(동시이행 등)</strong> ① "갑"은 잔금 수령과 상환으로 소유권이전등록에 필요한 서류와 매매목적물을 "을"에게 인도하기로 한다. ② "을"은 "갑"에게 잔금을 지급함과 동시에 소유권이전등록의 절차에 필요한 서류와 등록비용을 자동차매매업자에게 내주어야 한다. ③ 매매업자는 잔금지급일부터 15일 이내에 자동차소유권 이전등록 신청을 하여야 한다.</p>
    <p><strong>제3조(공과금 부담)</strong> 이 자동차에 대한 제세공과금은 자동차 인도일을 기준으로 하여, 그 기준일까지의 분은 "갑"이 부담하고 기준일 다음날부터의 분은 "을"이 부담한다.</p>
    <p><strong>제4조(사고책임)</strong> "을"은 이 자동차를 인수한 때부터 발생하는 모든 사고에 대하여 자기를 위하여 운행하는 자로서의 책임을 진다.</p>
    <p><strong>제5조(법률상의 하자책임)</strong> ① 자동차 인도일 이전에 발생한 행정처분 또는 이전등록 요건의 불비 등의 하자에 대해서는 "갑"이 그 책임을 진다. ② 매매업자는 「자동차관리법」 제58조제1항에 따라 자동차의 성능ㆍ상태의 점검 내용을 "을"에게 알려야 한다.</p>
    <p><strong>제6조(해약금 등)</strong> ① "갑"이 이 계약을 위반한 경우에는 "갑"은 해약금으로 계약금의 2배액을 "을"에게 배상해야 하며, "을"이 위약한 경우에는 "을"은 "갑"에게 계약금의 반환을 요구할 수 없다. ② 점검내용 중 주행거리, 사고 또는 침수사실이 다르거나, "갑"이 자동차의 성능ㆍ상태의 점검내용 또는 압류ㆍ저당권의 등록 여부를 거짓으로 고지하거나 고지하지 아니한 경우 "을"은 자동차인도일로부터 30일 이내에 매매계약을 해제할 수 있다.</p>
    <p><strong>제7조(매매업자의 책임)</strong> 제5조의 하자에 대해서는 매매업자가 매도인과 동일한 책임을 진다.</p>
    <p><strong>제8조(등록 지체 책임)</strong> 매매업자가 이전등록 신청을 대행하지 않을 때에는 이에 대한 모든 책임을 매매업자가 진다.</p>
    <p><strong>제9조(할부승계 특약)</strong> 할부금이 남은 상태에서 양도하는 경우 나머지 할부금을 "을"이 승계하여 부담할 것인지의 여부를 특약사항란에 적어야 한다.</p>
    <p><strong>제10조(계약서)</strong> 이 계약서는 4통 작성하여 "갑"이 1통, "을"이 1통, 등록절차를 대행하는 매매업자가 2통씩을 각각 지닌다.</p>
</div>

<div class="common-notes">
    <div class="note-title">【공통사항】</div>
    <ul>
        <li>1. 사고유무, 주행거리, 용도이력, 침수, 전손 여부에 대한 설명을 듣고 성능상태점검기록부에 서명 후 교부 받았음</li>
        <li>2. 압류 및 저당권의 등록 여부, 인허가보증보험증권에 대해 고지 받았음</li>
        <li>3. 차량인수 후 단순변심으로 인한 교환이나 환불 불가</li>
    </ul>
    <div>※ 상기 각호에 대해 설명을 듣고 동의함 &nbsp;&nbsp; 서명(양도인) ___________________</div>

    <div class="note-title">【성능상태점검책임보험 차량】</div>
    <ul>
        <li>1. 성능상태점검책임보험에 가입(증권 교부)되어 있으며 보험료는 소비자의 부담임을 고지 받았음</li>
        <li>2. 성능상태점검책임보험 증권의 보증범위가 아닌 노후에 의한 잔고장이나 소모품은 출고 후 보증 불가</li>
    </ul>
    <div>※ 상기 각호에 대해 설명을 듣고 동의함 &nbsp;&nbsp; 서명(양도인) ___________________</div>
</div>

<div class="signature-row">
    「자동차등록규칙」 제33조제2항제2호에 따라 위의 중고자동차매매계약서 기재내용과 같이 양도하였음을 증명합니다.<br>
    <span style="float:right; margin-top:6px;">{{ $vehicle->purchase_date ? $vehicle->purchase_date->format('Y년 m월 d일') : $today }}</span>
    <div style="clear:both;"></div>
    <table class="form" style="margin-top:6px;">
        <tr>
            <td class="label" style="width:8%;">양도인</td>
            <td style="width:32%;">{{ $vehicle->nice_reg_owner_name ?: '' }}</td>
            <td class="stamp-cell" style="width:10%;">(서명 또는 인)</td>
            <td class="label" style="width:8%;">양수인</td>
            <td style="width:32%;">㈜싼카</td>
            <td class="stamp-cell">(서명 또는 인)</td>
        </tr>
    </table>
</div>

<div style="margin-top:6px; font-size:6.5pt;">
    <strong>(유의사항)</strong>
    1. 이 양도증명서는 「자동차관리법」에 따라 자동차매매업의 등록을 한 자만이 사용할 수 있습니다.
    2. 매매업자는 반드시 직인을 찍어야 합니다.
    3. 자동차매매사업 조합의 중고자동차 제시 또는 매도신고번호를 적어야 합니다.
    4. 자동차정지, 검사, 주행거리 이력, 자동차세 납부 여부, 압류내역 및 배출가스저감장치 자기부담금 납부내역 등 자동차토탈이력정보는 국토교통부에서 제공하는 스마트폰용 어플("마이카정보") 또는 자동차민원대국민포털(www.ecar.go.kr)에서 조회가 가능하므로 확인 바랍니다.
</div>

<div class="footer-meta">경기도자동차매매사업조합 &nbsp;&nbsp;|&nbsp;&nbsp; 210㎜×297㎜ [백상지 80g/㎡]</div>

</body>
</html>
