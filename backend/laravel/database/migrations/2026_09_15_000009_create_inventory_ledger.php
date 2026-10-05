<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * المخزون دفترُ حركاتٍ لا عمودَ عدد — المراحل ٠٢٤–٠٢٥ (§٢٨ §٢٩ §٣٠).
 *
 * §٢٩ صريحة: «لا تعتمد على `quantity` قابلٍ للتعديل وحده». والسبب عمليّ لا
 * عقائديّ: عمودٌ يُنقَص ويُزاد لا يجيب عن «لماذا صار ثلاثة؟». وحين يختلف
 * الجرد عن النظام — وسيختلف — لا يبقى ما يُراجَع.
 *
 * فالدفتر هو الحقيقة، و`stock_levels` مادّةٌ مُشتقّة تُعاد بناؤها منه متى
 * شُكّ فيها. الحركة تُضاف ولا تُعدَّل: تصحيحُ خطأٍ حركةٌ معاكسة بسببٍ مكتوب،
 * لا حذفُ سطر.
 *
 * والحجز جدولٌ مستقلّ بأجل: سلّةٌ تحجز ثمّ تُهجر يجب أن تُفرِج عمّا حجزته،
 * وإلّا صار المخزون ينفد بسلالٍ لم تُدفع (§٩ «reserve required inventory»).
 *
 * المخزون مستقلٌّ عن طبيعة المنتج (§٢٨): رخصةٌ ذات عدد مقاعد تُحجز كما
 * يُحجز صندوق.
 */
return new class extends Migration
{
    /** §٣٠ — أنواع المواقع. */
    private const LOCATION_KINDS = [
        'WAREHOUSE', 'STORE', 'SUPPLIER', 'FULFILLMENT_CENTER', 'VIRTUAL_POOL',
    ];

    /** §٢٩ — حركات المخزون. */
    private const MOVEMENTS = [
        'RECEIPT', 'RESERVATION', 'RELEASE', 'SALE', 'RETURN',
        'ADJUSTMENT', 'TRANSFER', 'DAMAGE',
    ];

    public function up(): void
    {
        Schema::create('inventory_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('kind', self::LOCATION_KINDS);
            $table->char('country_code', 2)->default('SA');
            $table->string('city')->nullable();
            $table->boolean('sellable')->default(true);   // مخزونه معروضٌ للبيع
            $table->boolean('active')->default(true);
            $table->unsignedInteger('priority')->default(0); // ترتيب الاختيار
            $table->timestamps();

            $table->index(['active', 'sellable', 'priority']);
        });

        /*
         * المستوى المُشتقّ. يُقرأ كثيرًا ويُكتب مع كلّ حركة، ويُعاد بناؤه
         * من الدفتر عند الشكّ. ليس مصدر الحقيقة.
         */
        Schema::create('stock_levels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->integer('on_hand')->default(0);
            $table->integer('reserved')->default(0);
            $table->unsignedInteger('reorder_point')->nullable();
            $table->timestamp('counted_at')->nullable(); // آخر جردٍ فعليّ
            $table->timestamps();

            $table->unique(['product_variant_id', 'inventory_location_id']);
            $table->index('product_variant_id');
        });

        /* الدفتر — يُضاف ولا يُعدَّل. */
        Schema::create('stock_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->enum('movement', self::MOVEMENTS);
            /* موجبٌ يدخل وسالبٌ يخرج. صفرٌ حركةٌ لم تقع (قيدٌ أدناه). */
            $table->integer('quantity_delta');
            /* الرصيد بعد الحركة — يجعل مراجعة الدفتر ممكنةً بلا إعادة جمعٍ كامل. */
            $table->integer('balance_after');

            $table->string('reference_type')->nullable(); // order|reservation|count|transfer
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('reason', 500)->nullable();
            $table->unsignedBigInteger('actor_admin_user_id')->nullable();
            /* §٢١ — الحركة لا تُسجَّل مرّتين لنفس السبب. */
            $table->string('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_variant_id', 'inventory_location_id', 'created_at']);
            $table->index(['reference_type', 'reference_id']);
        });

        /* الحجز بأجل — ما لم يُدفع يعود. */
        Schema::create('inventory_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_location_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->enum('status', ['HELD', 'CONSUMED', 'RELEASED', 'EXPIRED'])->default('HELD');
            $table->string('holder_type'); // cart|order
            $table->unsignedBigInteger('holder_id');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('settled_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['status', 'expires_at']);
            $table->index(['holder_type', 'holder_id']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // حركةٌ بمقدار صفر سطرٌ بلا معنى يُفسد المراجعة.
            DB::statement('ALTER TABLE stock_ledger ADD CONSTRAINT stock_ledger_delta_nonzero CHECK (quantity_delta <> 0)');

            /*
             * المحجوز لا يكون سالبًا أبدًا. أمّا `on_hand` فيجوز أن يسلب:
             * الطلب المسبق والتأخّر (§٢٨) يبيعان ما لم يصل بعد، ومنعُ السالب
             * كان سيمنع استراتيجيّتين مذكورتين في التفويض.
             */
            DB::statement('ALTER TABLE stock_levels ADD CONSTRAINT stock_levels_reserved_nonnegative CHECK (reserved >= 0)');

            DB::statement('ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_quantity_positive CHECK (quantity > 0)');

            // حجزٌ قائمٌ له أجل؛ وما أُغلق له وقتُ إغلاق.
            DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_held_has_expiry CHECK (
                status <> 'HELD' OR expires_at IS NOT NULL
            )");
            DB::statement("ALTER TABLE inventory_reservations ADD CONSTRAINT inventory_reservations_settled_has_time CHECK (
                status = 'HELD' OR settled_at IS NOT NULL
            )");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_reservations');
        Schema::dropIfExists('stock_ledger');
        Schema::dropIfExists('stock_levels');
        Schema::dropIfExists('inventory_locations');
    }
};
