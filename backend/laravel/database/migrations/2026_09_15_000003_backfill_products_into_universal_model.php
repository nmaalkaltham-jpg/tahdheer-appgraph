<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ترحيل المنتجات القائمة إلى النموذج الكونيّ — خطوة «الترحيل» من §١١.
 *
 * كلّ منتجٍ قائم يصير مجموعةً ذات صيغةٍ واحدة وعرضٍ واحد واستراتيجيّة تسليمٍ
 * واحدة. لا شيء يُحذف ولا يُعدَّل: `products` و`product_prices` و`product_facts`
 * تبقى كما هي، والطلبات الـ١٥٣ والاستحقاقات الـ٢٧ لا تُمسّ. النموذجان
 * يتعايشان حتّى يثبت التكافؤ السلوكيّ، وعندها وحدها تبدأ خطوة التقليص.
 *
 * والأعمدة الثلاثة المخبوزة في `products` — `resource_type` و`format`
 * و`editable` — تُرحَّل سماتٍ مكتوبةَ النوع في السجلّ (§٤٢)، ومعها مفاتيح
 * `product_facts` الخمسة بوحداتها الصريحة (§٥٠). فـ«١٢٠» تصير عددًا وحدته
 * `page` يُفرز ويُقارن، لا نصًّا في عمود.
 *
 * الهجرة قابلة لإعادة التشغيل: ما له مجموعةٌ بالفعل يُتخطّى.
 */
return new class extends Migration
{
    /**
     * تعريفات السمات المستخرجة من الأعمدة المخبوزة ومن `product_facts`.
     * الوحدة صريحة، والنوع صريح، والسلوك صريح — وهذا هو الفرق كلّه عن EAV.
     */
    private function definitions(): array
    {
        return [
            // §٤٢ — كانت أعمدةً في `products`.
            ['key' => 'resource_type', 'name' => 'نوع المورد', 'data_type' => 'TEXT', 'unit_type' => null,
                'allowed_values' => null, 'variant_defining' => false, 'search_weight' => 60],
            // صيغة الملفّ مفردةٌ مُعرِّفة للصيغة صراحةً في §٤٢، ومفرداتها تقنيّة مغلقة.
            ['key' => 'file_format', 'name' => 'صيغة الملفّ', 'data_type' => 'ENUM', 'unit_type' => null,
                'allowed_values' => ['PDF', 'DOCX', 'PPTX'], 'variant_defining' => true, 'search_weight' => 50],
            ['key' => 'editable', 'name' => 'قابل للتحرير', 'data_type' => 'BOOLEAN', 'unit_type' => null,
                'allowed_values' => null, 'variant_defining' => false, 'search_weight' => 30],

            // §٥٠ — كانت `product_facts` أرقامًا بلا وحدة.
            ['key' => 'pages_count', 'name' => 'عدد الصفحات', 'data_type' => 'INTEGER', 'unit_type' => 'page',
                'allowed_values' => null, 'variant_defining' => false, 'search_weight' => 20],
            ['key' => 'slides_count', 'name' => 'عدد الشرائح', 'data_type' => 'INTEGER', 'unit_type' => 'slide',
                'allowed_values' => null, 'variant_defining' => false, 'search_weight' => 20],
            ['key' => 'lessons_count', 'name' => 'عدد الدروس', 'data_type' => 'INTEGER', 'unit_type' => 'lesson',
                'allowed_values' => null, 'variant_defining' => false, 'search_weight' => 20],
            ['key' => 'worksheets_count', 'name' => 'عدد أوراق العمل', 'data_type' => 'INTEGER', 'unit_type' => 'worksheet',
                'allowed_values' => null, 'variant_defining' => false, 'search_weight' => 20],
            ['key' => 'activities_count', 'name' => 'عدد الأنشطة', 'data_type' => 'INTEGER', 'unit_type' => 'activity',
                'allowed_values' => null, 'variant_defining' => false, 'search_weight' => 20],
        ];
    }

    public function up(): void
    {
        $now = now();

        /*
         * §١٠٧ — ضريبة القيمة المضافة السعوديّة ١٥٪. الطلبات تستخرجها من الثمن
         * ولا تضيفها إليه (`orders.vat_minor`: «extracted, never added»)، فالصنف
         * هنا يصف الواقع القائم لا يغيّره.
         */
        $taxClassId = DB::table('tax_classes')->where('code', 'SA_VAT_STANDARD')->value('id')
            ?? DB::table('tax_classes')->insertGetId([
                'code' => 'SA_VAT_STANDARD', 'name' => 'ضريبة القيمة المضافة — القياسيّة',
                'rate_bps' => 1500, 'jurisdiction' => 'SA',
                'created_at' => $now, 'updated_at' => $now,
            ]);

        /* سجلّ السمات. */
        $attributeIds = [];
        foreach ($this->definitions() as $d) {
            $attributeIds[$d['key']] = DB::table('attribute_definitions')->where('key', $d['key'])->value('id')
                ?? DB::table('attribute_definitions')->insertGetId([
                    'key' => $d['key'],
                    'name' => $d['name'],
                    'data_type' => $d['data_type'],
                    'unit_type' => $d['unit_type'],
                    'allowed_values' => $d['allowed_values'] === null ? null : json_encode($d['allowed_values'], JSON_UNESCAPED_UNICODE),
                    'required' => false,
                    'variant_defining' => $d['variant_defining'],
                    'searchable' => true,
                    'filterable' => true,
                    'facetable' => true,
                    'sortable' => $d['data_type'] === 'INTEGER',
                    'comparison_enabled' => true,
                    'seo_exposable' => true,
                    'search_weight' => $d['search_weight'],
                    'locale_strategy' => 'SHARED',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
        }

        $factKeys = ['pages_count', 'slides_count', 'lessons_count', 'worksheets_count', 'activities_count'];
        $factUnits = array_column($this->definitions(), 'unit_type', 'key');

        DB::transaction(function () use ($now, $taxClassId, $attributeIds, $factKeys, $factUnits) {
            $done = DB::table('product_groups')->whereNotNull('legacy_product_id')->pluck('legacy_product_id')->all();
            $skip = array_flip($done);

            DB::table('products')->orderBy('id')->each(function ($p) use ($now, $taxClassId, $attributeIds, $factKeys, $factUnits, $skip) {
                if (isset($skip[$p->id])) {
                    return; // مُرحَّلٌ سلفًا
                }

                /*
                 * §٦ — الطبيعة تُشتقّ من `fulfillment_type` القائم. القيم الستّ
                 * والعشرون كلّها `digital`، لكنّ الاشتقاق صريحٌ لا مفترَض:
                 * قيمةٌ غير معروفة تقف بالهجرة بدل أن تخمّن طبيعةً خاطئة.
                 */
                $nature = match ($p->fulfillment_type) {
                    'digital' => 'DIGITAL',
                    'physical' => 'PHYSICAL',
                    'service' => 'SERVICE',
                    'hybrid' => 'HYBRID',
                    default => throw new RuntimeException(
                        "fulfillment_type غير معروف على المنتج {$p->id}: «{$p->fulfillment_type}». "
                        .'أضف تعيينًا صريحًا قبل الترحيل — لا طبيعة افتراضيّة.'
                    ),
                };

                $strategy = match ($nature) {
                    'DIGITAL' => 'DIGITAL_DOWNLOAD',
                    'PHYSICAL' => 'PHYSICAL_SHIPMENT',
                    'SERVICE' => 'MANUAL_SERVICE',
                    'HYBRID' => 'DIGITAL_DOWNLOAD',
                };

                $groupId = DB::table('product_groups')->insertGetId([
                    'slug' => $p->slug,
                    'title' => $p->title,
                    'subtitle' => $p->subtitle,
                    'short_desc' => $p->short_desc,
                    'description' => $p->description,
                    'product_nature' => $nature,
                    'status' => $p->status,
                    'stage_id' => $p->stage_id,
                    'grade_id' => $p->grade_id,
                    'subject_id' => $p->subject_id,
                    'semester_id' => $p->semester_id,
                    'legacy_product_id' => $p->id,
                    'published_at' => $p->published_at,
                    'created_at' => $p->created_at ?? $now,
                    'updated_at' => $now,
                ]);

                $variantId = DB::table('product_variants')->insertGetId([
                    'product_group_id' => $groupId,
                    'sku' => $p->sku,
                    'slug' => $p->slug,
                    'title' => null, // صيغةٌ واحدة: عنوان المجموعة يكفي
                    'position' => 0,
                    'status' => $p->status,
                    'legacy_product_id' => $p->id,
                    'created_at' => $p->created_at ?? $now,
                    'updated_at' => $now,
                ]);

                /*
                 * العرض من `product_prices`. كلّ منتجٍ له صفُّ سعرٍ واحد
                 * (مقيس: ٢٦ منتجًا · ٠ بلا سعر · أقصى صفوفٍ لمنتج = ١).
                 * غياب السعر يوقف الترحيل بدل أن يخترع صفرًا (§١٥٨).
                 */
                $price = DB::table('product_prices')->where('product_id', $p->id)->orderBy('id')->first();
                if ($price === null) {
                    throw new RuntimeException("المنتج {$p->id} بلا صفّ سعر — لا عرض بلا ثمن حقيقيّ.");
                }

                $offerId = DB::table('product_offers')->insertGetId([
                    'product_variant_id' => $variantId,
                    'selling_model' => 'ONE_TIME',
                    'inventory_strategy' => 'UNLIMITED', // رقميّ: لا مخزون يُستنفد
                    'regular_minor' => $price->regular_minor,
                    'sale_minor' => $price->sale_minor,
                    'sale_from' => $price->sale_from,
                    'sale_to' => $price->sale_to,
                    'currency' => $price->currency,
                    'tax_class_id' => $taxClassId,
                    'status' => $p->status === 'published' ? 'active' : 'draft',
                    'created_at' => $price->created_at ?? $now,
                    'updated_at' => $now,
                ]);

                DB::table('offer_fulfillments')->insert([
                    'product_offer_id' => $offerId,
                    'strategy' => $strategy,
                    'position' => 0,
                    'created_at' => $now, 'updated_at' => $now,
                ]);

                /* ── السمات ──────────────────────────────────────────────── */
                /*
                 * الإدراج الدفعيّ يوجب تطابق الأعمدة في كلّ صفوف الدفعة. القالب
                 * يثبّت المجموعة كاملةً ثمّ يَغلِب كلّ صفٍّ ما يخصّه وحده (اتّحاد
                 * المصفوفات في PHP يُبقي مفاتيح الطرف الأيسر). بناء الصفوف
                 * بمفاتيح مختلفة كان يُخرج `VALUES lists must all be the same length`.
                 */
                $row = [
                    'attribute_definition_id' => null,
                    'product_group_id' => null,
                    'product_variant_id' => null,
                    'text_value' => null,
                    'long_text_value' => null,
                    'int_value' => null,
                    'decimal_value' => null,
                    'bool_value' => null,
                    'date_value' => null,
                    'datetime_value' => null,
                    'json_value' => null,
                    'unit' => null,
                    'currency' => null,
                    'locale' => null,
                    'source' => 'VERIFIED_SYSTEM',
                    'confidence' => null,
                    'extractor' => null,
                    'model_version' => null,
                    'verified_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $values = [];

                if ($p->resource_type !== null && $p->resource_type !== '') {
                    $values[] = [
                        'attribute_definition_id' => $attributeIds['resource_type'],
                        'product_group_id' => $groupId,
                        'text_value' => $p->resource_type,
                    ] + $row;
                }

                // صيغة الملفّ تُعرِّف الصيغة، فمحلّها الصيغة لا المجموعة (§٤٢).
                if ($p->format !== null && $p->format !== '') {
                    $values[] = [
                        'attribute_definition_id' => $attributeIds['file_format'],
                        'product_variant_id' => $variantId,
                        'text_value' => $p->format,
                    ] + $row;
                }

                /* `unset` غيابٌ لا «لا». §١٥٨: المجهول لا يُكتب قيمةً. */
                if ($p->editable === 'yes' || $p->editable === 'no') {
                    $values[] = [
                        'attribute_definition_id' => $attributeIds['editable'],
                        'product_group_id' => $groupId,
                        'bool_value' => $p->editable === 'yes',
                    ] + $row;
                }

                $facts = DB::table('product_facts')->where('product_id', $p->id)->whereIn('key', $factKeys)->get();
                foreach ($facts as $fact) {
                    if ($fact->int_value === null) {
                        continue; // لا قيمة = لا صفّ
                    }
                    $values[] = [
                        'attribute_definition_id' => $attributeIds[$fact->key],
                        'product_group_id' => $groupId,
                        'int_value' => $fact->int_value,
                        'unit' => $factUnits[$fact->key],
                    ] + $row;
                }

                if ($values !== []) {
                    DB::table('attribute_values')->insert($values);
                }
            });
        });
    }

    public function down(): void
    {
        /* المجموعات تتتالى على الصيغ والعروض والتسليم والسمات. */
        DB::table('product_groups')->whereNotNull('legacy_product_id')->delete();
        DB::table('attribute_definitions')->whereIn('key', array_column($this->definitions(), 'key'))->delete();
        DB::table('tax_classes')->where('code', 'SA_VAT_STANDARD')->delete();
    }
};
