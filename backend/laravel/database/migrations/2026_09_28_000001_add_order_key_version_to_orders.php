<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A per-order version for the guest access token.
 *
 * The token is derived (HMAC of number|email), not stored, so the only way to revoke one
 * leaked token without rotating the app key for every order is to change what is derived
 * for that order. Version 0 is the original formula: every existing token stays valid.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedSmallInteger('order_key_version')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn('order_key_version');
        });
    }
};
