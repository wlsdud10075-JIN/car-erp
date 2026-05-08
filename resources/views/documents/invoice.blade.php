<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Proforma Invoice — {{ $vehicle->vehicle_number }}</title>
    @php
        $company = config('company');
        $buyer = $vehicle->exportBuyer ?: $vehicle->buyer;
        $invoiceNo = sprintf('SC%s-%05d', now()->format('ym'), $vehicle->id);
        $currency = $vehicle->currency ?: 'USD';
        $fobPrice = (float) ($vehicle->sale_price ?? 0);
        $shipping = (float) ($vehicle->transport_fee ?? 0);
        $commission = (float) ($vehicle->commission ?? 0);
        $autoLoading = (float) ($vehicle->auto_loading ?? 0);
        $taxDc = (float) ($vehicle->tax_dc ?? 0);
        $subTotal = $fobPrice + $shipping + $commission + $autoLoading - $taxDc;
        $deposit = (float) ($vehicle->deposit_down_payment ?? 0)
            + (float) ($vehicle->interim_payment ?? 0)
            + (float) ($vehicle->advance_payment1 ?? 0)
            + (float) ($vehicle->advance_payment2 ?? 0);
        $balance = $subTotal - $deposit;
        $fmt = fn ($v) => $v ? number_format($v, 2) : '';
    @endphp
    <style>
        @include('documents._korean_fonts')
        * { font-family: 'NotoSansKR', 'Helvetica', sans-serif; }
        @page { margin: 10mm 12mm; }
        body {
            font-size: 8.5pt;
            line-height: 1.3;
            color: #000;
            margin: 0;
        }
        h1.title {
            font-size: 19pt;
            font-weight: bold;
            text-align: right;
            margin: 0 0 4px 0;
            letter-spacing: 2px;
        }
        .seller-block {
            font-size: 8pt;
            line-height: 1.4;
            margin-bottom: 8px;
        }
        .seller-block .name {
            font-weight: bold;
            font-size: 9.5pt;
        }
        table {
            border-collapse: collapse;
            width: 100%;
            table-layout: fixed;
        }
        table.form td,
        table.form th {
            border: 1px solid #000;
            padding: 2px 5px;
            vertical-align: middle;
            font-size: 8.5pt;
            word-wrap: break-word;
        }
        .label {
            background: #f3f3f3;
            font-weight: normal;
        }
        h2.section {
            font-size: 10pt;
            font-weight: bold;
            margin: 8px 0 3px 0;
            text-transform: uppercase;
            letter-spacing: 1px;
        }
        .num { text-align: right; }
        .center { text-align: center; }
        .total-row td { font-weight: bold; background: #f3f3f3; }
        .balance-row td { font-weight: bold; background: #ffe9c2; }
        .signature-block {
            margin-top: 16px;
            page-break-inside: avoid;
        }
        .signature-block .signer {
            float: right;
            text-align: center;
            width: 40%;
        }
        .signature-block .signer .signed {
            margin-top: 18px;
            border-bottom: 1px solid #000;
            min-height: 14pt;
            font-style: italic;
            color: #555;
        }
        .signature-block .signer .label-line {
            margin-top: 3px;
            font-size: 8pt;
            font-weight: bold;
        }
        .footer {
            clear: both;
            margin-top: 8px;
            padding-top: 3px;
            border-top: 1px solid #000;
            font-size: 7pt;
            color: #555;
        }
    </style>
</head>
<body>

<h1 class="title">PROFORMA INVOICE</h1>

<div class="seller-block">
    <div class="name">{{ $company['name_en'] }}</div>
    <div>{{ $company['address_en'] }}</div>
    <div>TEL: {{ $company['tel'] }} &nbsp;|&nbsp; FAX: {{ $company['fax'] }}</div>
    <div>EMAIL: {{ $company['email'] }}</div>
</div>

{{-- Invoice meta + Buyer --}}
<table class="form meta-table">
    <tr>
        <td class="label" style="width:18%;">Date</td>
        <td style="width:32%;">{{ now()->format('Y-m-d') }}</td>
        <td class="label" style="width:18%;">Invoice No.</td>
        <td>{{ $invoiceNo }}</td>
    </tr>
    <tr>
        <td class="label">Buyer (Name)</td>
        <td>{{ $buyer?->name ?? '' }}</td>
        <td class="label">Contact</td>
        <td>{{ $buyer?->contact_name ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">Address</td>
        <td colspan="3">{{ $buyer?->address ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">Phone</td>
        <td>{{ $buyer?->contact_phone ?? '' }}</td>
        <td class="label">Country</td>
        <td>{{ $buyer?->country?->name ?? '' }}</td>
    </tr>
    <tr>
        <td class="label">Currency / Rate</td>
        <td colspan="3">{{ $currency }} {{ $vehicle->exchange_rate ? '@ '.number_format($vehicle->exchange_rate, 2).' KRW' : '' }}</td>
    </tr>
</table>

<h2 class="section">Bank Account Information</h2>
<table class="form">
    <tr>
        <td class="label" style="width:20%;">Beneficiary</td>
        <td style="width:32%;">{{ $company['bank']['beneficiary_name'] }}</td>
        <td class="label" style="width:18%;">Bank Name</td>
        <td>{{ $company['bank']['bank_name'] }}</td>
    </tr>
    <tr>
        <td class="label">Swift Code</td>
        <td>{{ $company['bank']['swift_code'] }}</td>
        <td class="label">Bank Address</td>
        <td>{{ $company['bank']['bank_address'] }}</td>
    </tr>
    <tr>
        <td class="label">Account Number</td>
        <td>{{ $company['bank']['account_number'] }}</td>
        <td class="label">Beneficiary Address</td>
        <td>{{ $company['address_en'] }}</td>
    </tr>
</table>

<h2 class="section">Vehicle</h2>
<table class="form">
    <tr>
        <th class="label center" style="width:14%;">Code</th>
        <th class="label center" style="width:14%;">Brand</th>
        <th class="label center" style="width:18%;">Model</th>
        <th class="label center" style="width:22%;">Chassis No.</th>
        <th class="label center" style="width:16%;">FOB Price ({{ $currency }})</th>
        <th class="label center">Shipping ({{ $currency }})</th>
    </tr>
    <tr>
        <td class="center">{{ $vehicle->vehicle_number }}</td>
        <td class="center">{{ $vehicle->brand }}</td>
        <td class="center">{{ $vehicle->nice_spec_model ?: $vehicle->model_type }}</td>
        <td class="center">{{ $vehicle->nice_reg_vin }}</td>
        <td class="num">{{ $fmt($fobPrice) }}</td>
        <td class="num">{{ $fmt($shipping) }}</td>
    </tr>
</table>

<h2 class="section">Charges &amp; Total</h2>
<table class="form">
    <tr>
        <td class="label" style="width:60%;">Commission</td>
        <td class="num">{{ $fmt($commission) }} {{ $currency }}</td>
    </tr>
    <tr>
        <td class="label">Auto Loading</td>
        <td class="num">{{ $fmt($autoLoading) }} {{ $currency }}</td>
    </tr>
    <tr>
        <td class="label">Tax D/C</td>
        <td class="num">- {{ $fmt($taxDc) }} {{ $currency }}</td>
    </tr>
    <tr class="total-row">
        <td>SUB TOTAL</td>
        <td class="num">{{ $fmt($subTotal) }} {{ $currency }}</td>
    </tr>
    <tr>
        <td class="label">Deposit (Received)</td>
        <td class="num">- {{ $fmt($deposit) }} {{ $currency }}</td>
    </tr>
    <tr class="balance-row">
        <td>BALANCE DUE</td>
        <td class="num">{{ $fmt($balance) }} {{ $currency }}</td>
    </tr>
</table>

<div class="signature-block">
    <div class="signer">
        @if ($company['seller_signature_path'])
            <img src="{{ $company['seller_signature_path'] }}" style="max-height:60px;">
        @else
            <div class="signed">{{ $company['seller_signature_text'] }}</div>
        @endif
        <div class="label-line">{{ $company['name_en'] }}<br>{{ $company['representative_en'] }}, Representative</div>
    </div>
</div>

<div class="footer">
    Issued by {{ $company['name_en'] }} &mdash; this Proforma Invoice is valid for 30 days from the date of issue. All bank charges outside Korea are at the buyer&rsquo;s expense.
</div>

</body>
</html>
