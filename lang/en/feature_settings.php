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
];
