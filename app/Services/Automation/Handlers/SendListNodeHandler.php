<?php

namespace App\Services\Automation\Handlers;

use App\Enums\MessageSenderType;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;
use App\Services\Messaging\MessageService;

class SendListNodeHandler implements NodeHandlerInterface
{
    public function __construct(
        protected MessageService $messages,
        protected VariableInterpolator $interpolator,
    ) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $body = (string) ($config['body'] ?? '');
        $sections = $this->normaliseSections($config['sections'] ?? []);

        if ($body === '' || $sections === []) {
            return NodeResult::fail('List node needs a body and at least one section with rows.');
        }

        if (! $context->conversation) {
            return NodeResult::fail('No conversation available to send into.');
        }

        $rendered = $this->interpolator->interpolate($body, $context->resolver());

        $message = $this->messages->sendList(
            $context->conversation,
            $rendered,
            mb_substr((string) ($config['button'] ?? 'Select'), 0, 20),
            $sections,
            [
                'sender_type' => MessageSenderType::Bot,
                'sync' => true,
                'header' => $config['header'] ?? null,
                'footer' => $config['footer'] ?? null,
            ],
        );

        return NodeResult::next('next', ['message_id' => $message->id]);
    }

    /**
     * @param  array<int, mixed>  $sections
     * @return list<array{title: string, rows: list<array{id: string, title: string, description?: string}>}>
     */
    protected function normaliseSections(array $sections): array
    {
        $normalised = [];
        $rowIndex = 0;

        foreach ($sections as $section) {
            if (! is_array($section)) {
                continue;
            }

            $rows = [];
            foreach ($section['rows'] ?? [] as $row) {
                if (! is_array($row)) {
                    continue;
                }

                $title = trim((string) ($row['title'] ?? ''));
                if ($title === '') {
                    continue;
                }

                $rowIndex++;
                $entry = [
                    'id' => (string) ($row['id'] ?? 'row_'.$rowIndex),
                    'title' => mb_substr($title, 0, 24),
                ];

                $description = trim((string) ($row['description'] ?? ''));
                if ($description !== '') {
                    $entry['description'] = mb_substr($description, 0, 72);
                }

                $rows[] = $entry;
            }

            if ($rows === []) {
                continue;
            }

            $normalised[] = [
                'title' => mb_substr((string) ($section['title'] ?? 'Section'), 0, 24),
                'rows' => $rows,
            ];
        }

        return $normalised;
    }
}
