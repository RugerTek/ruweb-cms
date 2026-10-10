<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('editorial_versions', function (Blueprint $t) {
            $t->id();
            $t->string('content_type');
            $t->unsignedBigInteger('content_id');
            $t->unsignedBigInteger('user_id')->nullable();
            $t->boolean('is_proposal')->default(false);
            $t->json('payload');
            $t->string('fingerprint', 64);
            $t->timestamps();
            $t->index(['content_type', 'content_id']);
        });
        Schema::create('editorial_drafts', function (Blueprint $t) {
            $t->id();
            $t->string('content_type');
            $t->unsignedBigInteger('content_id');
            $t->unsignedBigInteger('user_id');
            $t->json('payload');
            $t->string('base_fingerprint', 64);
            $t->timestamp('expires_at');
            $t->timestamps();
            $t->unique(['content_type', 'content_id', 'user_id']);
        });
        Schema::create('editorial_reviews', function (Blueprint $t) {
            $t->id();
            $t->string('content_type');
            $t->unsignedBigInteger('content_id');
            $t->unsignedBigInteger('requested_by');
            $t->unsignedBigInteger('reviewer_id');
            $t->unsignedBigInteger('version_id')->nullable();
            $t->string('fingerprint', 64);
            $t->string('state')->default('pending');
            $t->text('comment')->nullable();
            $t->timestamps();
        });
        Schema::create('editorial_preview_links', function (Blueprint $t) {
            $t->id();
            $t->string('content_type');
            $t->unsignedBigInteger('content_id');
            $t->string('token_hash', 64)->unique();
            $t->unsignedBigInteger('version_id')->nullable();
            $t->unsignedBigInteger('user_id');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
        });
        Schema::create('editorial_review_comments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('review_id');
            $t->unsignedBigInteger('user_id');
            $t->text('body');
            $t->timestamps();
            $t->index('review_id');
        });
        foreach (['pages', 'posts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->timestamp('expires_at')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['pages', 'posts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('expires_at'));
        }
        foreach (['editorial_review_comments', 'editorial_preview_links', 'editorial_reviews', 'editorial_drafts', 'editorial_versions'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
