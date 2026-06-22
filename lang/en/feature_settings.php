<?php

// i18n — Feature Settings (admin/settings, super only).
return [
    'title' => 'Feature Settings',
    'subtitle' => 'System admin (super) only — global settings',
    'saved' => 'Saved — refresh to see it in the sidebar.',
    'locale_enabled_flash' => 'English has been enabled. Use the 한국어 / English buttons in the top bar to switch your own screen.',
    'locale_disabled_flash' => 'English has been disabled.',

    'brand_section' => 'Brand',
    'brand_label' => 'Sidebar brand text',
    'brand_hint' => 'Shown next to the logo at the top of the sidebar (max 12 chars). e.g. SSANCAR / SANCAR ERP',

    'lang_section' => 'Language (i18n)',
    'lang_hint' => 'Users can only pick enabled languages from the top bar. Turning one off reverts those users to Korean.',
    'ko_label' => 'Korean',
    'ko_default' => '(default)',
    'always_on' => 'Always on',
    'en_label' => 'English',
    'en_sub' => '(English)',

    'alarm_section' => 'Clearance Document Alarm',
    'alarm_hint' => 'Export vehicles 10 days before arrival (ETA) get a "prepare clearance documents" alarm. Run `php artisan alarms:scan --dry-run` first to check how many will fire.',
    'alarm_label' => 'Enable ETA clearance alarm',
    'alarm_sub' => '(off = no alarms generated)',

    'stamp_section' => 'Stamp · Signature',
    'stamp_hint' => 'Company stamp/signature is auto-overlaid at the fixed position when generating documents. If not uploaded, the template default image is used. (Transparent PNG recommended, max 2MB)',
    'stamp_signature_label' => 'Signature',
    'stamp_signature_sub' => 'Applied to deregistration contract · shipping invoices',
    'stamp_seal_label' => 'Company seal',
    'stamp_seal_sub' => 'Applied to sales invoice · contracts',
    'stamp_uploaded' => 'Uploaded',
    'stamp_default' => 'Using template default',
    'stamp_upload_btn' => 'Choose image',
    'stamp_remove_btn' => 'Revert to default',
    'stamp_removed' => 'Reverted to default image.',
    'stamp_invalid' => 'Only PNG/JPG images allowed (max 2MB).',
];
