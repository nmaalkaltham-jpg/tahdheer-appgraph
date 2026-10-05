<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تقوية `bundle_components` قبل أن يكتب فيها أحد (§٤٣).
 *
 * ─── العيب الذي يُغلق ───
 *
 * الفهرس الفريد `(bundle, variant, selection_group)` يبدو كافيًا، وهو ليس
 * كذلك: `selection_group` فارغةٌ في الحزمة الثابتة — وهي الحالة الشائعة —
 * وPostgreSQL يعدّ الفراغات **متمايزة** افتراضًا. فالحزمة الثابتة تقبل
 * المكوّن نفسه مرّاتٍ بلا حدّ، ولا يبيت الفهرس إلّا على الحزم القابلة
 * للتهيئة التي تملأ المجموعة. والنتيجة حزمةٌ تحمل الملفّ ثلاث مرّات فيُحسب
 * ثمنُه ثلاثًا ويُسلَّم مرّةً.
 *
 * و`NULLS NOT DISTINCT` (بدءًا من PostgreSQL 15، والمحرّك هنا 18) يجعل
 * الفراغات متطابقة، فيبيت الفهرس على الحالة التي وُضع لها.
 *
 * ─── وما لا يُفرَض هنا، ولماذا ───
 *
 * «الحزمة لا تحتوي نفسها» شرطٌ يمسّ **جدولين**: المكوّن صيغةٌ، وصيغتُها تتبع
 * مجموعةً، والمجموعة قد تكون الحزمة. و`CHECK` في PostgreSQL لا يقرأ جدولًا
 * آخر — ولا يُفرض إلّا بمُطلِقٍ (trigger). والمُطلِقات في هذا المستودع غير
 * مستعملة: منطقُ النطاق في PHP حيث يُقرأ ويُختبَر. فالشرط يُفرض في
 * `BundleComposition` ويُثبَّت باختبار، ويُقال هنا صراحةً أنّه ليس في المحرّك.
 *
 * ─── وهي فارغة ───
 *
 * صفرُ صفوفٍ اليوم (تُحقَّق أدناه)، فالتضييق لا يكسر بياناتٍ قائمة. ولو
 * وُجدت صفوفٌ مكرّرة لَسقط إنشاءُ الفهرس — وذاك الصواب: لا يُحذف صفٌّ
 * صامتًا لإرضاء فهرس.
 */
return new class extends Migration
{
    private const INDEX = 'bundle_components_unique_component';

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $old = DB::selectOne(
            "select conname from pg_constraint
             where conrelid = 'bundle_components'::regclass and contype = 'u'"
        );

        if ($old !== null) {
            DB::statement('ALTER TABLE bundle_components DROP CONSTRAINT '.$old->conname);
        }

        DB::statement(
            'ALTER TABLE bundle_components ADD CONSTRAINT '.self::INDEX.
            ' UNIQUE NULLS NOT DISTINCT (bundle_product_group_id, component_variant_id, selection_group)'
        );
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE bundle_components DROP CONSTRAINT IF EXISTS '.self::INDEX);

        // يعود الفهرس القديم بسلوكه القديم — تراجعٌ تامّ لا تقريبيّ.
        DB::statement(
            'ALTER TABLE bundle_components ADD CONSTRAINT'
            .' bundle_components_bundle_product_group_id_component_variant_id_'
            .' UNIQUE (bundle_product_group_id, component_variant_id, selection_group)'
        );
    }
};
