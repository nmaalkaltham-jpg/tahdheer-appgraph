<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Independent access ledger — never derived from order rows at request time.
        Schema::create('entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('version_label')->nullable();
            $table->timestamp('granted_at');
            $table->timestamp('expires_at')->nullable(); // null = permanent access
            $table->unsignedInteger('download_limit')->nullable(); // null = unlimited
            $table->unsignedInteger('download_count')->default(0);
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'REVOKED', 'SUSPENDED'])->default('ACTIVE');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason')->nullable();
            $table->timestamps();
            $table->index(['customer_id', 'status']);
            $table->unique('order_item_id');
        });

        Schema::create('download_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamps();
        });

        // Append-only audit.
        Schema::create('download_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event_type'); // granted|viewed|link-generated|completed|copy-created|revoked
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('download_events');
        Schema::dropIfExists('download_grants');
        Schema::dropIfExists('entitlements');
    }
};
