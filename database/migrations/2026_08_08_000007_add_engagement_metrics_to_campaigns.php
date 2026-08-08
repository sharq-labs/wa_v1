<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->unsignedInteger('replied_count')->default(0)->after('read_count');
            $table->unsignedInteger('unique_click_count')->default(0)->after('replied_count');
            $table->unsignedInteger('conversion_count')->default(0)->after('unique_click_count');
            $table->decimal('conversion_value', 18, 4)->default(0)->after('conversion_count');
        });

        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->timestamp('replied_at')->nullable()->after('sent_at');
            $table->unsignedInteger('click_count')->default(0)->after('replied_at');
            $table->timestamp('first_clicked_at')->nullable()->after('click_count');
            $table->timestamp('last_clicked_at')->nullable()->after('first_clicked_at');
            $table->timestamp('converted_at')->nullable()->after('last_clicked_at');
            $table->string('conversion_name', 120)->nullable()->after('converted_at');
            $table->decimal('conversion_value', 18, 4)->nullable()->after('conversion_name');
            $table->index(['contact_id', 'sent_at']);
            $table->index(['campaign_id', 'replied_at']);
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex(['contact_id', 'sent_at']);
            $table->dropIndex(['campaign_id', 'replied_at']);
            $table->dropColumn([
                'replied_at',
                'click_count',
                'first_clicked_at',
                'last_clicked_at',
                'converted_at',
                'conversion_name',
                'conversion_value',
            ]);
        });

        Schema::table('campaigns', function (Blueprint $table) {
            $table->dropColumn(['replied_count', 'unique_click_count', 'conversion_count', 'conversion_value']);
        });
    }
};
