<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
use App\Jobs\ProcessAutomationEvent;
use App\Models\CustomField;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;

/**
 * Handles both Set Custom Field and Clear Custom Field.
 */
class SetCustomFieldNodeHandler implements NodeHandlerInterface
{
    public function __construct(protected VariableInterpolator $interpolator) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $key = (string) ($config['field_key'] ?? $config['key'] ?? '');

        if ($key === '') {
            return NodeResult::fail('Custom field node has no field selected.');
        }

        if (! $context->contact) {
            return NodeResult::fail('No contact in context.');
        }

        $field = CustomField::query()
            ->forWorkspace($context->workspace)
            ->where('key', $key)
            ->first();

        if (! $field) {
            return NodeResult::fail("Custom field [{$key}] does not exist.");
        }

        $oldValue = $context->contact->customFieldValue($key);

        if ($node['type'] === NodeType::ClearCustomField->value) {
            $context->contact->setCustomFieldValue($field, null);
            $this->dispatchChange($context, $field, $oldValue, null);

            return NodeResult::next('next', ['cleared' => $key, 'changed' => $oldValue !== null]);
        }

        $value = $this->interpolator->interpolate(
            (string) ($config['value'] ?? ''),
            $context->resolver(),
        );

        $context->contact->setCustomFieldValue($field, $value);
        $this->dispatchChange($context, $field, $oldValue, $value);

        return NodeResult::next('next', ['field' => $key, 'value' => $value, 'changed' => $oldValue !== $value]);
    }

    protected function dispatchChange(
        AutomationContext $context,
        CustomField $field,
        ?string $oldValue,
        ?string $newValue,
    ): void {
        if ($oldValue === $newValue) {
            return;
        }

        ProcessAutomationEvent::dispatch(
            $context->workspace->id,
            NodeType::TriggerFieldChanged->value,
            $context->contact?->id,
            [
                'field_id' => $field->id,
                'field_key' => $field->key,
                'old_value' => $oldValue,
                'new_value' => $newValue,
                'source' => 'automation',
                'source_run_id' => $context->run->id,
            ],
        )->afterCommit();
    }
}
