<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * إعدادات المكوّنات — التخزين الذي لم يكن موجودًا.
 *
 * محرّر المكوّنات في اللوحة يحرّر ثلاثة وثلاثين ضابطًا، ولا نقطة نهاية واحدة
 * تحفظها: التعديل يعيش في حالة React ويضيع مع أوّل إعادة تحميل. وكذلك محرّر
 * الأبطال (الهدف والهيكل).
 *
 * قرارات المخطّط:
 *
 *  · مسوّدة ومنشور عمودان منفصلان لا صفّان بحالة. المتجر العام يقرأ
 *    `published_settings` وحده، فلا يوجد مسار يجعله يقرأ مسوّدة بالخطأ —
 *    وهو أقوى من الاعتماد على مرشّح `status` في كل استعلام.
 *
 *  · `version` عدّاد تفاؤلي. مشرفان يفتحان العنصر نفسه: الثاني يرسل نسخته
 *    التي قرأها، فإن تغيّرت رُدّ 409 بدل سحق تغيير زميله بصمت.
 *
 *  · JSONB للإعدادات لأن الضوابط ثلاثة وثلاثون وتتغيّر — لكنها ليست حرّة:
 *    ComponentSettings يطبّعها ويرفض ما ليس في المخطّط، فلا يصل شيء غير
 *    متحقَّق منه إلى القرص.
 *
 *  · `component_key` فريد: العنصر هوية لا صفّ متكرّر.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_components', function (Blueprint $table) {
            $table->id();
            // «tah/collection.0101» — معرّف السجلّ، لا رقم داخلي.
            $table->string('component_key', 120)->unique();
            $table->string('component_type', 60);
            $table->string('variant', 60)->nullable();

            $table->json('draft_settings');
            $table->json('published_settings')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('published_version')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['component_type', 'published_at']);
        });

        /*
         * تاريخ الإصدارات — للعودة إلى إصدار سابق.
         * لقطة كاملة لا فرق: الوثيقة صغيرة، والفروق المتسلسلة تحتاج إعادة
         * بناء لكل استرجاع وتنكسر بأوّل تغيير في المخطّط.
         */
        Schema::create('design_component_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('component_id')->constrained('design_components')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->json('settings');
            $table->foreignId('created_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->unique(['component_id', 'version']);
        });

        /*
         * الأبطال: الهدف والهيكل كانا يعيشان في الجلسة وحدها.
         * `target_type` + `target_ref` مرجع حقيقي لا نصّ: بطل مربوط بمنتج
         * يحمل معرّفه، فيُتحقَّق من وجوده عند الحفظ ويُحلّ عند العرض.
         */
        Schema::create('design_heroes', function (Blueprint $table) {
            $table->id();
            $table->string('hero_key', 120)->unique();
            $table->string('name', 160);

            $table->string('target_type', 40);           // homepage | category | product | search | campaign | custom
            $table->string('target_ref', 190)->nullable(); // معرّف الكيان أو المسار

            $table->string('structure', 60);
            $table->string('variant', 60)->nullable();

            $table->json('draft_settings');
            $table->json('published_settings')->nullable();

            $table->unsignedInteger('version')->default(1);
            $table->unsignedInteger('published_version')->nullable();

            $table->foreignId('updated_by')->nullable()->constrained('admin_users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['target_type', 'target_ref', 'published_at']);
        });

        if (DB::getDriverName() === 'pgsql') {
            // نسخة منشورة تعني وجود إعدادات منشورة — لا نصفَ نشرٍ في القاعدة.
            DB::statement('ALTER TABLE design_components ADD CONSTRAINT design_components_publish_pair
                CHECK ((published_version IS NULL) = (published_settings IS NULL))');
            DB::statement('ALTER TABLE design_heroes ADD CONSTRAINT design_heroes_publish_pair
                CHECK ((published_version IS NULL) = (published_settings IS NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('design_component_versions');
        Schema::dropIfExists('design_components');
        Schema::dropIfExists('design_heroes');
    }
};
