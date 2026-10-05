<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * قمعُ الاكتشاف: بحث ← ظهور ← نقرة ← سلّة ← طلب.
 *
 * ─── search_queries (حدث البحث) ───
 * كلُّ ما يلزم لإعادة تفسير البحث لاحقًا ولا شيءَ من هويّة صاحبه:
 * المرشِّحات، والنتائج بترتيبها (الصفحة الأولى)، ونمطُ التعافي، والمصدر،
 * وفئةُ الجهاز، وتنويعةُ التجربة.
 *
 * ─── search_interactions (ظهورٌ ونقرة) ───
 * صفٌّ لكلّ (حدث بحث · منتج · نوع) — القيد الفريد هو ما يمنع العدّ المزدوج
 * مع إعادة التصيير والرجوع والتحديث والنقر المكرّر. بلا هويّة: ترتبط بالبحث لا بالشخص.
 *
 * ─── search_attributions (سلّة وطلب) ───
 * إسنادٌ على مستوى المنتج لا الطلب: سطرٌ لكلّ (حدث بحث · منتج · مرحلة · طلب).
 * يُكتب بموافقة القياس وحدها (يربط شراءَ شخصٍ بأسئلته)، وتُمحى بصماتُه بسياسة
 * الاحتفاظ نفسها لـorder_attributions. الدفعُ والاسترداد يُقرآن من الطلب وقت
 * الاستعلام، فلا نسخة ثانية من حالة الطلب تنحرف.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_queries', function (Blueprint $table) {
            $table->jsonb('filters')->nullable();
            $table->jsonb('result_ids')->nullable();
            $table->string('recovery_mode', 24)->nullable();
            $table->string('source', 16)->default('search');
            $table->string('device', 8)->nullable();
            $table->string('variant', 32)->nullable();
            $table->index(['source', 'created_at']);
        });

        Schema::create('search_interactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_query_id')->constrained('search_queries')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('kind', 10);
            $table->unsignedInteger('rank');
            $table->string('destination', 16)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['search_query_id', 'product_id', 'kind']);
            $table->index(['product_id', 'kind', 'created_at']);
            $table->index(['kind', 'created_at']);
        });
        DB::statement("ALTER TABLE search_interactions ADD CONSTRAINT search_interactions_kind_check CHECK (kind IN ('impression','click'))");

        Schema::create('search_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('search_query_id')->constrained('search_queries')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->char('visitor_hash', 64)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->string('stage', 16);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->bigInteger('value_minor')->nullable();
            /* مفتاحُ عدم التكرار: (بحث · منتج · مرحلة · طلب) — الطلبُ الغائب صفر. */
            $table->string('idempotency_key', 120)->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['stage', 'created_at']);
            $table->index(['visitor_hash', 'product_id', 'stage']);
            $table->index('order_id');
        });
        DB::statement("ALTER TABLE search_attributions ADD CONSTRAINT search_attributions_stage_check CHECK (stage IN ('add_to_cart','remove_from_cart','checkout'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('search_attributions');
        Schema::dropIfExists('search_interactions');
        Schema::table('search_queries', function (Blueprint $table) {
            $table->dropIndex(['source', 'created_at']);
            $table->dropColumn(['filters', 'result_ids', 'recovery_mode', 'source', 'device', 'variant']);
        });
    }
};
