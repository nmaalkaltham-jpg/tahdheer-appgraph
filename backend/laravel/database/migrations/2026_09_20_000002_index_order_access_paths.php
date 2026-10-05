<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * فهرس مفتاح وصول الضيف إلى طلبه.
 *
 * ─── العطل ───
 *
 * ‏`orders.order_key_hash` هو مفتاح وصول غير المسجَّل إلى طلبه — **الوحيد**.
 * ويُستعلم به مساواةً في ثلاثة مسارات كلّها على أسخن طريقٍ في المتجر:
 *
 *   · `OrderController:22`    — عرض الطلب
 *   · `PaymentController:84`  — الدفع
 *   · `DownloadController:40` — التنزيل
 *
 * وكان بلا فهرس. قِيس على القاعدة الحيّة قبل هذه الهجرة:
 *
 *   $ select indexname from pg_indexes where tablename='orders';
 *     orders_pkey · orders_number_unique · orders_created_at_index
 *     orders_customer_id_status_index · orders_reminder_scan
 *     ← لا شيء على order_key_hash
 *
 * أي أنّ كلّ فتحِ طلبٍ لضيف، وكلّ دفعة، وكلّ تنزيل، يمسح جدول الطلبات
 * كاملًا. وهو مسارٌ يطول بنجاح المتجر لا بفشله.
 *
 * ─── ولماذا هذا وحده ───
 *
 * المسح وجد أربعة أعمدةٍ بلا فهرس على هذا الطريق. وثلاثة منها —
 * ‏`order_items.order_id` و`payments.order_id` و`payment_events.payment_id` —
 * غطّتها هجرة `2026_09_20_000001_harden_money_path_constraints` قبل هذه
 * بساعة. فهرسٌ رابعٌ على عمودٍ مفهرسٍ كلفةُ كتابةٍ بلا مكسب قراءة.
 *
 * ─── والفهرس جزئيّ ───
 *
 * العمود يقبل NULL — طلبات المسجَّلين لا مفتاح ضيفٍ لها — وكلّ استعلاماته
 * مساواةٌ بقيمةٍ غير فارغة، وPostgreSQL لا يستعمل فهرس B-tree لمساواة NULL
 * أصلًا. فالجزئيّ يحمل الصفوف المقروءة وحدها: أصغر، وبالسلوك نفسه بالضبط.
 *
 * وهجرةٌ **مضيفة فقط**: لا عمود يتغيّر ولا قيد ولا استعلامٌ يُعاد كتابته.
 * و`IF NOT EXISTS` لأنّ جلسات متوازية تهاجر القاعدة نفسها.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(
            'CREATE INDEX IF NOT EXISTS orders_order_key_hash_index
             ON orders (order_key_hash) WHERE order_key_hash IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS orders_order_key_hash_index');
    }
};
