<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per connector (`cms.connectors` key), created the first time the administrator activates it. Always
 * created, like the shop tables: a site without connectors keeps it empty. See PLAN.md §4.2 and §4.7.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            $table->boolean('enabled')->default(false);
            $table->json('settings_json')->nullable();
            $table->string('token_hash', 64)->nullable();
            $table->text('token')->nullable();
            $table->timestamp('token_rotated_at')->nullable();
            $table->timestamp('last_fetched_at')->nullable();
            $table->string('last_fetch_agent', 255)->nullable();
            $table->timestamp('last_meta_fetched_at')->nullable();
            $table->unsignedInteger('last_item_count')->nullable();
            $table->unsignedInteger('last_skipped_count')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connectors');
    }
};
