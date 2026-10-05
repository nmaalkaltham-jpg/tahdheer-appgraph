<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin platform (MIP Part 8): operator accounts + the append-only audit log.
 *
 * Operators are a separate identity space from `customers` (which is
 * passwordless by design) and from `users` (the framework skeleton table).
 * Only this table carries a password hash, and only for the operations
 * console — never for storefront accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('password_hash');
            $table->enum('role', [
                'owner', 'administrator', 'product_manager', 'order_manager',
                'seo_manager', 'search_manager', 'analytics', 'read_only',
            ])->default('read_only');
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        // Append-only (MIP §8.5): actor, action, entity, before/after, reason, IP.
        Schema::create('admin_audit_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('actor_email')->nullable(); // preserved even if the account is deleted
            $table->string('action');                  // product.published | order.refunded | …
            $table->string('entity_type');
            $table->string('entity_id')->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->string('reason')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['entity_type', 'entity_id']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_log');
        Schema::dropIfExists('admin_users');
    }
};
