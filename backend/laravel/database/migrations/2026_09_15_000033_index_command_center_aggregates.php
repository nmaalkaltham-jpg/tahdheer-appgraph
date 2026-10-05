<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * فهارس تجميعات مركز التشغيل.
 *
 * ─── لماذا الآن ───
 *
 * لوحة التشغيل صارت تسأل ثلاثة أسئلةٍ لم تكن تُسأل، وكلّها تُنفَّذ في كلّ
 * تحميل صفحة وفي كلّ تحديثٍ للشريط الجانبيّ:
 *
 *   • «طلبٌ مدفوع بلا صلاحية وصول» — نفيُ وجودٍ على `entitlements.order_id`.
 *   • «أحداث بوّابةٍ فشلت» — ترشيحٌ على `webhook_deliveries.status` وحده.
 *   • «طلبات المدى» — قصٌّ على `orders.created_at` وحده.
 *
 * ─── ولماذا هذه الأعمدة بالذات ───
 *
 * `entitlements.order_id` مفتاحٌ خارجيّ بلا فهرس: PostgreSQL لا يُنشئ فهرسًا
 * للمفتاح الخارجيّ من تلقائه — خلافًا لـ MySQL. فكان نفيُ الوجود يمسح جدول
 * الصلاحيات مرّةً لكلّ طلبٍ مدفوع: مسحٌ تربيعيّ يمرّ بلا أثرٍ على ستّةٍ
 * وعشرين صفًّا ويُسقط اللوحة على مئة ألف.
 *
 * و`webhook_deliveries` له `(provider, status)`، والمزوّد ليس في شرطنا. فهرسٌ
 * مركّب لا تُذكر بادئتُه لا يُستعمل، فالعدّاد كان يمسح الجدول كاملًا.
 *
 * و`orders` له `(status, reminder_stage, created_at)`، و`reminder_stage` بين
 * الحالة والتاريخ يقطع الطريق على قصّ المدى. والمدى يُقصّ وحده في «كلّ
 * الطلبات» فلا بادئةَ حالةٍ تسبقه أصلًا.
 *
 * ─── وما لم يُضف ───
 *
 * `fulfillment_units(order_id, status)` و`risk_assessments(review_outcome,
 * created_at)` قائمان ويخدمان العدّادَين الجديدَين ببادئتهما. وفهرسٌ ثانٍ على
 * العمود نفسه كلفةُ كتابةٍ بلا مكسب قراءة.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->index('order_id', 'entitlements_order_id_index');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->index('status', 'webhook_deliveries_status_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->index('created_at', 'orders_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('entitlements', function (Blueprint $table): void {
            $table->dropIndex('entitlements_order_id_index');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table): void {
            $table->dropIndex('webhook_deliveries_status_index');
        });

        Schema::table('orders', function (Blueprint $table): void {
            $table->dropIndex('orders_created_at_index');
        });
    }
};
