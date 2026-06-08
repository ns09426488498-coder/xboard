<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('v2_outline_access_keys')) {
            return;
        }

        Schema::create('v2_outline_access_keys', function (Blueprint $table) {
            $table->id();
            $table->integer('server_id');
            $table->integer('user_id');
            $table->string('access_key_id');
            $table->string('name')->nullable();
            $table->text('access_url');
            $table->text('api_url')->nullable();
            $table->string('cert_sha256')->nullable();
            $table->string('method')->nullable();
            $table->string('password')->nullable();
            $table->integer('port')->nullable();
            $table->unsignedBigInteger('data_limit_bytes')->nullable();
            $table->unsignedBigInteger('remote_data_usage_bytes')->default(0);
            $table->integer('last_synced_at')->nullable();
            $table->integer('created_at');
            $table->integer('updated_at');

            $table->unique(['server_id', 'user_id'], 'outline_server_user_unique');
            $table->index('access_key_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('v2_outline_access_keys');
    }
};
