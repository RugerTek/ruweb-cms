<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Variants of a base-language product, each with its own sku, price and stock. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku', 100)->nullable()->unique();
            $table->string('name', 255);
            $table->json('attributes_json')->nullable();
            $table->unsignedBigInteger('price_amount')->nullable();
            $table->unsignedBigInteger('compare_at_price_amount')->nullable();
            $table->integer('stock')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_variants');
    }
};
