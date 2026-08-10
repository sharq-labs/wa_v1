<?php

use App\Jobs\ProcessContactImport;
use App\Models\Contact;
use App\Models\ContactImport;
use App\Models\Plan;
use App\Models\Subscription;
use App\Services\Billing\EntitlementsService;
use App\Services\Contacts\SpreadsheetContactReader;
use Illuminate\Support\Facades\Storage;

it('does not let CSV imports exceed the workspace contact plan limit', function () {
    $ctx = createWorkspaceContext();

    $plan = Plan::query()->create([
        'name' => 'One Contact',
        'slug' => 'one-contact-'.uniqid(),
        'is_active' => true,
    ]);
    $plan->features()->create(['key' => 'contacts', 'value' => '1']);
    Subscription::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    $path = 'contact-imports/qa-limit.csv';
    Storage::disk('local')->put($path, "phone,name\n201000000001,Alice\n201000000002,Bob\n");

    $import = ContactImport::query()->create([
        'workspace_id' => $ctx['workspace']->id,
        'created_by' => $ctx['user']->id,
        'original_filename' => 'qa-limit.csv',
        'disk' => 'local',
        'path' => $path,
        'status' => 'queued',
        'headers' => ['phone', 'name'],
        'mapping' => [
            'phone_number' => 'phone',
            'first_name' => 'name',
        ],
        'options' => ['update_existing' => true],
        'total_rows' => 2,
    ]);

    (new ProcessContactImport($import->id))->handle(
        app(SpreadsheetContactReader::class),
        app(EntitlementsService::class),
    );

    $import->refresh();

    expect(Contact::query()->where('workspace_id', $ctx['workspace']->id)->count())->toBe(1)
        ->and($import->status)->toBe('completed')
        ->and($import->imported_count)->toBe(1)
        ->and($import->skipped_count)->toBe(1)
        ->and($import->failed_count)->toBe(0)
        ->and($import->errors[0]['message'] ?? null)->toContain('plan contact limit');
});
