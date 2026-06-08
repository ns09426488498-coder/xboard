<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v2_outline_access_keys')) {
            return;
        }

        Schema::table('v2_outline_access_keys', function (Blueprint $table) {
            if (!Schema::hasColumn('v2_outline_access_keys', 'api_url')) {
                $table->text('api_url')->nullable()->after('access_url');
            }
            if (!Schema::hasColumn('v2_outline_access_keys', 'cert_sha256')) {
                $table->string('cert_sha256')->nullable()->after('api_url');
            }
            if (!Schema::hasColumn('v2_outline_access_keys', 'data_limit_bytes')) {
                $table->unsignedBigInteger('data_limit_bytes')->nullable()->after('port');
            }
            if (!Schema::hasColumn('v2_outline_access_keys', 'remote_data_usage_bytes')) {
                $table->unsignedBigInteger('remote_data_usage_bytes')->default(0)->after('data_limit_bytes');
            }
            if (!Schema::hasColumn('v2_outline_access_keys', 'last_synced_at')) {
                $table->integer('last_synced_at')->nullable()->after('remote_data_usage_bytes');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('v2_outline_access_keys')) {
            return;
        }

        Schema::table('v2_outline_access_keys', function (Blueprint $table) {
            $columns = [
                'api_url',
                'cert_sha256',
                'data_limit_bytes',
                'remote_data_usage_bytes',
                'last_synced_at',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('v2_outline_access_keys', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
