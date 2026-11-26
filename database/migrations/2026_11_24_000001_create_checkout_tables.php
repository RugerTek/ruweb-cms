<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cart, checkout, orders and payments (PLAN.md, stage 3, E3.2 and E3.5). Always created, like the catalog tables:
 * a site that never sells keeps them empty, and turning the shop off never drops them. Amounts are integers in the
 * row's `currency` (cms.shop.currency), like products. Status columns are enums (CHECK constraints on SQLite) with
 * every value of the state machines in App\Models\Order::TRANSITIONS and App\Models\Payment::TRANSITIONS.
 * No payment method rows here: 2026_11_24_000002_add_checkout_settings adds them, disabled and empty.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->unsignedBigInteger('price_amount')->default(0);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['enabled', 'sort_order']);
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            // Value of the `ruweb_cart` cookie (64 random characters).
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->cascadeOnDelete();
            // "{product_id}:{variant_id or 0}", filled by App\Models\CartItem: a unique index over a nullable
            // variant_id would let two rows of the same product without variant through (NULLs are distinct).
            $table->string('line_key', 50);
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['cart_id', 'line_key']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 40)->unique();
            $table->enum('status', ['pending', 'paid', 'fulfilled', 'cancelled', 'refunded'])->default('pending')->index();
            $table->string('locale', 10);
            // The page of the order is reached only by its random token: sha256 to find it, an encrypted copy to
            // send the link again. Never by number (PLAN.md R3).
            $table->string('access_token_hash', 64)->unique();
            $table->text('access_token');
            $table->string('customer_name', 255);
            $table->string('customer_email', 255)->index();
            $table->string('customer_phone', 50);
            $table->enum('delivery_method', ['pickup', 'delivery']);
            // Zone frozen at checkout: the name and price stay even if the zone changes or is deleted.
            $table->foreignId('shipping_zone_id')->nullable()->constrained('shipping_zones')->nullOnDelete();
            $table->string('shipping_zone_name', 120)->nullable();
            $table->string('payment_method_key', 64);
            $table->string('currency', 3);
            $table->unsignedBigInteger('subtotal_amount');
            $table->unsignedBigInteger('shipping_amount')->default(0);
            $table->unsignedBigInteger('total_amount');
            $table->text('notes')->nullable();
            $table->text('internal_notes')->nullable();
            // Stock is taken when the payment is confirmed (P3); null = not taken (or given back).
            $table->timestamp('stock_applied_at')->nullable();
            // What was missing when a paid order could not take its stock (PLAN.md E3.2 step 7).
            $table->json('stock_shortfall')->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->decimal('recaptcha_score', 3, 2)->nullable();
            $table->timestamp('placed_at')->index();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'placed_at']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // Products can be deleted while old orders keep their frozen copy.
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->foreignId('variant_id')->nullable()->constrained('product_variants')->nullOnDelete();
            $table->string('title', 255);
            $table->string('variant_name', 255)->nullable();
            $table->string('sku', 100)->nullable();
            $table->unsignedBigInteger('unit_price_amount');
            $table->unsignedInteger('quantity');
            $table->unsignedBigInteger('line_total_amount');
            $table->timestamps();
        });

        Schema::create('order_addresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            // One `shipping` row when the order is delivered; pickup orders have none.
            $table->enum('type', ['shipping'])->default('shipping');
            $table->string('address', 255);
            $table->string('city', 120);
            $table->string('department', 120)->nullable();
            $table->text('reference')->nullable();
            $table->timestamps();

            $table->unique(['order_id', 'type']);
        });

        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64)->unique();
            // Key of cms.shop.payment_providers (`manual`, `bancard`).
            $table->string('provider', 32);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            // Public data the customer may see (bank details, instructions).
            $table->json('settings_json')->nullable();
            // Credentials, `encrypted:array` (never in logs, MCP, views or snapshots in clear).
            $table->text('secrets')->nullable();
            $table->enum('environment', ['staging', 'production'])->nullable();
            // Site origin the secrets were saved under (App\Support\SiteUrl::origin(), PLAN.md R20).
            $table->string('bound_origin', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('method_key', 64);
            $table->string('provider', 32);
            $table->enum('status', ['pending', 'confirmed', 'failed', 'expired', 'refunded'])->default('pending')->index();
            $table->unsignedBigInteger('amount');
            $table->string('currency', 3);
            // Environment the attempt was created in: its notifications are verified with that environment's key.
            $table->enum('environment', ['staging', 'production'])->nullable();
            // Bancard shop_process_id (the id of this row, at most 15 digits); null for manual methods.
            $table->unsignedBigInteger('shop_process_id')->nullable()->unique();
            $table->string('process_id', 64)->nullable();
            $table->string('authorization_number', 64)->nullable();
            $table->string('ticket_number', 64)->nullable();
            $table->string('response_code', 16)->nullable();
            $table->string('response_description', 255)->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['provider', 'status', 'created_at']);
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            // Null when the notification names an unknown attempt.
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->string('provider', 32);
            $table->string('type', 40);
            $table->boolean('token_valid')->nullable();
            $table->json('payload_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['provider', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('payment_methods');
        Schema::dropIfExists('order_addresses');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
        Schema::dropIfExists('shipping_zones');
    }
};
