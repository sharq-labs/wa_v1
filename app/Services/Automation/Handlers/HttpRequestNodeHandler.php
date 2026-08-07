<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;
use Illuminate\Support\Facades\Http;

/**
 * HTTP Request / Send Webhook node with SSRF protection:
 * - only http/https schemes
 * - private, loopback and link-local IP ranges blocked (after DNS resolution)
 * - response size and timeout limited
 * - secrets never logged
 */
class HttpRequestNodeHandler implements NodeHandlerInterface
{
    public function __construct(protected VariableInterpolator $interpolator) {}

    public function handle(AutomationContext $context, array $node): NodeResult
    {
        $config = $node['config'] ?? [];
        $url = $this->interpolator->interpolate((string) ($config['url'] ?? ''), $context->resolver());
        $method = strtoupper($config['method'] ?? 'POST');

        if (! in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return NodeResult::fail("HTTP method [{$method}] is not allowed.");
        }

        $ssrfError = $this->validateUrl($url);
        if ($ssrfError !== null) {
            return NodeResult::fail($ssrfError);
        }

        $headers = [];
        foreach (($config['headers'] ?? []) as $header) {
            if (! empty($header['key'])) {
                $headers[$header['key']] = $this->interpolator->interpolate((string) ($header['value'] ?? ''), $context->resolver());
            }
        }

        if (($config['auth']['type'] ?? 'none') === 'bearer' && ! empty($config['auth']['token'])) {
            $headers['Authorization'] = 'Bearer '.$config['auth']['token'];
        } elseif (($config['auth']['type'] ?? 'none') === 'basic' && ! empty($config['auth']['username'])) {
            $headers['Authorization'] = 'Basic '.base64_encode(
                ($config['auth']['username'] ?? '').':'.($config['auth']['password'] ?? ''),
            );
        }

        $query = [];
        foreach (($config['query'] ?? []) as $q) {
            if (! empty($q['key'])) {
                $query[$q['key']] = $this->interpolator->interpolate((string) ($q['value'] ?? ''), $context->resolver());
            }
        }

        $body = null;
        if ($node['type'] === NodeType::SendWebhook->value) {
            // Send Webhook: fixed payload describing the context.
            $body = [
                'event' => 'automation.webhook',
                'workspace_id' => $context->workspace->id,
                'automation' => $context->automation->name,
                'contact' => [
                    'id' => $context->contact?->id,
                    'phone_number' => $context->contact?->phone_number,
                    'name' => $context->contact?->full_name,
                ],
                'conversation_id' => $context->conversation?->id,
                'variables' => $context->variables,
                'custom_payload' => $config['payload'] ?? null,
            ];
        } elseif (isset($config['body']) && $config['body'] !== '') {
            $raw = $this->interpolator->interpolate((string) $config['body'], $context->resolver());
            $decoded = json_decode($raw, true);
            $body = $decoded !== null ? $decoded : $raw;
        }

        $timeout = min(
            (int) ($config['timeout'] ?? config('whatsapp.http_node.timeout_seconds', 10)),
            (int) config('whatsapp.http_node.timeout_seconds', 10),
        );

        try {
            $pending = Http::withHeaders($headers)
                ->timeout(max(1, $timeout))
                ->withOptions(['allow_redirects' => false]);

            $response = match ($method) {
                'GET' => $pending->get($url, $query),
                'DELETE' => $pending->delete($url.$this->queryString($query), is_array($body) ? $body : []),
                default => $pending->withQueryParameters($query)->send($method, $url, [
                    is_array($body) ? 'json' : 'body' => $body ?? [],
                ]),
            };
        } catch (\Throwable $e) {
            return NodeResult::next('error', ['error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        $maxBytes = (int) config('whatsapp.http_node.max_response_bytes', 524288);
        $bodyText = mb_substr($response->body(), 0, $maxBytes);
        $json = json_decode($bodyText, true) ?? [];

        // Response mappings: response.data.customer_id -> variables.customer_id
        foreach (($config['response_mappings'] ?? []) as $mapping) {
            $path = (string) ($mapping['path'] ?? '');
            $variable = (string) ($mapping['variable'] ?? '');

            if ($path === '' || $variable === '') {
                continue;
            }

            $path = preg_replace('/^response\./', '', $path);
            $value = data_get($json, $path);

            if ($value !== null && ! is_scalar($value)) {
                $value = json_encode($value);
            }

            $context->setVariable($variable, $value === null ? null : (string) $value);
        }

        $status = $response->status();
        $handle = $status >= 200 && $status < 300 ? 'next' : 'error';

        return NodeResult::next($handle, [
            'status' => $status,
            'mapped' => count($config['response_mappings'] ?? []),
        ]);
    }

    /**
     * @return string|null error message when the URL must be blocked
     */
    protected function validateUrl(string $url): ?string
    {
        $parts = parse_url($url);

        if (! $parts || empty($parts['host'])) {
            return 'HTTP node URL is invalid.';
        }

        $scheme = strtolower($parts['scheme'] ?? '');

        if (! in_array($scheme, config('whatsapp.http_node.allowed_schemes', ['http', 'https']), true)) {
            return "URL scheme [{$scheme}] is not allowed.";
        }

        $host = $parts['host'];

        // Resolve and check every IP the hostname points at.
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);

        if ($ips === []) {
            return 'URL host could not be resolved.';
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return 'Requests to private or reserved network addresses are blocked.';
            }
        }

        return null;
    }

    protected function queryString(array $query): string
    {
        return $query === [] ? '' : '?'.http_build_query($query);
    }
}
