<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الاسترداد يُنفَّذ عند المزوّد — والصفّ يحمل مصيره.
 *
 * ─── العيب ───
 *
 * صفّ الاسترداد كان يُكتب محلّيًّا ويُعلَّم الطلب «مستردًّا» ولا يُطلب من
 * البوّابة شيء. الدفتر يقول «رُدّ المال» والزبون لم يستلم هللة — وحين
 * يُردّ يدويًّا من لوحة المزوّد لا يعرف الدفتر، فيُردّ مرّتين أو لا يُردّ.
 *
 * ─── الحالات ───
 *
 *   PENDING    حُجز محلّيًّا ولم يُحسم عند المزوّد بعد (نداءٌ جارٍ أو ينتظر خطّافًا)
 *   SUCCEEDED  أكّد المزوّد خروج المال — هنا وحدها تقع الآثار (سحب، حدث)
 *   FAILED     رفض المزوّد — الحجز أُفرج عنه ولا أثر
 *   UNKNOWN    انقطع الردّ — قد يكون المال خرج: الحجز يبقى حتّى يُعاد السؤال
 *   MANUAL     لا تحصيلَ معروفَ المزوّد لهذا الطلب — يُردّ يدويًّا ويُسجَّل
 *
 * الصفوف القائمة كلّها من النموذج القديم (محلّيّة) فتُعدّ `MANUAL`: لم يُطلب
 * من مزوّدٍ شيء، والمشغّل قام به بيده — أو لم يقم. لا تُخترع لها حقيقة.
 */
return new class extends Migration
{
    private const STATES = ['PENDING', 'SUCCEEDED', 'FAILED', 'UNKNOWN', 'MANUAL'];

    public function up(): void
    {
        Schema::table('refunds', function (Blueprint $table) {
            $table->string('status', 16)->default('MANUAL')->after('amount_minor');
            $table->string('provider', 32)->nullable()->after('status');
            $table->foreignId('payment_id')->nullable()->after('provider')->constrained('payments')->nullOnDelete();
            $table->string('provider_refund_id', 191)->nullable()->after('payment_id');
            $table->string('provider_error', 1000)->nullable()->after('provider_refund_id');
            $table->timestampTz('settled_at')->nullable()->after('provider_error');

            $table->index(['order_id', 'status']);
        });

        DB::table('refunds')->update(['settled_at' => DB::raw('created_at')]);

        /*
         * المحجوز منفصلٌ عن المحسوم: `refunded_total_minor` يبقى «ما خرج
         * فعلًا» لكلّ قارئٍ قائم (الحالة، التقارير، المتجر)، و`refund_reserved_minor`
         * ما ينتظر جواب المزوّد. والسقف على مجموعهما.
         */
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('refund_reserved_minor')->default(0)->after('refunded_total_minor');
        });

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->string('kind', 16)->default('charge')->after('provider');
            $table->string('refund_id', 191)->nullable()->after('charge_id');
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE orders ADD CONSTRAINT orders_refund_reserve_within_captured CHECK (
            refund_reserved_minor >= 0 AND refunded_total_minor + refund_reserved_minor <= total_minor
        )');

        $list = "'".implode("','", self::STATES)."'";
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_status_check CHECK (status IN ({$list}))");
        /*
         * ما أكّده المزوّد له وقتُ تأكيد؛ وما لم يُحسم لا يدّعيه. و`MANUAL`
         * يُترك حرًّا: صفوفٌ قديمة وإدخالاتٌ مباشرة لا تحمل وقتًا.
         */
        DB::statement("ALTER TABLE refunds ADD CONSTRAINT refunds_settled_has_time CHECK (
            (status <> 'SUCCEEDED' OR settled_at IS NOT NULL)
            AND (status NOT IN ('PENDING', 'UNKNOWN', 'FAILED') OR settled_at IS NULL)
        )");
        // هويّة الاسترداد عند المزوّد فريدة: لا يُسجَّل استرداد المزوّد الواحد مرّتين.
        DB::statement('CREATE UNIQUE INDEX refunds_provider_refund_unique ON refunds (provider, provider_refund_id) WHERE provider_refund_id IS NOT NULL');
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS refunds_provider_refund_unique');
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_settled_has_time');
            DB::statement('ALTER TABLE refunds DROP CONSTRAINT IF EXISTS refunds_status_check');
        }

        Schema::table('webhook_deliveries', function (Blueprint $table) {
            $table->dropColumn(['kind', 'refund_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_refund_reserve_within_captured');
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('refund_reserved_minor');
        });

        Schema::table('refunds', function (Blueprint $table) {
            $table->dropIndex(['order_id', 'status']);
            $table->dropConstrainedForeignId('payment_id');
            $table->dropColumn(['status', 'provider', 'provider_refund_id', 'provider_error', 'settled_at']);
        });
    }
};
