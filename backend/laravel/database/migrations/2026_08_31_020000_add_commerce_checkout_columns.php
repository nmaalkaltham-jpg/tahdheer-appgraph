<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Commerce-builder schema gaps: applied coupon on carts; paid/consent
// audit columns on orders. Core tables already exist.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->timestamp('paid_at')->nullable();
            $table->boolean('marketing_opt_in')->default(false);
            $table->timestamp('policy_consent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('carts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('coupon_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['paid_at', 'marketing_opt_in', 'policy_consent_at']);
        });
    }
};
