<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shop catalog (loaded by ShopServiceProvider only while cms.shop.mode is not off). Translations are rows in the
 * same translation group, like posts; sku, prices, stock and variants belong to the base-language row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 10)->default(config('cms.locales.default', 'es'))->index();
            $table->uuid('translation_group_id')->nullable()->index();
            $table->string('translation_status', 30)->nullable()->index();
            $table->string('source_fingerprint', 64)->nullable();
            $table->json('source_field_hashes')->nullable();
            $table->string('sku', 100)->nullable()->unique();
            $table->string('title', 255);
            $table->string('slug', 255);
            $table->text('excerpt')->nullable();
            $table->longText('description')->nullable();
            // Integer amounts in cms.shop.currency; null = catalog item without a public price.
            $table->unsignedBigInteger('price_amount')->nullable();
            $table->unsignedBigInteger('compare_at_price_amount')->nullable();
            $table->boolean('has_variants')->default(false);
            $table->boolean('track_stock')->default(false);
            $table->boolean('allow_backorder')->default(false);
            $table->integer('stock')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->json('dimensions_json')->nullable();
            $table->json('meta_json')->nullable();
            $table->string('status', 20)->default('draft')->index();
            $table->timestamp('published_at')->nullable()->index();
            $table->timestamp('expires_at')->nullable()->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('created_at');
            $table->unique(['locale', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
