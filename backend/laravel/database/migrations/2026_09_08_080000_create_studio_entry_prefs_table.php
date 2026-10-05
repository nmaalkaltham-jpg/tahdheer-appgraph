<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * تفضيلات المشرف في متصفّح العناصر (عقد المرحلة ٣٫٥ §3.4، قرار C47).
 *
 * كانت «المفضّلة» و«مفتوح حديثًا» تعيشان في حالة React وحدها، والواجهة تقول
 * ذلك صراحةً: «في هذه الجلسة فقط — لا نقطة نهاية لحفظ تفضيلات المشرف».
 * هذا الجدول هو تلك النقطة.
 *
 * ─── صفٌّ واحد للزوج، لا جدولان ───
 *
 * المفضّلة والأخيرة ليستا كيانين بل **صفتين للعلاقة نفسها**: (مشرف، مدخل).
 * جدولان كانا سيضاعفان المسارات والفهارس ثم يفرضان استعلامين لبناء لوحٍ
 * واحد. فالعمودان `favorite` و`last_opened_at` يعيشان في صفّ واحد بمفتاح
 * فريد مركّب — والصفّ يوجد إن كان المدخل مفضّلًا أو مفتوحًا حديثًا أو كليهما.
 *
 * ─── لماذا لا مفتاح أجنبي على `registry_id`؟ ───
 *
 * السجل وحدة TypeScript لا جدول — وهو مقصود (`packages/registry`). ومرآةٌ
 * ثالثة له في PHP (بعد `PageDocument::ALLOWED_REGISTRY_IDS`) تُصان يدويًّا
 * مقابل صفر مكسب: الصفّ **إشارة مرجعية لا محتوى مُصيَّر**، ومعرّفٌ لا يحلّه
 * السجل لا يُرسم في أي سطح لأن الواجهة تحلّه من السجل أوّلًا. الثمن المُعلَن:
 * مفضّلة لمدخل حُذف من السجل تختفي صامتةً — وهو السلوك الصحيح لا عطل.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_entry_prefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->constrained()->cascadeOnDelete();
            $table->string('registry_id', 64);
            $table->boolean('favorite')->default(false);
            /*
             * `last_opened_at` عمودٌ صريح لا `updated_at`: الأخير يتحرّك عند
             * التفضيل أيضًا، فترتيب «مفتوح حديثًا» كان سيكذب — مدخلٌ فُضِّل
             * ولم يُفتح كان سيتصدّر قائمة المفتوح حديثًا.
             */
            $table->timestamp('last_opened_at')->nullable();
            $table->timestamps();

            $table->unique(['admin_user_id', 'registry_id']);
            // ترتيب «مفتوح حديثًا» لكل مشرف — الفهرس هو الاستعلام بعينه.
            $table->index(['admin_user_id', 'last_opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_entry_prefs');
    }
};
