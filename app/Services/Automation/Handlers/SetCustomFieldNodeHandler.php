<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
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

        if ($node['type'] === NodeType::ClearCustomField->value) {
            $context->contact->setCustomFieldValue($field, null);

            return NodeResult::next('next', ['cleared' => $key]);
        }

        $value = $this->interpolator->interpolate(
            (string) ($config['value'] ?? ''),
            $context->resolver(),
        );

        $context->contact->setCustomFieldValue($field, $value);

        return NodeResult::next('next', ['field' => $key, 'value' => $value]);
    }
}
