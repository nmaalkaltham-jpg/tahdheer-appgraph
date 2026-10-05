<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * محرّك الشحن — المرحلة ٠٢٦ (§٢٦ §٣١ §١٠٨).
 *
 * «لا تدفن منطق الشحن في واجهة الدفع» (§٣١). فالتسعير قواعدُ في قاعدة
 * البيانات تُقرأ وتُراجَع، لا شروطٌ في مكوّن واجهة يعرفها من كتبها وحده.
 *
 * والشحنة ليست حقلًا على الطلب بل كيانًا: طلبٌ واحد قد يُشحن على دفعتين من
 * موقعين، ولكلٍّ تتبّعُها وحالتها. ربطُها بوحدة التنفيذ (§١٠) يجعل «شُحن
 * نصف الطلب» حالةً قابلةً للتمثيل بدل أن تكون عطلًا.
 *
 * وصنف الشحن (§١٠٨) على الصيغة لا على الفئة: «كتاب» و«لوح قابل للكسر» قد
 * يكونان في فئةٍ واحدة ويُشحنان بقواعد مختلفة.
 */
return new class extends Migration
{
    /** §٢٦ — حالات الشحنة. */
    private const SHIPMENT_STATES = [
        'PENDING', 'LABEL_CREATED', 'PICKED_UP', 'IN_TRANSIT', 'OUT_FOR_DELIVERY',
        'DELIVERED', 'FAILED', 'RETURNED', 'CANCELLED',
    ];

    public function up(): void
    {
        /* §١٠٨ — صنف الشحن: أهليّة الناقل والتسعير والتغليف. */
        Schema::create('shipping_classes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->boolean('requires_signature')->default(false);
            $table->boolean('fragile')->default(false);
            $table->boolean('hazardous')->default(false);
            $table->unsignedInteger('handling_days')->default(0); // أيام التجهيز قبل التسليم للناقل
            $table->timestamps();
        });

        Schema::create('carriers', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();   // smsa|aramex|naqel|spl
            $table->string('name');
            $table->boolean('active')->default(true);
            /*
             * قالبُ التتبّع نصٌّ فيه `{tracking}`. بهذا يُبنى رابط التتبّع من
             * بيانات الناقل لا من شرطٍ في الواجهة يُنسى عند إضافة ناقل.
             */
            $table->string('tracking_url_template')->nullable();
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();
        });

        /* §٣١ — المنطقة: مجموعة وجهاتٍ تتشارك القواعد. */
        Schema::create('shipping_zones', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->json('country_codes');        // ["SA"] أو ["SA","AE"]
            $table->json('regions')->nullable();  // فارغ = كل مناطق الدولة
            $table->unsignedInteger('priority')->default(0); // الأخصّ يفوز
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        /*
         * القاعدة السعريّة. النطاقات نصفُ مفتوحة `[min, max)` عمدًا: نطاقان
         * متلاصقان بحدّين مغلقين يتراكبان عند نقطة التماسّ، فيُختار السعر
         * بحسب ترتيب الاستعلام لا بحسب قاعدة — وهو خطأٌ لا يظهر إلّا عند
         * الوزن الحدّيّ بالضبط.
         */
        Schema::create('shipping_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipping_zone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('carrier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shipping_class_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('service_name');      // «قياسي» · «سريع»

            $table->unsignedInteger('min_weight_grams')->default(0);
            $table->unsignedInteger('max_weight_grams')->nullable(); // فارغ = بلا سقف
            $table->integer('min_order_minor')->default(0);
            $table->integer('max_order_minor')->nullable();

            $table->integer('price_minor');
            /* مجّانيٌّ فوق مبلغ — فارغ يعني لا مجّانيّة، لا «مجّانيّ دائمًا». */
            $table->integer('free_over_minor')->nullable();

            $table->unsignedSmallInteger('min_days')->nullable();
            $table->unsignedSmallInteger('max_days')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedInteger('priority')->default(0);
            $table->timestamps();

            $table->index(['shipping_zone_id', 'active', 'priority']);
        });

        /* الشحنة — كيانٌ مربوطٌ بوحدة تنفيذ. */
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fulfillment_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('carrier_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('inventory_location_id')->nullable()->constrained()->nullOnDelete();

            $table->string('service_name')->nullable();
            $table->string('tracking_number')->nullable();
            $table->enum('status', self::SHIPMENT_STATES)->default('PENDING');
            $table->integer('shipping_cost_minor')->nullable();
            $table->unsignedInteger('weight_grams')->nullable();

            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();
            $table->string('last_error', 1000)->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('tracking_number');
        });

        /* ما في الشحنة — طلبٌ قد ينقسم على شحنتين. */
        Schema::create('shipment_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();

            $table->unique(['shipment_id', 'order_item_id']);
        });

        /* §٢٧ — الطرود بأبعادها. الوحدة عمودٌ لا لاحقةٌ في نصّ. */
        Schema::create('shipment_packages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shipment_id')->constrained()->cascadeOnDelete();
            $table->string('package_type')->nullable();
            $table->unsignedInteger('weight_grams');
            $table->unsignedInteger('length_mm')->nullable();
            $table->unsignedInteger('width_mm')->nullable();
            $table->unsignedInteger('height_mm')->nullable();
            $table->string('tracking_number')->nullable();
            $table->timestamps();
        });

        /* §١٠٨ — الصنف على الصيغة. */
        Schema::table('product_variants', function (Blueprint $table) {
            $table->foreignId('shipping_class_id')->nullable()->after('height_mm')->constrained()->nullOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_price_nonnegative CHECK (price_minor >= 0)');
            // نطاقٌ مقلوب لا يُطابق شيئًا أبدًا، فيختفي سعرٌ ظنّ المشغّل أنّه أضافه.
            DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_weight_range_ordered CHECK (max_weight_grams IS NULL OR max_weight_grams > min_weight_grams)');
            DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_order_range_ordered CHECK (max_order_minor IS NULL OR max_order_minor > min_order_minor)');
            DB::statement('ALTER TABLE shipping_rates ADD CONSTRAINT shipping_rates_days_ordered CHECK (min_days IS NULL OR max_days IS NULL OR max_days >= min_days)');

            // «سُلّمت» بلا وقتِ تسليم لا تُدقَّق ولا تُحاسَب عليها.
            DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_delivered_has_time CHECK (status <> 'DELIVERED' OR delivered_at IS NOT NULL)");
            // شحنةٌ في الطريق بلا رقم تتبّع لا يستطيع الزبون ولا الدعم متابعتها.
            DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_in_transit_has_tracking CHECK (
                status NOT IN ('IN_TRANSIT', 'OUT_FOR_DELIVERY', 'DELIVERED') OR tracking_number IS NOT NULL
            )");
            DB::statement("ALTER TABLE shipments ADD CONSTRAINT shipments_failed_has_reason CHECK (status <> 'FAILED' OR last_error IS NOT NULL)");
            DB::statement('ALTER TABLE shipment_items ADD CONSTRAINT shipment_items_quantity_positive CHECK (quantity > 0)');
            DB::statement('ALTER TABLE shipment_packages ADD CONSTRAINT shipment_packages_weight_positive CHECK (weight_grams > 0)');
        }
    }

    public function down(): void
    {
        Schema::table('product_variants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shipping_class_id');
        });

        Schema::dropIfExists('shipment_packages');
        Schema::dropIfExists('shipment_items');
        Schema::dropIfExists('shipments');
        Schema::dropIfExists('shipping_rates');
        Schema::dropIfExists('shipping_zones');
        Schema::dropIfExists('carriers');
        Schema::dropIfExists('shipping_classes');
    }
};
