<?php

namespace App\Services\Automation\Handlers;

use App\Enums\NodeType;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\NodeHandlerInterface;
use App\Services\Automation\NodeResult;
use App\Services\Automation\VariableInterpolator;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

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

        [$ssrfError, $resolvedIp] = $this->validateUrl($url);
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
        $maxBytes = max(1024, (int) config('whatsapp.http_node.max_response_bytes', 524288));
        $parts = parse_url($url);
        $host = (string) ($parts['host'] ?? '');
        $port = (int) ($parts['port'] ?? (($parts['scheme'] ?? 'https') === 'https' ? 443 : 80));

        $options = [
            'allow_redirects' => false,
            'stream' => true,
            'on_headers' => function (ResponseInterface $response) use ($maxBytes): void {
                $length = (int) $response->getHeaderLine('Content-Length');
                if ($length > 0 && $length > $maxBytes) {
                    throw new RuntimeException('HTTP node response exceeds the configured size limit.');
                }
            },
        ];

        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            if (! defined('CURLOPT_RESOLVE')) {
                return NodeResult::fail('DNS-safe HTTP requests require the PHP cURL extension.');
            }
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$resolvedIp}"]];
        }

        try {
            $pending = Http::withHeaders($headers)
                ->connectTimeout(min(5, max(1, $timeout)))
                ->timeout(max(1, $timeout))
                ->withOptions($options);

            $response = match ($method) {
                'GET' => $pending->get($url, $query),
                'DELETE' => $pending->delete($url.$this->queryString($query), is_array($body) ? $body : []),
                default => $pending->withQueryParameters($query)->send($method, $url, [
                    is_array($body) ? 'json' : 'body' => $body ?? [],
                ]),
            };

            $stream = $response->toPsrResponse()->getBody();
            $bodyText = '';
            while (! $stream->eof() && strlen($bodyText) <= $maxBytes) {
                $remaining = ($maxBytes + 1) - strlen($bodyText);
                $bodyText .= $stream->read(min(8192, $remaining));
            }

            if (strlen($bodyText) > $maxBytes || ! $stream->eof()) {
                return NodeResult::next('error', ['error' => 'response_too_large']);
            }
        } catch (\Throwable $e) {
            return NodeResult::next('error', ['error' => mb_substr($e->getMessage(), 0, 500)]);
        }

        $json = json_decode($bodyText, true) ?? [];

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

    /** @return array{0: ?string, 1: ?string} */
    protected function validateUrl(string $url): array
    {
        $parts = parse_url($url);
        if (! $parts || empty($parts['host'])) {
            return ['HTTP node URL is invalid.', null];
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, config('whatsapp.http_node.allowed_schemes', ['http', 'https']), true)) {
            return ["URL scheme [{$scheme}] is not allowed.", null];
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['Credentials in HTTP node URLs are not allowed.', null];
        }

        $host = $parts['host'];
        $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
        if ($ips === []) {
            return ['URL host could not be resolved.', null];
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return ['Requests to private or reserved network addresses are blocked.', null];
            }
        }

        // Pin one already-validated address for the actual request. CURLOPT_RESOLVE
        // prevents a second DNS lookup from being redirected to a private address.
        return [null, $ips[0]];
    }

    protected function queryString(array $query): string
    {
        return $query === [] ? '' : '?'.http_build_query($query);
    }
}
