<?php

namespace App\Services\Automation\Handlers;

use App\Enums\MessageSenderType;
use App\Models\WhatsAppTemplate;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Messaging\MessageService;
use App\Services\Templates\TemplateRenderer;

class SendTemplateNodeHandler implements NodeHandlerInterface
{
    public function __construct(
        protected MessageService $messages,
        protected TemplateRenderer $renderer,
    ) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $templateId = $config['template_id'] ?? null;

        if (! $templateId) {
            return NodeResult::fail('Send Template node has no template selected.');
        }

        $template = WhatsAppTemplate::query()
            ->forWorkspace($context->workspace)
            ->find($templateId);

        if (! $template) {
            return NodeResult::fail('Template no longer exists.');
        }

        if (! $template->isApproved()) {
            return NodeResult::fail("Template [{$template->name}] is not approved.");
        }

        if (! $context->conversation) {
            return NodeResult::fail('No conversation available to send into.');
        }

        $rendered = $this->renderer->render(
            $template,
            $context->conversation,
            $config['variable_mappings'] ?? [],
            $context,
        );

        $message = $this->messages->sendTemplate(
            $context->conversation,
            $template,
            $rendered['components'],
            $rendered['text'],
            ['sender_type' => MessageSenderType::Bot, 'sync' => true],
        );

        return NodeResult::next('next', ['message_id' => $message->id, 'template' => $template->name]);
    }
}
