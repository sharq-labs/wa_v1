<?php

return [
    'company_name' => env('COMPLIANCE_COMPANY_NAME', env('APP_NAME', 'WhatsFlow')),
    'legal_name' => env('COMPLIANCE_LEGAL_NAME'),
    'company_address' => env('COMPLIANCE_COMPANY_ADDRESS'),
    'support_email' => env('COMPLIANCE_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS')),
    'privacy_email' => env('COMPLIANCE_PRIVACY_EMAIL', env('COMPLIANCE_SUPPORT_EMAIL', env('MAIL_FROM_ADDRESS'))),
    'support_url' => env('COMPLIANCE_SUPPORT_URL'),
];
