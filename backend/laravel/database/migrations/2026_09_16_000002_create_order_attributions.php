<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إسناد الطلب — الوصلة التي كانت مستحيلة بنيويًّا.
 *
 * ─── الحقيقة المقيسة التي أوجبت هذا الجدول ───
 *
 * جدول `orders` **لا يحمل عمود إسنادٍ واحدًا**: بحثٌ عن ref/utm/source/cart/
 * visitor/session في أعمدته لم يطابق إلّا `refunded_total_minor` مصادفةً.
 * فالسؤال «أيّ صفحةٍ أنتجت هذا الإيراد» لم يكن ناقص لوحة، بل بلا جواب ممكن.
 *
 * ─── ولماذا جدولٌ مستقلّ لا عمودٌ على `orders` ───
 *
 * بصمةُ الزائر بيانٌ **بموافقة** غرضها القياس، وسطرُ الطلب بيانٌ **ضروريّ**
 * تحكمه مدّة حفظ الفواتير النظاميّة — سنوات. ووضعُ البصمة في `orders` يجعل
 * معرّف قياسٍ يعيش سنواتٍ داخل جدولٍ لا تُنظّفه أيّ سياسة قياس، فيُكسر وعدُ
 * «تُحذف بعد تسعين يومًا» من بابٍ خلفيّ.
 *
 * وفصلُه يسمح بما تنصّ عليه السياسة نصًّا: تُمحى البصمة بعد تسعين يومًا
 * (`ANONYMIZE` لا `DELETE`) ويبقى ما لا يعرّف بأحد — مسار الصفحة والمُحيل —
 * ليبقى «إيراد كلّ صفحة» مجيبًا بعد انقضاء المدّة.
 *
 * ─── واللقطة تُكتب الآن لا تُشتقّ لاحقًا ───
 *
 * `landing_path` و`landing_referrer_host` و`first_seen_at` تُقرأ من
 * `analytics_events` لحظة الشراء وتُخزَّن. اشتقاقُها وقت القراءة يجعل تقرير
 * الأمس يتغيّر اليوم لأنّ الأحداث انقضت مدّتها — وهي السابقة نفسها التي
 * جعلت لقطة سعر البند تُكتب مع الطلب (§١٠١).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_attributions', function (Blueprint $table): void {
            /*
             * الطلب هو المفتاح: إسنادٌ واحد لكلّ طلب. ولو كان مفتاحًا آليًّا
             * لأمكن وجود سطرين متناقضين للطلب الواحد، فيصير «إيراد الصفحة»
             * مجموعًا لإيرادٍ عُدّ مرّتين.
             */
            $table->foreignId('order_id')->primary()->constrained()->cascadeOnDelete();

            /* البصمتان تُمحيان عند انقضاء المدّة — ولذلك قابلتان للإفراغ. */
            $table->char('visitor_hash', 64)->nullable();
            $table->char('session_hash', 64)->nullable();

            /* ما يبقى بعد المحو: لا يعرّف بشخصٍ ويحمل الجواب التجاريّ. */
            $table->string('landing_path', 200)->nullable();
            $table->string('landing_referrer_host', 120)->nullable();
            $table->timestamp('first_seen_at')->nullable();

            /*
             * عدد اللمسات — دليلُ عمقٍ لا زينة: إسنادٌ بلمسةٍ واحدة يعني أنّ
             * الزائر وافق على القياس في صفحة الدفع نفسها، ونسبتُه إلى صفحةٍ
             * «جالبة» استنتاجٌ لا قياس.
             */
            $table->unsignedInteger('touch_count')->default(0);

            /* متى مُحيت البصمة — إثباتُ تنفيذٍ لا حقلُ حالة. */
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('visitor_hash');
            $table->index(['landing_path', 'created_at']);
        });

        $now = now();

        DB::table('retention_policies')->updateOrInsert(
            ['data_class' => 'order_attributions'],
            [
                'name' => 'إسناد الطلبات',
                'retain_days' => 90,
                /*
                 * إخفاء الهويّة لا الحذف: الصفّ نفسه إيرادٌ محاسَب، وحذفُه
                 * يُنقص مجموع «إيراد الصفحة» بأثرٍ رجعيّ فيصير تقرير الربع
                 * الماضي يتغيّر كلّ يوم. المحو يطال المعرّف وحده.
                 */
                'on_expiry' => 'ANONYMIZE',
                'legal_basis' => 'بصمة الزائر بيانُ قياسٍ بموافقة، وتَعِد السياسة المنشورة بحذف تفاصيل '
                    .'القياس بعد تسعين يومًا. والمسار والمُحيل لا يعرّفان بشخصٍ فيبقيان مع الطلب.',
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('retention_policies')->where('data_class', 'order_attributions')->delete();
        Schema::dropIfExists('order_attributions');
    }
};
