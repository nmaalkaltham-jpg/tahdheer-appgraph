<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تخطيط صفحة المنتج. كان يعيش في حالة المحرّر وحدها ويضيع مع إعادة التحميل.
 *
 * نفس شكل design_components عمدًا: مسوّدة ومنشور عمودان، وعدّاد نسخ تفاؤلي.
 * لم يُبنَ نظام نشر ثانٍ — الأعمدة والدلالة مطابقة فيقرؤها المشغّل نفسه.
 *
 * لا HTML خام: الوثيقة أقسام وودجات وترتيب وإعدادات، فيبقى التصيير للمكوّنات
 * ويبقى المحتوى قابلًا لإعادة التنسيق دون إعادة تحرير.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('draft_layout');
            $table->json('published_layout')->nullable();
            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('published_version')->nullable();
            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_layouts');
    }
};
