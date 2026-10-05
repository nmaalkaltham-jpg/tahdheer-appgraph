<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * نطاق SEO (MIP الجزء ٧) — يكمل جداول المنصّة القائمة ولا يعيد إنشاءها.
 *
 * SEO domain tables. `redirect_rules` and `not_found_hits` already exist from
 * the platform migration; they are EXTENDED here with the MIP §7.7 fields
 * rather than recreated (existing migrations are immutable).
 */
return new class extends Migration
{
    public function up(): void
    {
        // §7.7: rules can be disabled without deletion, and every rule carries
        // the human reason it exists (audit requirement).
        Schema::table('redirect_rules', function (Blueprint $table) {
            $table->boolean('enabled')->default(true);
            $table->string('reason')->nullable();
        });

        // §7.7: the 404 aggregate is keyed by url_hash so arbitrarily long URLs
        // aggregate safely, and keeps a first-seen timestamp for age reporting.
        Schema::table('not_found_hits', function (Blueprint $table) {
            $table->string('url_hash', 64)->nullable();
            $table->timestamp('first_seen_at')->nullable();
        });

        Schema::table('not_found_hits', function (Blueprint $table) {
            $table->unique('url_hash');
        });

        /*
         * §7.3.7 truthful lastmod. lastmod is NEVER the regeneration time: the
         * ledger stores the content hash of the fields a reader would notice
         * (title/description/price/availability) and only moves lastmod_at when
         * that hash actually changes. A rebuild with no content change is a
         * no-op for every timestamp in this table.
         */
        Schema::create('sitemap_url_states', function (Blueprint $table) {
            $table->id();
            $table->string('provider', 64);
            $table->string('loc');
            $table->string('content_hash', 64);
            $table->timestamp('lastmod_at');
            $table->timestamps();

            $table->unique('loc');
            $table->index('provider');
        });

        /*
         * §7.4: FAQPage is emitted ONLY from real rows that the page renders.
         * There is no generator and no default seed — an empty table means no
         * FAQPage node, never a fabricated question.
         */
        Schema::create('product_faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('question', 500);
            $table->text('answer');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['product_id', 'position']);
        });

        /*
         * §7.4: ProfilePage/Person only for real, consented authors. `consented`
         * defaults to false — an author row without explicit consent never
         * reaches the markup.
         */
        Schema::create('product_authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->text('bio')->nullable();
            $table->string('profile_url')->nullable();
            $table->boolean('consented')->default(false);
            $table->timestamps();

            $table->index('product_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_authors');
        Schema::dropIfExists('product_faqs');
        Schema::dropIfExists('sitemap_url_states');

        Schema::table('not_found_hits', function (Blueprint $table) {
            $table->dropUnique(['url_hash']);
            $table->dropColumn(['url_hash', 'first_seen_at']);
        });

        Schema::table('redirect_rules', function (Blueprint $table) {
            $table->dropColumn(['enabled', 'reason']);
        });
    }
};
