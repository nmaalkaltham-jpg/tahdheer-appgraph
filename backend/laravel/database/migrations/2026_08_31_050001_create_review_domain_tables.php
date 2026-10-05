<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reviews-builder schema gaps (MIP §4.7). The core `reviews` +
 * `review_aggregates` tables already exist; this migration adds only what the
 * domain genuinely needs and cannot express today:
 *
 *  1. ONE review per customer per product. The original unique was
 *     (product_id, customer_id, order_id) — with a NULLABLE order_id that
 *     enforces nothing: NULLs never collide, and a customer who bought the
 *     same product on two orders could publish two reviews. Replaced by
 *     (product_id, customer_id).
 *  2. `variant_purchased` — listed in §4.7 but absent from the table.
 *  3. `helpful_count` — denormalized so sort=helpful is an index scan, not a
 *     per-row aggregate. Rebuilt from review_votes, never hand-edited.
 *  4. `moderated_at` / `moderation_note` — honest audit of when a human
 *     decided and why (internal; never rendered to customers).
 *  5. review_replies / review_votes / review_media / review_category_scores.
 */
return new class extends Migration
{
    /** The 6 criteria of §4.7 — new reviews only, no backfill. */
    public const CRITERIA = [
        'content_quality',    // جودة المحتوى
        'edit_ease',          // سهولة التعديل
        'design_quality',     // جودة التصميم
        'worksheets_value',   // فائدة أوراق العمل
        'description_match',  // مطابقة الوصف
        'download_ease',      // سهولة التحميل
    ];

    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropUnique(['product_id', 'customer_id', 'order_id']);
            $table->unique(['product_id', 'customer_id']);

            $table->string('variant_purchased')->nullable()->after('order_id');
            $table->unsignedInteger('helpful_count')->default(0)->after('status');
            $table->timestamp('moderated_at')->nullable()->after('helpful_count');
            $table->string('moderation_note')->nullable()->after('moderated_at');

            // Public feed: approved rows of one product, ordered by either axis.
            $table->index(['product_id', 'status', 'created_at']);
            $table->index(['product_id', 'status', 'helpful_count']);
        });

        // One store reply per review, always signed with the brand name.
        Schema::create('review_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->unique()->constrained()->cascadeOnDelete();
            $table->text('body');
            $table->string('author_label'); // «التحاضير الحديثة»
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        // Helpful votes — unique per customer per review (rate-limited at the route).
        Schema::create('review_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['review_id', 'customer_id']);
        });

        // Customer-supplied media — moderated before publish, exactly like the
        // review body. Only assets already in the ledger; no free-text URLs.
        Schema::create('review_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->cascadeOnDelete();
            $table->string('alt')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['review_id', 'asset_id']);
        });

        // Per-criterion scores. Optional: a review with none simply has no
        // category breakdown — the section omits the block rather than zero it.
        Schema::create('review_category_scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('review_id')->constrained()->cascadeOnDelete();
            $table->string('criterion', 32);
            $table->unsignedTinyInteger('score');
            $table->timestamps();
            $table->unique(['review_id', 'criterion']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE review_category_scores ADD CONSTRAINT review_category_scores_range CHECK (score BETWEEN 1 AND 5)');
            $list = implode(', ', array_map(fn ($c) => "'{$c}'", self::CRITERIA));
            DB::statement("ALTER TABLE review_category_scores ADD CONSTRAINT review_category_scores_criterion CHECK (criterion IN ({$list}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('review_category_scores');
        Schema::dropIfExists('review_media');
        Schema::dropIfExists('review_votes');
        Schema::dropIfExists('review_replies');

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropIndex(['product_id', 'status', 'helpful_count']);
            $table->dropIndex(['product_id', 'status', 'created_at']);
            $table->dropColumn(['variant_purchased', 'helpful_count', 'moderated_at', 'moderation_note']);
            $table->dropUnique(['product_id', 'customer_id']);
            $table->unique(['product_id', 'customer_id', 'order_id']);
        });
    }
};
