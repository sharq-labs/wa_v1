<?php

use App\Services\Automation\VariableInterpolator;

$resolver = fn (string $path): ?string => match ($path) {
    'contact.first_name' => 'Ahmed',
    'custom.company_name' => 'TechCorp',
    default => null,
};

it('replaces known variables', function () use ($resolver) {
    $result = (new VariableInterpolator)->interpolate(
        'Hi {{contact.first_name}} from {{custom.company_name}}!',
        $resolver,
    );

    expect($result)->toBe('Hi Ahmed from TechCorp!');
});

it('replaces missing variables with empty by default', function () use ($resolver) {
    $result = (new VariableInterpolator)->interpolate('Hello {{custom.missing}}!', $resolver);

    expect($result)->toBe('Hello !');
});

it('supports a configured default for missing variables', function () use ($resolver) {
    $result = (new VariableInterpolator)->interpolate(
        'Hello {{custom.missing}}!',
        $resolver,
        VariableInterpolator::MISSING_DEFAULT,
        'friend',
    );

    expect($result)->toBe('Hello friend!');
});

it('can fail the node on missing variables', function () use ($resolver) {
    (new VariableInterpolator)->interpolate(
        'Hello {{custom.missing}}!',
        $resolver,
        VariableInterpolator::MISSING_FAIL,
    );
})->throws(RuntimeException::class);

it('leaves non-variable braces untouched and never executes content', function () use ($resolver) {
    $result = (new VariableInterpolator)->interpolate('Keep {{not a var}} and {plain}', $resolver);

    expect($result)->toBe('Keep {{not a var}} and {plain}');
});
