<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('page_id')->nullable()->index();
            $table->string('locale', 10)->nullable();
            // Form fields (code-owned, see docs/forms.md)
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 60)->nullable();
            $table->string('subject', 190)->nullable();
            $table->text('message');
            // Metadata
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->string('referer', 500)->nullable();
            $table->decimal('recaptcha_score', 3, 2)->nullable();
            $table->timestamp('read_at')->nullable()->index();
            $table->timestamp('notified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_submissions');
    }
};
