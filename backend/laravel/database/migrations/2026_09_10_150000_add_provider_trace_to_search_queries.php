<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تتبّعُ ما خدم كلَّ بحث — المزوّد، والتدهور وسببه، والزمن.
 *
 * ─── لماذا بهذا الاسم وهذا التاريخ ───
 *
 * هذه الهجرة طُبّقت على قاعدة التطوير في 2026-09-10 ولم يدخل ملفُّها
 * المستودع قطّ (صفُّها في `migrations` بلا ملفّ). وشاشة «البحث الداخلي» في
 * اللوحة (aee3921) تقرأ القياس المبنيّ على هذه الأعمدة، فانكسرت في كلّ بيئةٍ
 * نظيفة. الاسم نفسه يجعلها لا-عملًا على قاعدة التطوير (مسجّلةٌ منفَّذة)
 * ويُنشئ الأعمدة في كلّ قاعدةٍ جديدة — والتعريف منسوخٌ من قاعدة التطوير
 * حرفًا بحرف (information_schema)، فلا انحراف بين الاثنتين.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('search_queries', function (Blueprint $table) {
            $table->string('provider', 32)->default('unknown');
            $table->boolean('degraded')->default(false);
            $table->string('degraded_reason', 64)->nullable();
            $table->integer('latency_ms')->nullable();
            $table->index(['created_at', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::table('search_queries', function (Blueprint $table) {
            $table->dropIndex(['created_at', 'provider']);
            $table->dropColumn(['provider', 'degraded', 'degraded_reason', 'latency_ms']);
        });
    }
};
