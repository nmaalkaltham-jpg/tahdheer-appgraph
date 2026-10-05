<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * طلبات المواد غير المتوفّرة — الطلب الذي خرج من المتجر بلا شراء.
 *
 * الزائر الذي لم يجد ملفّه يخرج ويأخذ معه سبب خروجه. هذا الجدول يحتفظ بالسبب:
 * أي مرحلة وصف ومادة وفصل ونوع طُلب ولم يوجد. فيصير قرار «ماذا نُنتج بعد ذلك»
 * مبنيًّا على طلب مُعلَن لا على تخمين.
 *
 * التصنيف مفاتيح أجنبية لا نصّ حرّ: طلبٌ يقول «سادس» وآخر «الصف السادس»
 * وثالث «٦» ثلاثة صفوف في تقرير مبنيّ على النصّ، وواحد في تقرير مبنيّ على
 * الكيانات. والغرض من الجدول هو العدّ.
 *
 * `contact` بيانات شخصية: لا تُفهرس، ولا تخرج في أي واجهة عامة، ولا تُرسل إلى
 * أي طرف ثالث. تُقرأ في اللوحة بصلاحية، ولها عمود انتهاء صريح كي لا تبقى
 * محفوظة بعد أن تنتفي حاجتها.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('material_requests', function (Blueprint $table) {
            $table->id();

            // التصنيف كما يعرفه الكتالوج — لا نصّ حرّ.
            $table->foreignId('stage_id')->nullable()->constrained('stages')->nullOnDelete();
            $table->foreignId('grade_id')->nullable()->constrained('grades')->nullOnDelete();
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->foreignId('semester_id')->nullable()->constrained('semesters')->nullOnDelete();
            /** نوع المورد كما يخزّنه الكتالوج في `products.resource_type`. */
            $table->string('kind', 120)->nullable();

            /** وصف الطالب بكلماته — مصدر لا يُستبدل بالتصنيف وحده. */
            $table->text('note')->nullable();

            /** وسيلة تواصل اختيارية؛ بريد أو رقم، ونوعها مُصرَّح به. */
            $table->string('contact', 190)->nullable();
            $table->string('contact_kind', 10)->nullable();

            /**
             * من أين جاء الطلب: نموذج الصفحة، أو استعلام بحث بلا نتيجة.
             * الاثنان طلبٌ فائت، ودمجهما هو ما يجعل التقرير كاملًا.
             */
            $table->string('source', 20)->default('form');
            /** نصّ الاستعلام حين يكون المصدر بحثًا صفريّ النتائج. */
            $table->string('query', 200)->nullable();

            /**
             * حالة المعالجة. `new` لا تعني «غير مقروء» بل «لم يُتّخذ قرار».
             * المسار: new → planned → published، أو new → declined بسبب.
             */
            $table->string('status', 12)->default('new');
            $table->text('decision_note')->nullable();

            /**
             * بصمة التصنيف: طلبان لنفس التقاطع صفٌّ واحد بعدّاد، لا صفّان.
             * فالتقرير يقيس الطلب لا عدد مرات إرسال النموذج.
             */
            $table->string('fingerprint', 64);
            $table->unsignedInteger('hits')->default(1);

            $table->timestamp('first_seen_at')->useCurrent();
            $table->timestamp('last_seen_at')->useCurrent();
            $table->timestamps();

            $table->unique('fingerprint');
            $table->index(['status', 'hits']);
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('material_requests');
    }
};
