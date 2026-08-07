<?php

namespace App\Services\Automation\Handlers;

use App\Enums\MessageSenderType;
use App\Models\AutomationWait;
use App\Models\CustomField;
use App\Models\Message;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;
use App\Services\Messaging\MessageService;
use Carbon\Carbon;

/**
 * Sends a question and persists a reply wait. The wait survives restarts;
 * the answer is validated and saved to a custom field or run variable.
 */
class AskQuestionNodeHandler implements NodeHandlerInterface
{
    public function __construct(
        protected MessageService $messages,
        protected VariableInterpolator $interpolator,
    ) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $question = (string) ($config['question'] ?? $config['text'] ?? '');

        if ($question === '') {
            return NodeResult::fail('Ask Question node has no question text.');
        }

        if (($config['save_to'] ?? '') === '') {
            return NodeResult::fail('Ask Question node has no save destination.');
        }

        if (! $context->conversation) {
            return NodeResult::fail('No conversation available to send into.');
        }

        $rendered = $this->interpolator->interpolate($question, $context->resolver());

        $message = $this->messages->sendText($context->conversation, $rendered, [
            'sender_type' => MessageSenderType::Bot,
            'sync' => true,
        ]);

        AutomationWait::query()->create([
            'workspace_id' => $context->workspace->id,
            'automation_run_id' => $context->run->id,
            'conversation_id' => $context->conversation->id,
            'node_id' => $node['id'],
            'wait_type' => AutomationWait::TYPE_REPLY,
            'config' => [
                'kind' => 'question',
                'save_to' => $config['save_to'],
                'validation' => $config['validation'] ?? 'text',
                'choices' => $config['choices'] ?? [],
                'error_message' => $config['error_message'] ?? null,
                'max_attempts' => (int) ($config['max_attempts'] ?? 0), // 0 = unlimited
            ],
            'status' => 'pending',
        ]);

        return NodeResult::wait(['message_id' => $message->id, 'question' => $rendered]);
    }

    /**
     * @return array{action: 'continue'|'stay', handle?: string}
     */
    public function handleReply(AutomationContext $context, array $node, AutomationWait $wait, Message $message): array
    {
        $config = $wait->config ?? [];
        $answer = trim((string) $message->content);

        if (! $this->validate($answer, $config['validation'] ?? 'text', $config['choices'] ?? [])) {
            $wait->increment('invalid_attempts');

            $maxAttempts = (int) ($config['max_attempts'] ?? 0);

            if ($maxAttempts > 0 && $wait->invalid_attempts >= $maxAttempts) {
                // Give up validating: continue with the raw answer via the invalid path if any.
                $this->save($context, (string) ($config['save_to'] ?? ''), $answer);

                return ['action' => 'continue', 'handle' => 'next'];
            }

            $error = (string) ($config['error_message'] ?? __('Sorry, that does not look valid. Please try again.'));

            if ($context->conversation) {
                $this->messages->sendText($context->conversation, $error, [
                    'sender_type' => MessageSenderType::Bot,
                    'sync' => true,
                ]);
            }

            return ['action' => 'stay'];
        }

        $this->save($context, (string) ($config['save_to'] ?? ''), $answer);

        return ['action' => 'continue', 'handle' => 'next'];
    }

    protected function validate(string $answer, string $type, array $choices): bool
    {
        if ($answer === '') {
            return false;
        }

        return match ($type) {
            'number' => is_numeric(str_replace([',', ' '], ['', ''], $answer)),
            'email' => filter_var($answer, FILTER_VALIDATE_EMAIL) !== false,
            'phone' => preg_match('/^\+?[0-9\s().-]{6,20}$/', $answer) === 1,
            'date' => $this->isDate($answer),
            'choice' => $choices === [] || in_array(
                mb_strtolower($answer),
                array_map('mb_strtolower', array_map('strval', $choices)),
                true,
            ),
            default => true, // free text
        };
    }

    protected function isDate(string $value): bool
    {
        try {
            Carbon::parse($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    protected function save(AutomationContext $context, string $saveTo, string $value): void
    {
        [$namespace, $key] = array_pad(explode('.', $saveTo, 2), 2, null);

        if (! $key) {
            return;
        }

        if ($namespace === 'custom' && $context->contact) {
            $field = CustomField::query()
                ->forWorkspace($context->workspace)
                ->where('key', $key)
                ->first();

            if ($field) {
                $context->contact->setCustomFieldValue($field, $value);
            }
        } elseif (in_array($namespace, ['variables', 'variable'], true)) {
            $context->setVariable($key, $value);
        }
    }
}
