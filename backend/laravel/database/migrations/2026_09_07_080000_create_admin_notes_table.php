<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ملاحظات المشغّلين على الطلبات والعملاء.
 *
 * الحاجة عملية لا تجميلية: «هذا العميل طلب استرداد هاتفيًّا»، «الملفّ أُرسل
 * يدويًّا بعد عطل التنزيل». اليوم تُكتب هذه في واتساب أو لا تُكتب — فتضيع مع
 * أوّل تسليم مناوبة. وسجلّ التدقيق لا يحلّ محلّها: هو يسجّل ما فعله النظام،
 * لا ما فهمه الإنسان.
 *
 * ─── تعدّدية الشكل بلا مفتاح أجنبي، عن قصد ───
 *
 * `entity_type` + `entity_id` بدل جدولَين أو مفتاحَين أجنبيَّين قابلَين للعدم.
 * والثمن معروف: لا تكامل مرجعي على مستوى المحرّك. والمقابل أنّ إضافة كيان
 * ثالث لاحقًا (منتج، قسيمة) سطرٌ في `ENTITIES` لا هجرةٌ جديدة.
 *
 * وحذف العميل أو الطلب لا يحذف ملاحظاته — وهذا مقصود لا سهو: الملاحظة قد تكون
 * **سبب** الحذف. والاستعلام يقرأ بـ`entity_type`+`entity_id` فلا يتعثّر بيتيم.
 *
 * أمّا الكاتب فيبقى اسمه ولو حُذف حسابه: `author_email` منسوخ لا مشتقّ — نفس
 * قاعدة `admin_audit_log.actor_email` بالضبط.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_notes', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 32);
            $table->unsignedBigInteger('entity_id');
            $table->foreignId('admin_user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('author_email');
            $table->text('body');
            /*
             * `edited_at` عمودٌ صريح لا فرقٌ بين `updated_at` و`created_at`.
             *
             * طوابع Laravel الافتراضية بدقّة الثانية، وملاحظةٌ تُصحَّح بعد
             * ثانية من كتابتها تجعل الفرق صفرًا — فتُعرض «غير معدَّلة» وهي
             * معدَّلة. وهذا كذبٌ صغير في مكان حسّاس: قارئ الملاحظة يقرّر بناءً
             * على أنّها ما كُتب أوّلًا.
             *
             * والعمود يحمل معلومةً زائدة مطلوبة أصلًا: **متى** عُدّلت.
             */
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            // الاستعلام الوحيد: ملاحظات كيان واحد بالأحدث أوّلًا.
            $table->index(['entity_type', 'entity_id', 'id'], 'admin_notes_entity_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_notes');
    }
};
