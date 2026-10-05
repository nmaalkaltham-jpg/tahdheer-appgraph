<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الخدمات والحجوزات — المرحلتان ٠٢٨ و٠٢٥ (§٢٤ §٢٥).
 *
 * الفارق الجوهريّ عن كلّ ما سبق: المخزون هنا **وقت**، والوقت لا يُخزَّن ولا
 * يُعاد طلبه. مقعدٌ لم يُحجز الساعةَ العاشرة ذهب، ومقعدان في اللحظة نفسها
 * تعارضٌ لا نقصُ مخزون.
 *
 * ولذلك المنع في قاعدة البيانات لا في طبقة الخدمة: فحصُ التعارض ثمّ
 * الإدراج سباقٌ مفتوح، وحجزان متزامنان يمرّان معًا فيجد المعلّم زبونين في
 * الموعد نفسه. قيدُ الاستبعاد (`EXCLUDE … WITH &&`) يجعل ذلك مستحيلًا على
 * مستوى المحرّك.
 *
 * والتوقيت يُخزَّن بمنطقة: «العاشرة» بلا منطقةٍ ليست وقتًا، وحجزٌ عابرٌ
 * للمناطق يقع في ساعةٍ خاطئة بلا أن يُلاحظ أحد.
 */
return new class extends Migration
{
    /** §٢٥ — حالات الحجز. */
    private const BOOKING_STATES = [
        'PENDING', 'CONFIRMED', 'RESCHEDULED', 'ATTENDED',
        'NO_SHOW', 'CANCELLED', 'COMPLETED',
    ];

    public function up(): void
    {
        /* §٢٤ — وصف الخدمة، مربوطٌ بالصيغة. */
        Schema::create('service_definitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('service_kind', ['APPOINTMENT', 'SESSION', 'CLASS', 'EVENT', 'CONSULTATION', 'RESERVATION']);
            $table->unsignedInteger('duration_minutes');
            $table->unsignedInteger('capacity')->default(1);      // أكثر من واحد = صفّ أو فعاليّة
            $table->unsignedInteger('lead_time_minutes')->default(0);   // أقلّ مهلةٍ قبل الموعد
            $table->unsignedInteger('buffer_minutes')->default(0);      // فاصلٌ بين موعدين
            $table->enum('delivery_method', ['ONSITE', 'ONLINE', 'HYBRID', 'CUSTOMER_LOCATION'])->default('ONLINE');
            $table->string('service_area', 500)->nullable();
            $table->unsignedSmallInteger('cancellation_window_hours')->nullable();
            $table->string('deliverables', 1000)->nullable();
            $table->string('timezone')->default('Asia/Riyadh');
            $table->timestamps();
        });

        /* المورد الذي يُؤدّي الخدمة: شخصٌ أو قاعةٌ أو جهاز. */
        Schema::create('service_resources', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('kind', ['PERSON', 'ROOM', 'EQUIPMENT', 'VIRTUAL']);
            $table->unsignedInteger('capacity')->default(1);
            $table->string('timezone')->default('Asia/Riyadh');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('service_resource_availability', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_resource_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday');   // ٠ الأحد … ٦ السبت
            $table->time('starts_at');
            $table->time('ends_at');
            $table->timestamps();
        });

        /* إجازةٌ أو إغلاقٌ يغلب الإتاحة الأسبوعيّة. */
        Schema::create('service_resource_blackouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_resource_id')->constrained()->cascadeOnDelete();
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('reason', 500)->nullable();
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();     // BK-…
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('fulfillment_unit_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('service_resource_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_definition_id')->nullable()->constrained()->nullOnDelete();

            /* بمنطقةٍ زمنيّة: «العاشرة» بلا منطقةٍ ليست وقتًا. */
            $table->timestampTz('starts_at');
            $table->timestampTz('ends_at');
            $table->string('timezone');
            $table->unsignedInteger('seats')->default(1);

            $table->enum('status', self::BOOKING_STATES)->default('PENDING');
            $table->string('customer_note', 1000)->nullable();
            $table->string('cancellation_reason', 500)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['service_resource_id', 'starts_at']);
            $table->index(['status', 'starts_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE service_definitions ADD CONSTRAINT service_definitions_duration_positive CHECK (duration_minutes > 0)');
            DB::statement('ALTER TABLE service_definitions ADD CONSTRAINT service_definitions_capacity_positive CHECK (capacity > 0)');
            DB::statement('ALTER TABLE service_resource_availability ADD CONSTRAINT service_availability_ordered CHECK (ends_at > starts_at)');
            DB::statement('ALTER TABLE service_resource_availability ADD CONSTRAINT service_availability_weekday_range CHECK (weekday BETWEEN 0 AND 6)');
            DB::statement('ALTER TABLE service_resource_blackouts ADD CONSTRAINT service_blackouts_ordered CHECK (ends_at > starts_at)');
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_ordered CHECK (ends_at > starts_at)');
            DB::statement('ALTER TABLE bookings ADD CONSTRAINT bookings_seats_positive CHECK (seats > 0)');
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_cancelled_has_reason CHECK (
                status <> 'CANCELLED' OR cancellation_reason IS NOT NULL
            )");

            /*
             * منعُ التعارض على مستوى المحرّك (§٢٥).
             *
             * `btree_gist` يسمح بضمّ عمودٍ عاديّ (`service_resource_id`) إلى
             * قيد استبعادٍ على مدًى زمنيّ. بدونه لا يقبل Postgres المزج.
             *
             * الملغى والمتخلّف عن الحضور مستثنيان: موعدٌ أُلغي يُفرِج عن وقته،
             * وإلّا بقي يحجب المورد إلى الأبد.
             *
             * السعة تُتحقَّق في طبقة الخدمة لا هنا: الصفّ ذو المقاعد العشرة
             * يقبل تراكبًا مقصودًا، والقيد يخدم الموارد المفردة — ولذلك
             * يقتصر على `capacity = 1` عبر جدول الموارد في طبقة الحجز.
             */
            DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
            DB::statement("ALTER TABLE bookings ADD CONSTRAINT bookings_no_double_booking EXCLUDE USING gist (
                service_resource_id WITH =,
                tstzrange(starts_at, ends_at) WITH &&
            ) WHERE (status NOT IN ('CANCELLED', 'NO_SHOW') AND seats = 1)");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
        Schema::dropIfExists('service_resource_blackouts');
        Schema::dropIfExists('service_resource_availability');
        Schema::dropIfExists('service_resources');
        Schema::dropIfExists('service_definitions');
    }
};
