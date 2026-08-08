<?php

namespace App\Jobs;

use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\CustomField;
use App\Models\Tag;
use App\Services\Contacts\SpreadsheetContactReader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class ProcessContactImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public readonly int $importId)
    {
        $this->onQueue('default');
    }

    public function handle(SpreadsheetContactReader $reader): void
    {
        $import = ContactImport::query()->with('workspace')->find($this->importId);
        if (! $import || ! in_array($import->status, ['queued', 'processing'], true)) {
            return;
        }

        $import->update([
            'status' => 'processing',
            'started_at' => $import->started_at ?? now(),
            'imported_count' => 0,
            'updated_count' => 0,
            'skipped_count' => 0,
            'failed_count' => 0,
            'errors' => [],
        ]);

        $path = Storage::disk($import->disk)->path($import->path);
        $extension = strtolower(pathinfo($import->original_filename, PATHINFO_EXTENSION));
        $mapping = $import->mapping ?? [];
        $options = $import->options ?? [];
        $updateExisting = (bool) ($options['update_existing'] ?? true);
        $overwriteEmpty = (bool) ($options['overwrite_empty'] ?? false);
        $accountId = isset($options['whatsapp_account_id']) ? (int) $options['whatsapp_account_id'] : null;
        $tagIds = collect($options['tag_ids'] ?? [])->map(fn ($id) => (int) $id)->filter()->unique()->values()->all();
        $tagIds = Tag::query()
            ->where('workspace_id', $import->workspace_id)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->all();
        $customFields = CustomField::query()
            ->where('workspace_id', $import->workspace_id)
            ->get()
            ->keyBy('key');

        $counts = ['imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
        $errors = [];
        $rowNumber = 1;

        try {
            foreach ($reader->rows($path, $extension) as $row) {
                $rowNumber++;
                try {
                    $phone = $this->normalisePhone($this->mapped($row, $mapping, 'phone_number'));
                    if ($phone === null) {
                        throw new RuntimeException('A valid phone number is required.');
                    }

                    $contact = Contact::query()
                        ->where('workspace_id', $import->workspace_id)
                        ->where(function ($query) use ($phone) {
                            $query->where('phone_number', $phone)->orWhere('wa_id', $phone);
                        })
                        ->first();

                    $attributes = $this->contactAttributes($row, $mapping, $overwriteEmpty);
                    if ($accountId && $import->workspace->whatsappAccounts()->whereKey($accountId)->exists()) {
                        $attributes['whatsapp_account_id'] = $accountId;
                    }

                    if ($contact) {
                        if (! $updateExisting) {
                            $counts['skipped']++;

                            continue;
                        }
                        if ($attributes !== []) {
                            $contact->fill($attributes)->save();
                        }
                        $counts['updated']++;
                    } else {
                        $contact = Contact::query()->create($attributes + [
                            'workspace_id' => $import->workspace_id,
                            'phone_number' => $phone,
                            'wa_id' => $phone,
                        ]);
                        $counts['imported']++;
                    }

                    if ($tagIds !== []) {
                        $contact->tags()->syncWithoutDetaching($tagIds);
                    }

                    foreach ($mapping as $target => $sourceHeader) {
                        if (! str_starts_with((string) $target, 'custom.')) {
                            continue;
                        }
                        $key = substr((string) $target, 7);
                        $field = $customFields->get($key);
                        if (! $field) {
                            continue;
                        }
                        $value = trim((string) ($row[$sourceHeader] ?? ''));
                        if ($value === '' && ! $overwriteEmpty) {
                            continue;
                        }
                        $contact->setCustomFieldValue($field, $value !== '' ? $value : null);
                    }
                } catch (Throwable $e) {
                    $counts['failed']++;
                    if (count($errors) < 50) {
                        $errors[] = [
                            'row' => $rowNumber,
                            'message' => mb_substr($e->getMessage(), 0, 300),
                        ];
                    }
                }
            }

            $import->update([
                'status' => 'completed',
                'imported_count' => $counts['imported'],
                'updated_count' => $counts['updated'],
                'skipped_count' => $counts['skipped'],
                'failed_count' => $counts['failed'],
                'errors' => $errors,
                'completed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $import->update([
                'status' => 'failed',
                'errors' => [['row' => null, 'message' => mb_substr($e->getMessage(), 0, 500)]],
                'completed_at' => now(),
            ]);
            throw $e;
        }
    }

    protected function mapped(array $row, array $mapping, string $target): ?string
    {
        $source = $mapping[$target] ?? null;
        if (! is_string($source) || $source === '') {
            return null;
        }

        return isset($row[$source]) ? trim((string) $row[$source]) : null;
    }

    protected function contactAttributes(array $row, array $mapping, bool $overwriteEmpty): array
    {
        $attributes = [];
        foreach (['first_name', 'last_name', 'display_name', 'email', 'country', 'language'] as $field) {
            $value = $this->mapped($row, $mapping, $field);
            if (($value === null || $value === '') && ! $overwriteEmpty) {
                continue;
            }
            $attributes[$field] = $value !== '' ? $value : null;
        }

        $consent = mb_strtolower((string) ($this->mapped($row, $mapping, 'opt_in_status') ?? ''));
        if ($consent !== '') {
            if (in_array($consent, ['1', 'yes', 'true', 'opted_in', 'subscribed', 'نعم', 'موافق'], true)) {
                $attributes['opt_in_status'] = 'opted_in';
                $attributes['opt_in_at'] = now();
                $attributes['consent_source'] = 'contact_import';
            } elseif (in_array($consent, ['0', 'no', 'false', 'opted_out', 'unsubscribed', 'لا', 'غير موافق'], true)) {
                $attributes['opt_in_status'] = 'opted_out';
                $attributes['opt_out_at'] = now();
                $attributes['consent_source'] = 'contact_import';
            }
        }

        return $attributes;
    }

    protected function normalisePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 8 && strlen($digits) <= 20 ? $digits : null;
    }
}
