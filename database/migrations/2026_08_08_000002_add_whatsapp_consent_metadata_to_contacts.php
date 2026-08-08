<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->timestamp('opt_in_at')->nullable()->after('opt_in_status');
            $table->timestamp('opt_out_at')->nullable()->after('opt_in_at');
            $table->string('consent_source', 50)->nullable()->after('opt_out_at');
            $table->index(['workspace_id', 'opt_in_status'], 'contacts_workspace_opt_in_idx');
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropIndex('contacts_workspace_opt_in_idx');
            $table->dropColumn(['opt_in_at', 'opt_out_at', 'consent_source']);
        });
    }
};
