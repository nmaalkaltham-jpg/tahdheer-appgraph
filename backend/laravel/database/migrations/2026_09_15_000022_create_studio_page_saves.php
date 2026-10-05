<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ حفظ المسوّدات — خمولُ الكتابة المحلّيّة.
 *
 * ─── العيب الذي يصلحه ───
 *
 * المحرّر يحفظ، والخادم يلتزم النسخة ١٢، ويضيع الردّ في الشبكة. فيُعيد
 * المحرّر الإرسال بأساسٍ ١١ — فيردّ الخادم «عدّل مشرفٌ آخر هذه الصفحة».
 *
 * وهذه **كذبة**: لا مشرف آخر، والذي عدّل هو المحرّر نفسه. والنتيجة أنّ
 * المشرف يُعرض عليه أن يتخلّى عن عمله أمام «تعارضٍ» مع نفسه — وهو أسوأ
 * سوء تشخيصٍ ممكن لأنّه يدفع إلى الفعل الخاطئ بثقة.
 *
 * ─── لماذا جدولٌ مستقلّ عن `studio_page_versions` ───
 *
 * الإصدارات تُقلَّم: الحفظ التلقائيّ يُبقي عددًا محدودًا ويحذف ما قبله.
 * ووضعُ مفتاح الخمول فيها يعني أن تختفي ذاكرة «هذا الطلب نُفِّذ» مع أوّل
 * تقليم — فتعود الكذبة. وهذا السجلّ يُقلَّم بالعمر لا بالعدد، لأنّ نافذة
 * إعادة الإرسال دقائق لا إصدارات.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_page_saves', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('page_id');

            /*
             * الهويّة ثلاثيّة: الصفحة، والعميل (تبويب/جهاز)، ومعرّف الطلب.
             * العميل جزءٌ منها عمدًا: تبويبان للمشرف نفسه محرّران مستقلّان،
             * ودمجُهما في مفتاحٍ واحد يجعل حفظ أحدهما يبتلع حفظ الآخر.
             */
            $table->string('client_id', 64);
            $table->string('request_id', 64);

            $table->integer('base_version');
            $table->integer('result_version');

            /* بصمة الوثيقة: تكشف مفتاحًا أُعيد استعماله بجسمٍ مختلف. */
            $table->string('fingerprint', 64);

            /* كيف انتهى: مباشرةً، أم بدمجٍ آليّ، أم بإجبارٍ من المشرف. */
            $table->string('outcome', 16);

            $table->unsignedBigInteger('actor_admin_user_id')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['page_id', 'client_id', 'request_id']);
            $table->index(['page_id', 'created_at']);
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("ALTER TABLE studio_page_saves ADD CONSTRAINT studio_page_saves_outcome_check CHECK (
            outcome IN ('DIRECT', 'MERGED', 'FORCED')
        )");

        /* النسخة الناتجة تتجاوز الأساس دائمًا — وإلّا فالسجلّ يكذب. */
        DB::statement('ALTER TABLE studio_page_saves ADD CONSTRAINT studio_page_saves_moves_forward CHECK (
            result_version > base_version
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_page_saves');
    }
};
