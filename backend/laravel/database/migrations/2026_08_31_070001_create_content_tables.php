<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Content-domain schema (MIP §3.3 homepage).
 *
 * Two additions, both there so the storefront can stop guessing:
 *
 *  1. `campaigns` — the promo strip's deadline has to be a fact. A row owns a
 *     real window, `ends_at` is NOT NULL, and the endpoint filters on it, so a
 *     finished campaign leaves the homepage without anyone touching a switch.
 *     Editorial COPY (hero, why, faq, cta) needs none of this and lives in the
 *     existing `settings` table under `home.*` — see App\Domain\Content\HomeContent.
 *
 *  2. `products.featured` — an explicit editorial flag, default FALSE. The
 *     «منتجات مختارة» rail had no truthful source before: ordering by newest
 *     and calling it «featured» would have been a lie with a nice label. Now a
 *     product is featured only because someone marked it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('description', 500)->nullable();
            $table->string('url');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at');
            $table->timestamps();
            $table->index(['ends_at', 'starts_at']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('featured')->default(false)->after('status');
            $table->index(['status', 'featured']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE campaigns ADD CONSTRAINT campaigns_window_ordered CHECK (starts_at IS NULL OR starts_at < ends_at)');
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropIndex(['status', 'featured']);
            $table->dropColumn('featured');
        });

        Schema::dropIfExists('campaigns');
    }
};
