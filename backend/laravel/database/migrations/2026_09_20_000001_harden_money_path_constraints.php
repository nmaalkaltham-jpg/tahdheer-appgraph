<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * قيودُ مسار المال في المحرّك (برنامج الموثوقيّة ٢٠٢٦-٠٩-٢٠).
 *
 * كلّ قيدٍ هنا يقابل عيبًا أُثبت بالاختبار:
 *
 *   payments (order, provider, intent) فريد — رسمٌ ثانٍ صفٌّ ثانٍ لا كتابةٌ فوق الأوّل.
 *   payments.state محدودة المفردات — كانت نصًّا حرًّا على مسار المال وحده.
 *   orders: الاسترداد والحالة يتّفقان — «مدفوع» بمبلغٍ مستردّ كان ممكنًا.
 *   فهارس على أعمدة المفاتيح الأجنبيّة الساخنة — PostgreSQL لا يفهرس جهة الابن.
 *   فهرس جزئيّ لاستحواذ الصندوق الصادر يطابق استعلامه حرفًا.
 *   مفاتيح أجنبيّة على سجلّ العناوين وأحداث القضايا — كانت أعدادًا عارية.
 *
 * `NOT VALID`: يُفرَض على الجديد ولا يُفحص القديم عند الترحيل، فالنشر لا
 * ينهار على صفٍّ تاريخيّ؛ يُصادَق عليه لاحقًا بـ`VALIDATE CONSTRAINT` بعد
 * تدقيق البيانات. والفهارس `IF NOT EXISTS` لأنّ قاعدة التطوير قد تحملها يدويًّا.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS payments_order_provider_intent_unique
            ON payments (order_id, provider, intent_id) WHERE intent_id IS NOT NULL');

        DB::statement("ALTER TABLE payments ADD CONSTRAINT payments_state_known
            CHECK (state IN ('CREATED', 'INTENT_FAILED', 'AUTHORIZED', 'CAPTURED', 'FAILED', 'VOIDED', 'REFUNDED')) NOT VALID");

        DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_refund_status_agrees CHECK (
            (refunded_total_minor = 0 AND status NOT IN ('PARTIALLY_REFUNDED', 'REFUNDED'))
            OR (refunded_total_minor > 0 AND refunded_total_minor < total_minor AND status = 'PARTIALLY_REFUNDED')
            OR (refunded_total_minor > 0 AND refunded_total_minor >= total_minor AND status = 'REFUNDED')
        ) NOT VALID");

        DB::statement('CREATE INDEX IF NOT EXISTS order_items_order_id_index ON order_items (order_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS payments_order_id_index ON payments (order_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS payment_events_payment_id_index ON payment_events (payment_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS download_grants_entitlement_id_index ON download_grants (entitlement_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS download_events_entitlement_id_index ON download_events (entitlement_id)');

        DB::statement("CREATE INDEX IF NOT EXISTS outbox_events_claimable_idx ON outbox_events (created_at) WHERE status = 'PENDING'");
        DB::statement("CREATE INDEX IF NOT EXISTS outbox_events_reclaim_idx ON outbox_events (updated_at) WHERE status = 'PROCESSING'");

        DB::statement('ALTER TABLE url_history ADD CONSTRAINT url_history_registry_fk
            FOREIGN KEY (registry_id) REFERENCES url_registry (id) ON DELETE CASCADE NOT VALID');
        DB::statement('ALTER TABLE url_registry ADD CONSTRAINT url_registry_redirect_rule_fk
            FOREIGN KEY (redirect_rule_id) REFERENCES redirect_rules (id) ON DELETE SET NULL NOT VALID');
        DB::statement('ALTER TABLE case_events ADD CONSTRAINT case_events_case_fk
            FOREIGN KEY (case_id) REFERENCES cases (id) ON DELETE CASCADE NOT VALID');
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE case_events DROP CONSTRAINT IF EXISTS case_events_case_fk');
        DB::statement('ALTER TABLE url_registry DROP CONSTRAINT IF EXISTS url_registry_redirect_rule_fk');
        DB::statement('ALTER TABLE url_history DROP CONSTRAINT IF EXISTS url_history_registry_fk');
        DB::statement('DROP INDEX IF EXISTS outbox_events_reclaim_idx');
        DB::statement('DROP INDEX IF EXISTS outbox_events_claimable_idx');
        DB::statement('DROP INDEX IF EXISTS download_events_entitlement_id_index');
        DB::statement('DROP INDEX IF EXISTS download_grants_entitlement_id_index');
        DB::statement('DROP INDEX IF EXISTS payment_events_payment_id_index');
        DB::statement('DROP INDEX IF EXISTS payments_order_id_index');
        DB::statement('DROP INDEX IF EXISTS order_items_order_id_index');
        DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_refund_status_agrees');
        DB::statement('ALTER TABLE payments DROP CONSTRAINT IF EXISTS payments_state_known');
        DB::statement('DROP INDEX IF EXISTS payments_order_provider_intent_unique');
    }
};
