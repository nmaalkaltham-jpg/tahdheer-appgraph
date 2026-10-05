<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * مكتبة وسائط الاستوديو — أول مكتبة صادقة (عقد المرحلة الثانية C26، §3).
 *
 * التخزين تحت `media/studio/` — داخل البادئة الوحيدة التي يخدمها
 * PublicMediaController، فالتقديم والتخزين المؤقّت وسلامة الأصول المدفوعة
 * كلها موروثة: صفر سطح عام جديد. البصمة في المفتاح تعني أن إعادة رفع
 * المحتوى نفسه صفٌّ واحد (upsert بالرابط الفريد، ردّ 200).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_media', function (Blueprint $table) {
            $table->id();
            $table->string('name', 160);                 // اسم الملف الأصلي — هدف البحث
            $table->string('url');                       // '/api/v1/media/studio/…' (مفتاح ببصمة)
            $table->string('alt_ar', 200)->nullable();   // افتراض المكتبة؛ الاختيار يَنسخه إلى خاصية المستند
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('mime', 40);
            $table->unsignedBigInteger('bytes');
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamps();
            $table->unique('url');                       // إعادة رفع المحتوى نفسه = الصفّ نفسه
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_media');
    }
};
