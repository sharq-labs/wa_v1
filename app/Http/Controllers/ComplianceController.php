<?php

namespace App\Http\Controllers;

use App\Models\SystemSetting;
use Illuminate\Contracts\View\View;

/**
 * Public compliance pages required for production readiness and Meta App
 * Review. Content is editable from the admin system settings.
 */
class ComplianceController extends Controller
{
    public function privacy(): View
    {
        return $this->page('privacy_policy', 'Privacy Policy', $this->defaultPrivacy());
    }

    public function terms(): View
    {
        return $this->page('terms_of_service', 'Terms of Service', $this->defaultTerms());
    }

    public function dataDeletion(): View
    {
        return $this->page('data_deletion', 'Data Deletion Instructions', $this->defaultDataDeletion());
    }

    public function support(): View
    {
        return $this->page('support_contact', 'Support & Contact', $this->defaultSupport());
    }

    public function company(): View
    {
        return $this->page('company_info', 'Company Information', $this->defaultCompany());
    }

    protected function page(string $key, string $title, string $default): View
    {
        return view('compliance', [
            'title' => $title,
            'content' => SystemSetting::get('compliance.'.$key) ?? $default,
        ]);
    }

    protected function defaultPrivacy(): string
    {
        $company = $this->companyName();
        $privacyEmail = $this->privacyEmail();

        return "{$company} processes contact information, message content, account identifiers, and operational metadata only to provide WhatsApp business messaging, automation, inbox, analytics, and customer-support services for its customers. Data is protected using access controls and encryption where appropriate and is not sold to advertisers or data brokers. WhatsApp message delivery is processed through the official Meta WhatsApp Business Platform and is also subject to Meta's applicable terms. Data is retained only for as long as needed to provide the service, meet legal obligations, resolve disputes, and maintain security records. Data subjects or workspace owners may request access, correction, export, or deletion by contacting {$privacyEmail}. See the Data Deletion page for the deletion procedure.";
    }

    protected function defaultTerms(): string
    {
        $company = $this->companyName();

        return "By using {$company}, customers agree to use WhatsApp messaging in compliance with the WhatsApp Business Messaging Policy and applicable laws, obtain valid consent where required, provide accurate business information, and not send spam, deceptive messages, or prohibited content. Customers are responsible for the content they send and for maintaining lawful contact lists. Platform subscription fees are charged by {$company}; Meta WhatsApp messaging or conversation charges may be billed separately according to the customer's Meta arrangement. Accounts may be suspended for abuse, security threats, unlawful use, or policy violations.";
    }

    protected function defaultDataDeletion(): string
    {
        $privacyEmail = $this->privacyEmail();

        return "To request deletion of personal data processed by this platform: (1) contact the business you have been messaging and ask it to delete your contact or conversation data, or (2) email {$privacyEmail} with enough information to identify the relevant account or phone number. Workspace owners can delete contacts from the CRM and may request full workspace deletion through support. We verify deletion requests before acting to prevent unauthorized deletion. Approved requests are processed within 30 days unless retention is required by law, fraud prevention, security, billing, or dispute-resolution obligations. When deletion is complete, production data is removed or irreversibly anonymized, subject to normal backup-retention windows.";
    }

    protected function defaultSupport(): string
    {
        $company = $this->companyName();
        $supportEmail = $this->supportEmail();
        $supportUrl = config('compliance.support_url');
        $urlText = filled($supportUrl) ? " Support portal: {$supportUrl}." : '';

        return "For {$company} support, contact {$supportEmail}.{$urlText} Support requests are handled during normal business operations, with priority given to account access, WhatsApp connectivity, security, billing, and data-protection issues.";
    }

    protected function defaultCompany(): string
    {
        $company = $this->companyName();
        $legalName = config('compliance.legal_name') ?: $company;
        $address = config('compliance.company_address');
        $addressText = filled($address) ? " Registered/business address: {$address}." : '';

        return "{$company} is operated by {$legalName} and provides software for WhatsApp Business messaging, team inboxes, automations, templates, campaigns, and customer operations using the official Meta WhatsApp Business Platform.{$addressText} Support contact: {$this->supportEmail()}.";
    }

    protected function companyName(): string
    {
        return (string) (config('compliance.company_name') ?: config('app.name', 'WhatsFlow'));
    }

    protected function supportEmail(): string
    {
        return (string) (config('compliance.support_email') ?: config('mail.from.address') ?: 'support@localhost');
    }

    protected function privacyEmail(): string
    {
        return (string) (config('compliance.privacy_email') ?: $this->supportEmail());
    }
}
