<?php

declare(strict_types=1);

use App\Domain\ContentIntelligence\Entities\EntityAgreement;
use App\Domain\ContentIntelligence\Entities\EntityDimension;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * مرشَّحو الكيانات التعليميّة — اقتراحاتٌ بشواهدها، لا حقيقةٌ صامتة.
 *
 * ─── لماذا جدولٌ منفصل لا أعمدةٌ في `products` ───
 *
 * البند ١٧٨ يشترط أن يكون تجاوز الإنسان قابلًا للتتبّع وأن ينجو من إعادة
 * التوليد؛ والبند ١٩٨ يشترط بوّابات جودة قبل الثقة بمحتوًى مولَّد؛ والبند
 * ١٢٣ يمنع اختلاق الادّعاءات. الكتابة في `products.subject_id` تخالف
 * الثلاثة دفعةً واحدة: تمحو ما كتبه إنسان، ولا تبقي أثرًا يُراجَع، وتحوّل
 * ظنًّا إلى حقيقةٍ لا يميّزها أحد بعد ثانية.
 *
 * فالجدول **مُلحق** لا بديل: `products` تبقى المرجع، وهذا الجدول يقترح.
 * لا هجرةَ هنا تلمس `products`، ولا خدمةَ تكتب فيها — والاختبار يثبت ذلك
 * ببصمة صفّ المنتج قبل الاشتقاق وبعده.
 *
 * ─── لماذا `entity_id` من دون مفتاح أجنبيّ ───
 *
 * البُعد يسمّي الجدول: `subject`→`subjects`، `grade`→`grades`… فالمفتاح
 * متعدّد الهدف ولا يقبل قيدًا واحدًا. البديل المرفوض: خمسة أعمدة مقيَّدة
 * (`subject_id`,`grade_id`,…) يبقى أربعةٌ منها NULL في كلّ صفّ، فيتحوّل
 * الجدول إلى مصفوفةٍ متفرّقة لا يقرؤها استعلام «كم مرشّحًا لكلّ بُعد» إلّا
 * بخمسة اتّحادات. والثمن مدفوعٌ صراحةً: `entity_value` يحفظ اللافتة ساعةَ
 * الاشتقاق، فمعرّفٌ معلَّقٌ بعد حذف صفٍّ من التصنيف **يُكتشف** بمقارنتها
 * بالجدول الحيّ. والرابط نفسه معرّفٌ لا لافتة — البند ١٥٤.
 *
 * ─── القيود التي تمنع صفًّا كاذبًا ───
 *
 * · `(asset_extraction_id, dimension)` فريد = تشغيلٌ متكرّر يحدّث ولا
 *   يُراكم. المرشّح اقتراحٌ واحدٌ لكلّ بُعدٍ لكلّ استخراج، وبدائلُه في
 *   `alternatives` لا في صفوفٍ موازية يحسبها التقرير مرّتين.
 * · CHECK على التلازم `(dimension='format') = (entity_id IS NULL)` =
 *   `format` وحده بلا جدولٍ يُربط به، وما عداه لا يُخزَّن بلا معرّف.
 * · CHECK على `confidence` بين صفر وواحد = نسبةُ التأييد لا عدد.
 * · لا قيد CHECK على `status` هنا لأنّ لا `status` — بخلاف
 *   `asset_extractions` التي منعتها صفوفٌ قديمة، هذا الجدول يولد نظيفًا
 *   فمفرداتُه تُفرَض في القاعدة لا في النطاق وحده.
 *
 * إضافيّة بالكامل: جدولٌ جديد، لا عمود يُعدَّل، ولا صفٌّ قائمٌ يتأثّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('entity_candidates')) {
            return;
        }

        Schema::create('entity_candidates', function (Blueprint $table) {
            $table->id();

            // ── من أين جاء: الاستخراج، الأصل، بصمة البايتات، المستخرِج ──
            $table->foreignId('asset_extraction_id')->nullable()
                ->constrained('asset_extractions')->cascadeOnDelete();
            // الأصل يُصفَّر ولا يُحذف الصفّ: البصمة تبقى مفتاح المحتوى،
            // كما في `asset_extractions` تمامًا.
            $table->foreignId('asset_id')->nullable()
                ->constrained('assets')->nullOnDelete();
            $table->string('asset_sha256', 64);
            $table->string('extractor', 40);

            // المنتج المربوط عبر `product_files` — قد يغيب (أصلٌ يتيم).
            $table->foreignId('product_id')->nullable()
                ->constrained('products')->nullOnDelete();

            // ── ما هو الاقتراح ──
            $table->string('dimension', 16);
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->string('entity_value', 64)->nullable();

            // ── الشاهد: المقطع نفسه وموضعه بالبايت في نصّ الاستخراج ──
            // 200 حرفًا سقفٌ مقيس: أطول سطرٍ في الاستخراجات الحقيقيّة ٩٩
            // حرفًا (قياس ٢٠٢٦-٠٩-٢٣). والمخزَّن بادئةُ السطر من الموضع
            // نفسه، فيبقى `substr(text, offset)` يبدأ بالشاهد دائمًا.
            $table->string('evidence_text', 200);
            $table->unsignedInteger('evidence_offset');

            // ── قوّة الشاهد: كم سطرًا أيّد، وكم سطرًا قال غيره ──
            $table->unsignedSmallInteger('support');
            $table->unsignedSmallInteger('conflicts')->default(0);
            $table->json('alternatives')->nullable();
            $table->decimal('confidence', 4, 3);

            // ── موقفه من الفهرس ──
            $table->unsignedBigInteger('catalog_entity_id')->nullable();
            $table->string('catalog_value', 64)->nullable();
            $table->string('agreement', 16);

            $table->string('derivation_version', 20);
            $table->timestamp('derived_at');
            $table->timestamps();

            $table->unique(['asset_extraction_id', 'dimension'], 'entity_candidates_extraction_dimension_unique');
            // التقرير الوحيد الذي يهمّ إنسانًا: «أرِني المخالفات لكلّ بُعد».
            $table->index(['dimension', 'agreement'], 'entity_candidates_dimension_agreement_index');
            $table->index('asset_sha256', 'entity_candidates_asset_sha256_index');
        });

        $dimensions = $this->quotedList(EntityDimension::values());
        $agreements = $this->quotedList(EntityAgreement::values());

        DB::statement("ALTER TABLE entity_candidates ADD CONSTRAINT entity_candidates_dimension_check CHECK (dimension IN ({$dimensions}))");
        DB::statement("ALTER TABLE entity_candidates ADD CONSTRAINT entity_candidates_agreement_check CHECK (agreement IN ({$agreements}))");
        DB::statement("ALTER TABLE entity_candidates ADD CONSTRAINT entity_candidates_format_has_no_id_check CHECK ((dimension = 'format') = (entity_id IS NULL))");
        DB::statement('ALTER TABLE entity_candidates ADD CONSTRAINT entity_candidates_confidence_range_check CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('entity_candidates');
    }

    /** @param  array<int, string>  $values */
    private function quotedList(array $values): string
    {
        return implode(', ', array_map(fn (string $v) => "'".str_replace("'", "''", $v)."'", $values));
    }
};
