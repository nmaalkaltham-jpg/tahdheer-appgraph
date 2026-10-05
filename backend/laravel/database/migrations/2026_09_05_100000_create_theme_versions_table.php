<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * إصدارات الثيم — تراجع حقيقي لتحرير الرموز (عقد المرحلة الثانية C27، §3).
 *
 * عند كل حفظ ثيم: إن كان الجدول فارغًا تُلتقط الوثيقة قبل الحفظ صفًّا
 * kind='baseline' أولًا؛ ثم تُلتقط الوثيقة المحفوظة؛ ويُقلَّم إلى أحدث 50
 * داخل المعاملة نفسها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_versions', function (Blueprint $table) {
            $table->id();
            $table->json('document');
            $table->string('etag', 16);
            $table->string('kind', 20);                  // publish | restore | baseline
            $table->string('label', 160)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_versions');
    }
};
