<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Sales Contract — {{ $vehicle->vehicle_number }}</title>
    @php
        $company = config('company');
        $buyer = $vehicle->exportBuyer ?: $vehicle->buyer;
        $contractNo = sprintf('SC-%s-%05d', now()->format('ym'), $vehicle->id);
        $unitPrice = (float) ($vehicle->sale_price ?? 0);
        $fmt = fn ($v) => $v ? number_format($v, 2) : '';

        // 표준 차량 목록 (시트 2 원본 그대로 — 면허 카탈로그성 약관)
        $standardItems = [
            ['Range Rover EVOQUE 90 UP', '5SEAT', '10UNIT'],
            ['SORENTO 90 UP', '5SEAT', '10UNIT'],
            ['K3', '', '10UNIT'],
            ['VISTO 90 UP', '5SEAT', '10UNIT'],
            ['PALISADE 90 UP', '5SEAT', '10UNIT'],
            ['SANTAFE 90 UP', '5SEAT', '10UNIT'],
            ['ACCENT 90 UP', '6SEAT', '11UNIT'],
            ['VERNA 90 UP', '5SEAT', '10UNIT'],
            ['MORNING 90 UP', '5SEAT', '10UNIT'],
            ['NUBIRA 90 UP', '5SEAT', '10UNIT'],
            ['TUCSON 90 UP', '5SEAT', '10UNIT'],
            ['AVANTE 90 UP', '5SEAT', '10UNIT'],
            ['LACETI 90 UP', '5SEAT', '10UNIT'],
            ['MATIZ 90 UP', '5SEAT', '10UNIT'],
            ['GRANDEUR UP', '1TON 1.4TON', '+'],
            ['SEPHIA 90 UP', '5SEAT', '10UNIT'],
            ['STAREX 90 UP', '3.6.9.12 SEAT', '10UNIT'],
            ['PREGIO 90 UP', '3.6.9.12 SEAT', '10UNIT'],
            ['GRACE 90 UP', '3.6.9.12 SEAT', '10UNIT'],
            ['LIBERO 90 UP', '1TON 1.4TON', '10UNIT'],
            ['BONGO 1.2.3 90 UP', '1TON 1.4TON', '10UNIT'],
            ['TICO 90 UP', '1TON 1.5TON', '11UNIT'],
            ['POTER 90 UP', '1TON 1.4TON', '10UNIT'],
            ['LABO 90 UP', '1TON 1.4TON', '10UNIT'],
            ['BONGO 3 90 UP', '1TON 1.4TON', '10UNIT'],
            ['SONATA 90 UP', '5SEAT', '10UNIT'],
            ['ANY KOREAN CAR', '5SEAT, 1TON 1.4TON, ANY SEAT', '10UNIT'],
            ['ANY JAPANESE CAR', '5SEAT, 1TON 1.4TON, ANY SEAT', '10UNIT'],
            ['ALL OF BMW CAR', '5SEAT, 1TON 1.4TON, ANY SEAT', '10UNIT'],
            ['ALL OF BENZ CAR', '5SEAT, 1TON 1.4TON, ANY SEAT', '10UNIT'],
            ['ALL OF AUDI CAR', '5SEAT, 1TON 1.4TON, ANY SEAT', '10UNIT'],
        ];
    @endphp
    <style>
        @include('documents._korean_fonts')
        * { font-family: 'NotoSansKR', 'Helvetica', sans-serif; }
        @page { margin: 10mm 12mm; }
        body {
            font-size: 8pt;
            line-height: 1.3;
            color: #000;
            margin: 0;
        }
        .header {
            text-align: center;
            margin-bottom: 6px;
        }
        .header .name {
            font-size: 14pt;
            font-weight: bold;
            letter-spacing: 1px;
        }
        .header .meta {
            font-size: 7.5pt;
            line-height: 1.5;
            margin-top: 2px;
        }
        h1.title {
            text-align: center;
            font-size: 16pt;
            font-weight: bold;
            margin: 8px 0 4px 0;
            letter-spacing: 4px;
        }
        .preamble {
            text-align: center;
            font-size: 8pt;
            margin: 0 0 8px 0;
            line-height: 1.4;
        }
        .terms-list {
            margin: 6px 0;
            padding: 0;
            font-size: 8pt;
            list-style: none;
        }
        .terms-list li {
            margin: 2px 0;
            line-height: 1.4;
        }
        .terms-list li .lbl {
            display: inline-block;
            min-width: 130px;
            font-weight: bold;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
        }
        table.items th,
        table.items td {
            border: 1px solid #000;
            padding: 2px 4px;
            font-size: 7.5pt;
            vertical-align: middle;
        }
        table.items th {
            background: #f3f3f3;
            font-weight: normal;
            text-align: center;
        }
        .num { text-align: right; }
        .center { text-align: center; }
        .total-row td {
            font-weight: bold;
            background: #f3f3f3;
        }
        .vehicle-row td {
            font-weight: bold;
            background: #ffe9c2;
        }
        .meta-table {
            margin-top: 8px;
            font-size: 8pt;
        }
        .meta-table td {
            padding: 3px 4px;
        }
        .meta-table .lbl {
            font-weight: bold;
            width: 22%;
        }
        .signature-block {
            margin-top: 18px;
            page-break-inside: avoid;
        }
        .signature-block table {
            width: 100%;
        }
        .signature-block table td {
            width: 50%;
            padding: 3px 8px;
            vertical-align: top;
            font-size: 8.5pt;
            line-height: 1.5;
        }
        .signature-block .signer-label {
            font-weight: bold;
        }
        .signature-block .signer-name {
            margin-top: 14px;
            border-bottom: 1px solid #000;
            min-height: 14pt;
            font-style: italic;
            color: #555;
            text-align: center;
        }
    </style>
</head>
<body>

<div class="header">
    <div class="name">{{ $company['name_en'] }}</div>
    <div class="meta">
        {{ $company['address_en'] }}<br>
        TEL: {{ $company['tel'] }} &nbsp;|&nbsp; FAX: {{ $company['fax'] }} &nbsp;|&nbsp; EMAIL: {{ $company['email'] }}
    </div>
</div>

<h1 class="title">SALES CONTRACT</h1>

<div class="preamble">
    WE THE SELLER AND THE BUYERS, CONFIRM HAVING CONCLUDED THIS CONTRACT<br>
    COVERING UNDERMENTIONED GOODS IN ACCORDANCE WITH THE TERMS AND CONDITIONS STATED HEREIN AND ON THE BACK
</div>

<ul class="terms-list">
    <li><span class="lbl">SHIPMENT :</span> TRANS SHIPMENT &nbsp; ALLOWED : &nbsp; PARTIAL SHIPMENT ALLOWED</li>
    <li><span class="lbl">TIME OF CONTRACT EFFECT :</span> WITHIN 4 MONTH AFTER CONTRACTING DATE</li>
    <li><span class="lbl">PORT OF LOADING :</span> ANY KOREAN PORT</li>
    <li><span class="lbl">PACKING :</span> D/P</li>
    <li><span class="lbl">INSURANCE :</span> TO BE COVERED BY SELLER</li>
    <li><span class="lbl">FROM :</span> KOREA &nbsp;&nbsp;&nbsp; <span class="lbl" style="min-width:0;">TO :</span> {{ $buyer?->country?->name ?? '' }}</li>
</ul>

<table class="items">
    <tr>
        <th style="width:34%;">ITEM</th>
        <th style="width:30%;">DESCRIPTIONS</th>
        <th style="width:14%;">QUANTITY</th>
        <th style="width:11%;">UNIT PRICE</th>
        <th>AMOUNT</th>
    </tr>
    @foreach ($standardItems as $it)
        <tr>
            <td>{{ $it[0] }}</td>
            <td>{{ $it[1] }}</td>
            <td class="center">{{ $it[2] }}</td>
            <td class="num">700</td>
            <td class="num">7,000</td>
        </tr>
    @endforeach

    {{-- THIS contract's actual vehicle (highlighted) --}}
    <tr class="vehicle-row">
        <td>{{ $vehicle->brand }} {{ $vehicle->nice_spec_model ?: $vehicle->model_type }}</td>
        <td>VIN: {{ $vehicle->nice_reg_vin }}</td>
        <td class="center">1 UNIT</td>
        <td class="num">{{ $fmt($unitPrice) }}</td>
        <td class="num">{{ $fmt($unitPrice) }}</td>
    </tr>

    <tr class="total-row">
        <td colspan="2" class="center">TOTAL</td>
        <td class="center">1 UNIT</td>
        <td colspan="2" class="num">{{ $fmt($unitPrice) }} {{ $vehicle->currency ?: 'USD' }} &nbsp; F.O.B INCHEON PORT</td>
    </tr>
</table>

<table class="meta-table">
    <tr>
        <td class="lbl">BODY NUMBER :</td>
        <td>{{ $vehicle->nice_reg_vin }}</td>
        <td class="lbl" style="width:18%;">CONTRACT DATE :</td>
        <td>{{ now()->format('Y-m-d') }}</td>
    </tr>
    <tr>
        <td class="lbl">CONDITION :</td>
        <td>F.O.B INCHEON PORT</td>
        <td class="lbl">CONTRACT NUMBER :</td>
        <td>{{ $contractNo }}</td>
    </tr>
    <tr>
        <td class="lbl">INSPECTION :</td>
        <td colspan="3">SELLER&rsquo;S TO BE FINAL</td>
    </tr>
    <tr>
        <td class="lbl">OTHER TERMS &amp; CONDITIONS :</td>
        <td colspan="3">&nbsp;</td>
    </tr>
</table>

<div class="signature-block">
    <table>
        <tr>
            <td>
                <div class="signer-label">BUYER : {{ $buyer?->name ?? '' }}</div>
                @if ($company['seller_signature_path'])
                    <img src="{{ $company['seller_signature_path'] }}" style="max-height:60px; margin-top:8px;">
                @else
                    <div class="signer-name">{{ $company['seller_signature_text'] }}</div>
                @endif
                <div style="margin-top:4px;">BY &nbsp; {{ $buyer?->contact_name ?? '' }}</div>
            </td>
            <td>
                <div class="signer-label">SELLER : {{ $company['name_en'] }}</div>
                @if ($company['seller_signature_path'])
                    <img src="{{ $company['seller_signature_path'] }}" style="max-height:60px; margin-top:8px;">
                @else
                    <div class="signer-name">{{ $company['seller_signature_text'] }}</div>
                @endif
                <div style="margin-top:4px;">BY &nbsp; {{ $company['representative_en'] }}</div>
            </td>
        </tr>
    </table>
</div>

</body>
</html>
