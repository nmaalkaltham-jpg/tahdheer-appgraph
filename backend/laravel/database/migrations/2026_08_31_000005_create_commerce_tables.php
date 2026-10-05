<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('token')->nullable()->unique(); // guest carts
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty')->default(1); // digital lines qty-locked to 1 at service level
            $table->integer('unit_price_snapshot_minor')->nullable();
            $table->timestamps();
            $table->unique(['cart_id', 'product_id']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->enum('type', ['percent', 'fixed']);
            $table->integer('value'); // percent points or fixed minor units
            $table->boolean('first_order_per_account')->default(false);
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique(); // TD-…
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('phone')->nullable();
            $table->enum('status', [
                'PENDING', 'AWAITING_PAYMENT', 'PAID', 'PROCESSING', 'COMPLETED',
                'FAILED', 'CANCELLED', 'REFUNDED', 'PARTIALLY_REFUNDED',
            ])->default('PENDING');
            $table->integer('totals_gross_minor')->default(0);
            $table->integer('discount_minor')->default(0);
            $table->integer('coupon_minor')->default(0);
            $table->integer('total_minor')->default(0);
            $table->integer('vat_minor')->default(0); // extracted, never added
            $table->string('order_key_hash', 64)->nullable(); // HMAC guest access
            $table->string('tax_org_name')->nullable();
            $table->string('tax_vat_number', 15)->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('title_snapshot');
            $table->integer('price_snapshot_minor');
            $table->string('version_label')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('provider'); // tap first
            $table->string('intent_id')->nullable();
            $table->string('method')->nullable(); // mada|visa|mc|apple_pay|bank_transfer
            $table->string('masked_instrument')->nullable();
            $table->string('state')->default('CREATED');
            $table->string('idempotency_key')->unique();
            $table->timestamps();
        });

        // Append-only.
        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('event_type');
            $table->json('payload')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->integer('amount_minor');
            $table->string('reason')->nullable();
            $table->string('revocation_policy')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
