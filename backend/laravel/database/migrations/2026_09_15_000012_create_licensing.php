<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * الرخص ومفاتيحها — المرحلة ٠٢٢ (§١٩ §٢٠).
 *
 * §١٩ تقول: «لا تخلط ملكيّة المنتج بصلاحيّات الرخصة». الاستحقاق يجيب «هل
 * يحقّ له التنزيل؟»، والرخصة تجيب سؤالًا آخر تمامًا: «ماذا يحقّ له أن يفعل
 * بما نزّله؟» — استعمالٌ شخصيّ أم صفّيّ أم مؤسّسيّ، وهل يُعاد توزيعه، وكم
 * مقعدًا. خلطُهما يجعل «اشترى» تعني «يملك كلّ الحقوق»، وهو ما لم يُبَع.
 *
 * ومخزون المفاتيح (§٢٠) لا يُعرض علنًا أبدًا: مفتاحٌ غير مُسنَد قيمةٌ
 * قابلة للسرقة، فالاستعلامات العامّة لا تلمس هذا الجدول.
 */
return new class extends Migration
{
    /** §١٩ — أنواع الرخص. */
    private const LICENSE_TYPES = [
        'PERSONAL', 'SINGLE_USER', 'MULTI_USER', 'TEAM', 'INSTITUTION', 'COMMERCIAL', 'CUSTOM',
    ];

    /** §٢٠ — دورة حياة المفتاح. */
    private const KEY_STATES = ['AVAILABLE', 'RESERVED', 'ASSIGNED', 'ACTIVATED', 'REVOKED', 'EXPIRED'];

    public function up(): void
    {
        /* قالب الرخصة — يُشار إليه من العرض. */
        Schema::create('license_terms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->enum('license_type', self::LICENSE_TYPES);
            $table->unsignedInteger('seat_count')->nullable();   // فارغ = غير محدود/غير منطبق
            $table->string('usage_scope', 1000)->nullable();     // «صفّ واحد» · «مدرسة»
            $table->boolean('commercial_rights')->default(false);
            $table->boolean('redistribution_rights')->default(false);
            $table->boolean('modification_rights')->default(false);
            $table->unsignedSmallInteger('validity_days')->nullable(); // فارغ = دائم
            /* نسخة النصّ القانونيّ: تغييرُ الشروط لا يُعيد كتابة ما وُقّع عليه. */
            $table->string('terms_version');
            $table->text('terms_text')->nullable();
            $table->timestamps();
        });

        Schema::table('product_offers', function (Blueprint $table) {
            $table->foreignId('license_terms_id')->nullable()->after('return_policy_id')->constrained()->nullOnDelete();
        });

        /*
         * الرخصة الممنوحة — لقطةٌ لا مرجع (§١٠١). تعديلُ القالب اليوم لا
         * يوسّع ولا يضيّق حقوق من اشترى أمس.
         */
        Schema::create('granted_licenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('entitlement_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            $table->enum('license_type', self::LICENSE_TYPES);
            $table->unsignedInteger('seat_count')->nullable();
            $table->unsignedInteger('seats_used')->default(0);
            $table->string('usage_scope', 1000)->nullable();
            $table->boolean('commercial_rights')->default(false);
            $table->boolean('redistribution_rights')->default(false);
            $table->boolean('modification_rights')->default(false);
            $table->string('terms_version');

            $table->timestamp('starts_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->enum('status', ['ACTIVE', 'EXPIRED', 'REVOKED'])->default('ACTIVE');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->unique('order_item_id');
            $table->index(['customer_id', 'status']);
        });

        /*
         * مخزون المفاتيح. §٢٠: «لا يُعرض المخزون غير المستعمل علنًا».
         * المفتاح مُجزّأ لا مخزَّنٌ صريحًا حيث أمكن — لكنّ بعض المزوّدين
         * يوجبون ردَّه للزبون، فيُخزَّن مع تقييد الوصول في طبقة الخدمة.
         */
        Schema::create('license_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
            $table->string('key_value');
            $table->string('key_fingerprint', 64)->unique(); // sha256 — يمنع التكرار بلا كشف
            $table->enum('source', ['PRE_GENERATED', 'PROVIDER_GENERATED', 'API_GENERATED', 'MANUAL']);
            $table->enum('status', self::KEY_STATES)->default('AVAILABLE');

            $table->foreignId('granted_license_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('revocation_reason', 500)->nullable();
            $table->timestamps();

            $table->index(['product_variant_id', 'status']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // مقاعدُ مستعملة أكثر من المتاحة تعني رخصةً تُخالَف بلا أن يُلاحظ أحد.
            DB::statement('ALTER TABLE granted_licenses ADD CONSTRAINT granted_licenses_seats_within_limit CHECK (
                seat_count IS NULL OR seats_used <= seat_count
            )');
            DB::statement("ALTER TABLE granted_licenses ADD CONSTRAINT granted_licenses_revoked_has_reason CHECK (
                status <> 'REVOKED' OR (revoked_at IS NOT NULL AND revocation_reason IS NOT NULL)
            )");

            /*
             * مفتاحٌ مُسنَد بلا مالك تناقضٌ صامت: يختفي من المتاح ولا يصل
             * أحدًا، فينفد المخزون بمفاتيحَ ضائعة.
             */
            DB::statement("ALTER TABLE license_keys ADD CONSTRAINT license_keys_assigned_has_owner CHECK (
                status NOT IN ('ASSIGNED', 'ACTIVATED') OR granted_license_id IS NOT NULL
            )");
            // الحجز بلا أجل يجمّد مفتاحًا إلى الأبد.
            DB::statement("ALTER TABLE license_keys ADD CONSTRAINT license_keys_reserved_has_expiry CHECK (
                status <> 'RESERVED' OR reserved_until IS NOT NULL
            )");
            DB::statement("ALTER TABLE license_keys ADD CONSTRAINT license_keys_revoked_has_reason CHECK (
                status <> 'REVOKED' OR revocation_reason IS NOT NULL
            )");
        }
    }

    public function down(): void
    {
        Schema::table('product_offers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('license_terms_id');
        });

        Schema::dropIfExists('license_keys');
        Schema::dropIfExists('granted_licenses');
        Schema::dropIfExists('license_terms');
    }
};
