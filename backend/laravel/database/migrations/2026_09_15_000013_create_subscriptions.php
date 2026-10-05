<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الاشتراكات — المرحلة ٠٢٣ (§٢٣).
 *
 * §٢٣ توجب الفصل: «الاشتراك ≠ الدفعة ≠ الاستحقاق». والسبب أنّ الثلاثة
 * تفترق فعلًا وكثيرًا: اشتراكٌ قائم ودفعةٌ فشلت والوصول ما يزال مفتوحًا في
 * مهلة السماح؛ أو اشتراكٌ أُلغي والوصول باقٍ إلى نهاية المدّة المدفوعة.
 * طيُّها في حقلٍ واحد يعني إمّا قطعَ الوصول عمّن دفع أو إبقاءه لمن لم يدفع.
 *
 * ولذلك `current_period_end` منفصلٌ عن `cancel_at`: الإلغاء نيّةٌ تُسجَّل،
 * والانقطاع حدثٌ يقع في موعده.
 */
return new class extends Migration
{
    /** §٢٣ — حالات الاشتراك. */
    private const STATES = [
        'TRIALING', 'ACTIVE', 'PAST_DUE', 'GRACE', 'PAUSED',
        'CANCELED', 'EXPIRED', 'INCOMPLETE',
    ];

    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();                 // SUB-…
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_offer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); // الطلب المُنشئ

            $table->enum('status', self::STATES)->default('INCOMPLETE');

            /* لقطة الدورة — تغييرُ العرض لا يغيّر ما اشترك عليه الزبون. */
            $table->unsignedSmallInteger('interval_count');
            $table->enum('interval_unit', ['DAY', 'WEEK', 'MONTH', 'YEAR']);
            $table->integer('price_minor');
            $table->char('currency', 3)->default('SAR');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->unsignedSmallInteger('grace_days')->default(0);

            /* الإلغاء نيّةٌ تُسجَّل؛ الانقطاع يقع في موعده. */
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('paused_at')->nullable();
            $table->timestamp('resumes_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->unsignedSmallInteger('failed_payment_attempts')->default(0);
            $table->timestamps();

            $table->index(['status', 'current_period_end']);
            $table->index(['customer_id', 'status']);
        });

        /* كلّ دورةٍ صفٌّ — فالتاريخ يُقرأ ولا يُستنتج من آخر حالة. */
        Schema::create('subscription_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->enum('outcome', ['PENDING', 'PAID', 'FAILED', 'REFUNDED', 'SKIPPED_TRIAL', 'SKIPPED_PAUSE'])->default('PENDING');
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('entitlement_id')->nullable()->constrained()->nullOnDelete();
            $table->integer('amount_minor')->nullable();
            $table->string('failure_reason', 500)->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'sequence']);
        });

        /* تغيير الخطّة يُسجَّل: ترقيةٌ في منتصف الدورة سؤالٌ ماليّ لاحقًا. */
        Schema::create('subscription_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_offer_id')->nullable()->constrained('product_offers')->nullOnDelete();
            $table->foreignId('to_offer_id')->nullable()->constrained('product_offers')->nullOnDelete();
            $table->enum('kind', ['UPGRADE', 'DOWNGRADE', 'PRICE_CHANGE', 'INTERVAL_CHANGE']);
            $table->integer('proration_minor')->nullable();
            $table->string('reason', 500)->nullable();
            $table->timestamp('effective_at');
            $table->timestamps();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_interval_positive CHECK (interval_count > 0)');
            DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_price_nonnegative CHECK (price_minor >= 0)');
            // دورةٌ تنتهي قبل أن تبدأ تكسر كلّ حسابٍ للتجديد.
            DB::statement('ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_period_ordered CHECK (
                current_period_start IS NULL OR current_period_end IS NULL OR current_period_end > current_period_start
            )');
            DB::statement('ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_ordered CHECK (ends_at > starts_at)');

            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_canceled_has_time CHECK (
                status <> 'CANCELED' OR canceled_at IS NOT NULL
            )");
            // تجربةٌ بلا موعد انتهاء تجربةٌ أبديّة.
            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_trialing_has_end CHECK (
                status <> 'TRIALING' OR trial_ends_at IS NOT NULL
            )");
            // اشتراكٌ نشطٌ بلا نهاية دورة لا يُجدَّد ولا يُحصَّل.
            DB::statement("ALTER TABLE subscriptions ADD CONSTRAINT subscriptions_active_has_period CHECK (
                status NOT IN ('ACTIVE', 'PAST_DUE', 'GRACE') OR current_period_end IS NOT NULL
            )");
            DB::statement("ALTER TABLE subscription_periods ADD CONSTRAINT subscription_periods_failure_has_reason CHECK (
                outcome <> 'FAILED' OR failure_reason IS NOT NULL
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_changes');
        Schema::dropIfExists('subscription_periods');
        Schema::dropIfExists('subscriptions');
    }
};
