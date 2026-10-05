<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bind a download grant to the exact asset it authorised, so redeeming streams
 * what was granted even if the product's files change in between — and so the
 * audit trail can name the file by id without ever storing its object key.
 *
 * No foreign-key constraint: SQLite (the test connection) cannot add one to an
 * existing table. The column is nullable for grants issued before this phase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('download_grants', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id')->nullable()->after('entitlement_id');
            $table->index('asset_id');
        });
    }

    public function down(): void
    {
        Schema::table('download_grants', function (Blueprint $table) {
            $table->dropIndex(['asset_id']);
            $table->dropColumn('asset_id');
        });
    }
};
