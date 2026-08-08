<?php

namespace App\Services\Messaging;

use App\Models\WhatsAppAccount;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MetaMediaDownloader
{
    public function download(WhatsAppAccount $account, string $mediaId): array
    {
        if ($mediaId === '') {
            throw new RuntimeException('Meta media id is missing.');
        }

        $metadata = Http::withToken($account->access_token)
            ->acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->retry(2, 250, throw: false)
            ->get($this->graphUrl($mediaId));

        if (! $metadata->successful()) {
            throw new RuntimeException((string) ($metadata->json('error.message') ?? 'Meta media metadata request failed.'));
        }

        $url = trim((string) $metadata->json('url'));
        $mimeType = trim((string) ($metadata->json('mime_type') ?: 'application/octet-stream'));
        $declaredSize = (int) ($metadata->json('file_size') ?? 0);
        $maxBytes = (int) config('whatsapp.inbound_media.max_bytes', 32 * 1024 * 1024);

        $this->assertAllowedMediaUrl($url);
        if ($declaredSize > 0 && $declaredSize > $maxBytes) {
            throw new RuntimeException('Inbound WhatsApp media exceeds the configured size limit.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'wa-media-');
        if ($tempPath === false) {
            throw new RuntimeException('Unable to create temporary media file.');
        }

        try {
            $response = Http::withToken($account->access_token)
                ->connectTimeout(5)
                ->timeout((int) config('whatsapp.inbound_media.download_timeout', 45))
                ->withOptions(['sink' => $tempPath])
                ->get($url);

            if (! $response->successful()) {
                throw new RuntimeException('Meta media download failed with HTTP '.$response->status().'.');
            }

            clearstatcache(true, $tempPath);
            $actualSize = (int) (filesize($tempPath) ?: 0);
            if ($actualSize <= 0) {
                throw new RuntimeException('Meta returned an empty media file.');
            }
            if ($actualSize > $maxBytes) {
                throw new RuntimeException('Downloaded WhatsApp media exceeds the configured size limit.');
            }

            $responseMime = trim((string) $response->header('Content-Type'));
            if ($responseMime !== '') {
                $mimeType = explode(';', $responseMime, 2)[0];
            }

            if (! $this->isAllowedMime($mimeType)) {
                throw new RuntimeException('Unsupported inbound WhatsApp media type: '.$mimeType);
            }

            $extension = $this->extensionForMime($mimeType);
            $path = sprintf(
                'whatsapp/inbound/%d/%s/%s.%s',
                $account->workspace_id,
                now()->format('Y/m'),
                Str::uuid(),
                $extension,
            );
            $diskName = (string) config('whatsapp.media_disk', 'public');
            $disk = Storage::disk($diskName);
            $stream = fopen($tempPath, 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to read downloaded media file.');
            }

            try {
                if (! $disk->put($path, $stream)) {
                    throw new RuntimeException('Unable to persist inbound WhatsApp media.');
                }
            } finally {
                fclose($stream);
            }

            return [
                'url' => $disk->url($path),
                'path' => $path,
                'disk' => $diskName,
                'mime_type' => $mimeType,
                'size' => $actualSize,
                'media_id' => $mediaId,
            ];
        } finally {
            @unlink($tempPath);
        }
    }

    protected function graphUrl(string $path): string
    {
        $base = rtrim((string) config('meta.graph_base_url'), '/');
        $version = (string) config('meta.graph_api_version');

        return "{$base}/{$version}/".ltrim($path, '/');
    }

    protected function assertAllowedMediaUrl(string $url): void
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        if (($parts['scheme'] ?? '') !== 'https' || $host === '') {
            throw new RuntimeException('Meta returned an invalid media download URL.');
        }

        $allowedSuffixes = config('whatsapp.inbound_media.allowed_host_suffixes', []);
        $allowed = false;
        foreach ($allowedSuffixes as $suffix) {
            $suffix = ltrim(strtolower((string) $suffix), '.');
            if ($host === $suffix || str_ends_with($host, '.'.$suffix)) {
                $allowed = true;
                break;
            }
        }

        if (! $allowed) {
            throw new RuntimeException('Meta returned a media URL from an untrusted host.');
        }
    }

    protected function isAllowedMime(string $mime): bool
    {
        foreach (config('whatsapp.inbound_media.allowed_mime_prefixes', []) as $allowed) {
            if (str_ends_with((string) $allowed, '/') && str_starts_with($mime, (string) $allowed)) {
                return true;
            }
            if ($mime === $allowed) {
                return true;
            }
        }

        return false;
    }

    protected function extensionForMime(string $mime): string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
            'audio/ogg' => 'ogg',
            'audio/aac' => 'aac',
            'audio/mp4' => 'm4a',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            default => 'bin',
        };
    }
}
