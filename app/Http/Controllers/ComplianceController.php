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
        return "We collect and process contact information and message content solely to provide WhatsApp business messaging services on behalf of our customers. Data is stored securely, encrypted at rest where appropriate, and never sold to third parties. Message content is processed through the Meta WhatsApp Business Platform subject to Meta's terms. You may request deletion of your data at any time — see our Data Deletion page.";
    }

    protected function defaultTerms(): string
    {
        return 'By using this platform you agree to use WhatsApp messaging in compliance with the WhatsApp Business Messaging Policy, to obtain proper opt-in from your contacts, and not to send spam or prohibited content. Subscriptions are billed by the platform; WhatsApp conversation charges are billed separately by Meta.';
    }

    protected function defaultDataDeletion(): string
    {
        return 'To request deletion of your personal data: (1) Contact the business you have been messaging and ask them to delete your contact record, or (2) email our support team with your phone number. Workspace owners can delete contacts, export data, or request full workspace deletion from Settings. Deletion requests are processed within 30 days and logged for audit purposes.';
    }

    protected function defaultSupport(): string
    {
        return 'For support, contact us at support@example.com. Our team responds within one business day.';
    }

    protected function defaultCompany(): string
    {
        return 'This platform is operated as a WhatsApp Business Solution built on the official Meta WhatsApp Business Platform.';
    }
}
