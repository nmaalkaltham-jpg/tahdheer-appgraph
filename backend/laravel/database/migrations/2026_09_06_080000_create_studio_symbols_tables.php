<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Studio Phase 2.5+3 (STUDIO-PHASE3-CONTRACT §3، قرار C32): studio_symbols +
 * studio_symbol_versions — الاستعمال الرابع لطبقة النشر المنقسمة العمودين
 * المجرَّبة (design_components ← product_layouts ← studio_pages ← الرموز).
 *
 * الحلّ وقت التصيير لا وقت النشر: الصفحات تحتفظ بعقد symbol-ref، والقراءة
 * العامة تضمّن خريطة الأجسام **المنشورة** داخل الحمولة المخبّأة. المسار
 * العام يقرأ published_document وحده — عزل المسوّدة بنيوي كما في الصفحات.
 *
 * لا عمود locale (الرمز على مستوى المتجر؛ لا فهرس فريد متورّط ⇒ إضافته
 * لاحقًا آمنة — حجّة قوالب المرحلة الثانية §6 حرفيًّا).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_symbols', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);                     // الاسم العربي المعروض، قابل للبحث
            $table->json('draft_document');                  // مستند رمز schema-3 (جذر واحد)
            $table->json('published_document')->nullable();  // العمود الوحيد الذي تقرؤه التضمينات العامة
            $table->unsignedSmallInteger('schema_version')->default(3);
            $table->unsignedInteger('version')->default(1);  // العدّاد التفاؤلي (§72)
            $table->unsignedInteger('published_version')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('studio_symbol_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('symbol_id')->constrained('studio_symbols')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('kind', 20);                      // autosave | checkpoint | publish
            $table->string('label', 160)->nullable();
            $table->json('document');
            $table->unsignedSmallInteger('schema_version')->default(3);
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['symbol_id', 'version']);
            $table->index(['symbol_id', 'kind', 'created_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // نفس قيدَي studio_pages حرفيًّا: لا نصفَ نشرٍ في القاعدة.
            DB::statement('ALTER TABLE studio_symbols ADD CONSTRAINT studio_symbols_publish_pair
                CHECK ((published_version IS NULL) = (published_document IS NULL))');
            DB::statement("ALTER TABLE studio_symbol_versions ADD CONSTRAINT studio_symbol_versions_kind
                CHECK (kind IN ('autosave','checkpoint','publish'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_symbol_versions');
        Schema::dropIfExists('studio_symbols');
    }
};
