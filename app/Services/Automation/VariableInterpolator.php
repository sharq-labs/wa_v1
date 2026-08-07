<?php

namespace App\Services\Automation;

use RuntimeException;

/**
 * Safely interpolates {{path}} variables into message text.
 *
 * Supported namespaces: contact.*, workspace.*, agent.*, custom.*,
 * variables.*, message.text. Values are plain text — no code execution.
 */
class VariableInterpolator
{
    public const MISSING_EMPTY = 'empty';

    public const MISSING_DEFAULT = 'default';

    public const MISSING_FAIL = 'fail';

    /**
     * @param  callable(string): ?string  $resolver  maps a variable path to a value
     */
    public function interpolate(
        string $text,
        callable $resolver,
        string $missingBehaviour = self::MISSING_EMPTY,
        string $defaultValue = '',
    ): string {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            function (array $matches) use ($resolver, $missingBehaviour, $defaultValue) {
                $value = $resolver($matches[1]);

                if ($value !== null && $value !== '') {
                    return $value;
                }

                return match ($missingBehaviour) {
                    self::MISSING_DEFAULT => $defaultValue,
                    self::MISSING_FAIL => throw new RuntimeException(
                        "Missing variable [{$matches[1]}] in message text.",
                    ),
                    default => '',
                };
            },
            $text,
        ) ?? $text;
    }
}
