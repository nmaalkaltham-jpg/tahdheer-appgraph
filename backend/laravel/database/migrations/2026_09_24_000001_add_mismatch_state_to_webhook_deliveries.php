<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * حالةٌ سادسة للتسليم: `MISMATCH`.
 *
 * رسمٌ موقّع بمبلغٍ أو عملةٍ لا تطابق الطلب ليس «معالَجًا» — الطلب لم
 * يُموَّل — وليس «فاشلًا» — لا عطل عندنا يُعاد تشغيله. هو حدثٌ حُسم أمره:
 * المال سُجِّل كما وصل، والطلب بقي بانتظار الدفع، والتسوية تحمله.
 */
return new class extends Migration
{
    private const STATES = ['RECEIVED', 'PROCESSED', 'IGNORED', 'UNMATCHED', 'FAILED', 'MISMATCH'];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $list = "'".implode("','", self::STATES)."'";

        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_status_check');
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ({$list}))");

        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_processed_has_time');
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_processed_has_time CHECK (
            status NOT IN ('PROCESSED', 'IGNORED', 'UNMATCHED', 'MISMATCH') OR processed_at IS NOT NULL
        )");
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_processed_has_time');
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_processed_has_time CHECK (
            status NOT IN ('PROCESSED', 'IGNORED', 'UNMATCHED') OR processed_at IS NOT NULL
        )");
        DB::statement('ALTER TABLE webhook_deliveries DROP CONSTRAINT IF EXISTS webhook_deliveries_status_check');
        DB::statement("ALTER TABLE webhook_deliveries ADD CONSTRAINT webhook_deliveries_status_check CHECK (status IN ('RECEIVED','PROCESSED','IGNORED','UNMATCHED','FAILED'))");
    }
};
