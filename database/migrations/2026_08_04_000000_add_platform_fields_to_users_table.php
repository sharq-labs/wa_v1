<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('password');
            $table->unsignedBigInteger('current_workspace_id')->nullable()->after('is_super_admin');
            $table->string('locale', 10)->default('en')->after('current_workspace_id');
            $table->string('avatar')->nullable()->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('avatar');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_super_admin', 'current_workspace_id', 'locale', 'avatar', 'last_login_at']);
        });
    }
};
