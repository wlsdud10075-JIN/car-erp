<?php

// i18n — Salesman cashflow (erp/salesmen/cashflow). Table headers via vehicle.col, status via domain.progress.
return [
    'title' => ':name — Cashflow',
    'date_from' => 'Purchase from',
    'date_to' => 'Purchase to',
    'kpi_vehicles' => 'Vehicles',
    'unit' => '',
    'kpi_purchase_total' => 'Total Buy Price',
    'kpi_purchase_unpaid' => 'Unpaid Purchases',
    'kpi_sale_unpaid' => 'Unpaid Sales',
    'kpi_carryover' => 'Unconsumed carryover',
    'carryover_sub' => 'FX carryover not yet absorbed by a settlement',
    'carryover_to_pay' => 'To pay',
    'carryover_to_collect' => 'To collect',
    'carryover_clear_btn' => 'Settle carryover',
    'carryover_clear_confirm' => 'Settle the unconsumed carryover of ₩:amount as ":action" and zero it out. This is for departed/ended-relationship salesmen. Proceed?',
    'carryover_cleared' => 'Carryover of ₩:amount settled (balance 0).',
    'carryover_already_zero' => 'No unconsumed carryover to settle (already 0).',
    'none' => 'None',
    'paid' => 'Paid',
    'empty' => 'No vehicles for this period.',
    'm_purchase_date' => 'Purchased:',
    'm_purchase_price' => 'Buy:',
    'm_unpaid' => 'Unpaid (buy):',
    'm_sale_unpaid' => 'Unpaid (sell):',
];
