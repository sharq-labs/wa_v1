<?php

use App\Enums\NodeType;
use App\Models\AutomationRun;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\Handlers\HttpRequestNodeHandler;
use App\Services\Automation\VariableInterpolator;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('preserves existing URL query parameters on DELETE automation requests', function () {
    $ctx = createWorkspaceContext();
    $automation = publishAutomation($ctx['workspace'], [
        'nodes' => [
            ['id' => 'trigger', 'type' => 'trigger_incoming_message', 'config' => []],
            ['id' => 'stop', 'type' => 'stop', 'config' => []],
        ],
        'edges' => [
            ['id' => 'edge', 'source' => 'trigger', 'sourceHandle' => 'next', 'target' => 'stop'],
        ],
    ], 'HTTP Test');

    $run = AutomationRun::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'automation_id' => $automation->id,
        'automation_version_id' => $automation->published_version_id,
        'status' => 'running',
        'started_at' => now(),
    ]);
    $context = AutomationContext::forRun($run);

    Http::fake(fn () => Http::response(['ok' => true], 200));

    $handler = new HttpRequestNodeHandler(app(VariableInterpolator::class));
    $handler->handle($context, [
        'id' => 'http',
        'type' => NodeType::HttpRequest->value,
        'config' => [
            'method' => 'DELETE',
            'url' => 'https://1.1.1.1/items?existing=1',
            'query' => [
                ['key' => 'added', 'value' => '2'],
            ],
        ],
    ]);

    Http::assertSent(function (Request $request): bool {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'DELETE'
            && ($query['existing'] ?? null) === '1'
            && ($query['added'] ?? null) === '2';
    });
});
