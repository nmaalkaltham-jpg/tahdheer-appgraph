<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الإرجاع وسياساته — المرحلة ٠٢٧ (§٣٣ §١٠٩).
 *
 * §١٠٩ صريحة: «لا تُثبّت افتراضَ إرجاعٍ واحدًا للجميع». والسبب أنّ الأنواع
 * تختلف اختلافًا جوهريًّا: ملفٌّ نُزِّل لا يُعاد، وصندوقٌ يعود بحالته،
 * وخدمةٌ أُدّيت لا تُسترجع، واشتراكٌ يُلغى ولا يُرجَع. سياسةٌ واحدة تعني
 * إمّا رفضَ إرجاعٍ مشروع أو قبولَ إرجاعٍ مستحيل.
 *
 * فالسياسة كيانٌ يُشار إليه من العرض، والطلب يحمل **لقطتها** لا مرجعها:
 * تعديلُ السياسة اليوم لا يجوز أن يغيّر ما وُعد به مشترٍ قبل شهر (§١٠١).
 *
 * والإرجاع طلبٌ ذو حالات (§٣٣) لا علمٌ منطقيّ: بينه وبين المال خطواتٌ
 * تُراجَع — فحصُ البضاعة قبل ردّ الثمن ليس تفصيلًا إداريًّا بل ما يمنع
 * ردّ ثمنِ صندوقٍ لم يصل.
 */
return new class extends Migration
{
    /** §٣٣ — دورة حياة طلب الإرجاع. */
    private const STATES = [
        'REQUESTED', 'APPROVED', 'REJECTED', 'IN_TRANSIT',
        'RECEIVED', 'INSPECTED', 'REFUNDED', 'CLOSED',
    ];

    public function up(): void
    {
        Schema::create('return_policies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description', 1000)->nullable();

            /*
             * `returnable=false` هو الافتراض للرقميّ: ملفٌّ نُزِّل لا يُعاد.
             * وهو تصريحٌ لا صمت — والفرق أنّ الواجهة تستطيع أن تقول للزبون
             * لماذا، بدل أن يختفي الزرّ بلا تفسير.
             */
            $table->boolean('returnable')->default(true);
            $table->unsignedSmallInteger('window_days')->nullable(); // فارغ = بلا حدّ زمنيّ
            $table->unsignedSmallInteger('restocking_fee_bps')->default(0);
            $table->boolean('requires_inspection')->default(true);
            $table->boolean('requires_original_packaging')->default(false);
            $table->boolean('customer_pays_return_shipping')->default(false);
            /* التنزيل بعد الشراء يُسقط حقّ الإرجاع في المنتجات الرقميّة. */
            $table->boolean('voided_by_download')->default(false);
            $table->timestamps();
        });

        Schema::table('product_offers', function (Blueprint $table) {
            $table->foreignId('return_policy_id')->nullable()->after('tax_class_id')->constrained()->nullOnDelete();
        });

        Schema::create('return_requests', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();           // RMA-…
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('status', self::STATES)->default('REQUESTED');

            $table->string('customer_reason', 1000);
            $table->string('resolution_note', 1000)->nullable();
            $table->string('rejection_reason', 1000)->nullable();

            /* §١٠١ — لقطة السياسة وقت الطلب، لا مرجعٌ يتغيّر تحتها. */
            $table->json('policy_snapshot')->nullable();

            $table->integer('refund_amount_minor')->nullable();
            $table->integer('restocking_fee_minor')->nullable();
            $table->foreignId('refund_id')->nullable()->constrained()->nullOnDelete();

            $table->timestamp('requested_at')->useCurrent();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->timestamp('inspected_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index(['status', 'requested_at']);
        });

        Schema::create('return_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('reason')->nullable();
            /* حالة ما وصل — تقرّر إعادته إلى المخزون أو إتلافه. */
            $table->enum('received_condition', ['UNOPENED', 'OPENED', 'USED', 'DAMAGED', 'MISSING'])->nullable();
            $table->enum('outcome', ['RESTOCK', 'DAMAGE', 'DISCARD', 'REJECTED'])->nullable();
            $table->integer('refund_minor')->nullable();
            $table->timestamps();

            $table->unique(['return_request_id', 'order_item_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE return_request_items ADD CONSTRAINT return_request_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE return_policies ADD CONSTRAINT return_policies_fee_within_range CHECK (restocking_fee_bps BETWEEN 0 AND 10000)');

            // رفضٌ بلا سبب مكتوب لا يُشرح للزبون ولا يُراجَع لاحقًا.
            DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_rejected_has_reason CHECK (
                status <> 'REJECTED' OR rejection_reason IS NOT NULL
            )");

            /*
             * لا ردّ مالٍ بلا مبلغ ولا وقت. هذا ما يمنع «رُدّ الثمن» علمًا
             * منطقيًّا بلا رقمٍ يُطابَق مع الدفتر المحاسبيّ (§١٠٢).
             */
            DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_refunded_has_amount CHECK (
                status <> 'REFUNDED' OR (refund_amount_minor IS NOT NULL AND refunded_at IS NOT NULL)
            )");

            // ما وصل يُسجَّل وقتُ وصوله — وإلّا استحال قياس مدّة الفحص.
            DB::statement("ALTER TABLE return_requests ADD CONSTRAINT return_requests_received_has_time CHECK (
                status NOT IN ('RECEIVED', 'INSPECTED') OR received_at IS NOT NULL
            )");

            DB::statement('ALTER TABLE return_requests ADD CONSTRAINT return_requests_refund_nonnegative CHECK (
                refund_amount_minor IS NULL OR refund_amount_minor >= 0
            )');
        }
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('return_policy_id');
        });

        Schema::dropIfExists('return_request_items');
        Schema::dropIfExists('return_requests');
        Schema::dropIfExists('return_policies');
    }
};
