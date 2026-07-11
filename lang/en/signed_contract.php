<?php

return [
    'request_btn' => 'Request e-signature',
    'esign_label' => 'E-signature',
    'chip' => [
        'signed' => 'Signed · view copy',
        'waiting' => 'Awaiting signature · copy link',
        'viewed' => 'Viewed · copy link',
    ],
    'modal' => [
        'hint' => 'Send the signing link below to the buyer (KakaoTalk, email, etc.). The buyer reviews the contract and signs on the linked page.',
        'copy' => 'Copy',
        'expire' => 'The link expires in 7 days. Re-issuing invalidates the previous link.',
    ],
    'notify' => [
        'delete_locked' => 'Signed e-contracts cannot be deleted. (legal evidence)',
        'issued' => 'Signing link issued. Send the link below to the buyer.',
        'scope_denied' => 'One or more selected vehicles are out of your scope.',
    ],
    'issue' => [
        'empty' => 'Select at least one vehicle.',
        'too_many' => 'Up to :max vehicles per contract.',
        'export_only' => 'E-signature is available for export-channel vehicles only.',
        'mixed_buyer' => 'Only vehicles of the same buyer can be issued together.',
        'mixed_currency' => 'Only vehicles of the same currency can be issued together.',
        'no_buyer' => 'Only vehicles with an assigned buyer can be issued.',
    ],
    'sign' => [
        'invalid' => 'Invalid signature, please sign again.',
        'render_failed' => 'Could not generate the signed copy, please try again shortly.',
    ],
    'mail' => [
        'subject' => '[Signed] :no',
        'body' => "Your electronic signature for sales contract :no has been completed.\nThe signed copy (PDF) is attached. This email serves as confirmation and delivery evidence.",
    ],
];
