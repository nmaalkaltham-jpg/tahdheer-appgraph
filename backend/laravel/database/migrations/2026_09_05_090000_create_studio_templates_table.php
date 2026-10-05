<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * قوالب الاستوديو — لقطات مسمّاة جامدة (عقد المرحلة الثانية C25، §3).
 *
 * لا تيار إصدارات، لا زوج نشر، لا مسار عام، لا عمود لغة (لا فهرس فريد
 * متورّط ⇒ إضافتها لاحقًا آمنة). النسخ-عند-الإنشاء يُبقي القوالب كيانًا
 * مفردًا — سجلّ النشر C3 يبقى غير ضروري.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);
            $table->json('document');                                    // لقطة مُطبَّعة من مسوّدة صفحة
            $table->unsignedSmallInteger('schema_version')->default(1);  // يُرحَّل للأمام عند الإنشاء منه (§65)
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_templates');
    }
};
