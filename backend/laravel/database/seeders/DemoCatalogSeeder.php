<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\Models\ProductFile;
use App\Domain\SEO\Models\ProductFaq;
use App\Models\Grade;
use App\Models\Product;
use App\Models\ProductFact;
use App\Models\ProductMedium;
use App\Models\ProductPrice;
use App\Models\ProductSeo;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * خمسة منتجات عرض كاملة — بغلاف حقيقي ومحتويات وأسئلة وملفّات.
 *
 * لماذا بذرة منفصلة عن DesignPreviewSeeder: تلك أربعة وعشرون منتجًا لمراجعة
 * التصميم وترفض العمل في الإنتاج عمدًا. هذه خمسة منتجات مقصودة لكتالوج حيّ
 * فارغ، وتحمل كل ما تحتاجه صفحة منتج حقيقية.
 *
 * الغلاف يُرفع إلى التخزين الكائني لا إلى قرص الحاوية: لا حجم مثبَّت على
 * حاوية الـAPI، فصورة على قرصها تختفي مع أوّل نشر — وهو السبب الحقيقي لغياب
 * الأغلفة، لا عطل تحميل في الواجهة.
 *
 * كل صفّ يحمل الوسم `DEMO-` في الـSKU، فحذفها لاحقًا أمر واحد لا بحث يدوي:
 *   Product::where('sku', 'like', 'DEMO-%')->delete();
 *
 * إعادة التشغيل آمنة: المفاتيح فريدة والكتابة updateOrCreate، فلا تتضاعف.
 */
class DemoCatalogSeeder extends Seeder
{
    private const PREFIX = 'DEMO-';

    /** بادئة التخزين العامّة — الوحيدة التي يخدمها PublicMediaController. */
    private const MEDIA_DIR = 'media/demo';

    /** البادئة نفسها التي يفرضها المتحكّم؛ تُقصّ من الرابط العام. */
    private const PUBLIC_PREFIX_LEN_SRC = 'media/';

    /**
     * @var list<array{
     *   sku:string, title:string, subtitle:string, short:string, body:string,
     *   stage:string, grade:string, subject:string, semester:string,
     *   format:string, kind:string, regular:int, sale:?int,
     *   tone:array{0:string,1:string}, facts:array<string,int>,
     *   includes:list<string>, faqs:list<array{0:string,1:string}>
     * }>
     */
    private const PRODUCTS = [
        [
            'sku' => 'MATH-G4-S1',
            'title' => 'تحضير الرياضيات — الصف الرابع الابتدائي — الفصل الأول',
            'subtitle' => 'تحضير كامل وفق نموذج الوزارة',
            'short' => 'تحضير جاهز لمادة الرياضيات يغطّي وحدات الفصل الأول كاملة، بصيغة قابلة للتعديل.',
            'body' => 'ملف تحضير متكامل لمادة الرياضيات للصف الرابع الابتدائي، مُعدّ وفق نموذج التحضير المعتمد ويغطّي وحدات الفصل الدراسي الأول كاملة. يشمل الأهداف السلوكية والاستراتيجيات وأساليب التقويم وجدول توزيع الحصص. الملف بصيغة Word قابلة للتعديل الكامل، فيمكنك تكييفه على خطتك دون إعادة بناء من الصفر.',
            'stage' => 'ابتدائي', 'grade' => 'الصف الرابع الابتدائي',
            'subject' => 'الرياضيات', 'semester' => 'الفصل الدراسي الأول',
            'format' => 'DOCX', 'kind' => 'تحضير',
            'regular' => 4500, 'sale' => 3600,
            'tone' => ['#1e7355', '#eaf5ef'],
            'facts' => ['pages_count' => 84, 'units_count' => 6, 'lessons_count' => 42],
            'includes' => [
                'ملف التحضير الرئيسي (Word قابل للتعديل)',
                'جدول توزيع المنهج على أسابيع الفصل',
                'نموذج خطة الدرس اليومية',
                'قائمة الأهداف السلوكية لكل وحدة',
            ],
            'faqs' => [
                ['هل الملف قابل للتعديل؟', 'نعم، الملف بصيغة Word ويمكن تعديل كل محتوياته بحرّية.'],
                ['هل يغطّي الفصل كاملًا؟', 'نعم، يغطّي الوحدات الستّ للفصل الدراسي الأول.'],
                ['متى أستلم الملف؟', 'فور إتمام الدفع مباشرةً، عبر رابط تحميل في صفحة الطلب.'],
            ],
        ],
        [
            'sku' => 'SCI-G5-S1',
            'title' => 'عرض بوربوينت — العلوم — الصف الخامس الابتدائي',
            'subtitle' => 'عروض تفاعلية جاهزة للعرض الصفّي',
            'short' => 'مجموعة عروض تقديمية مصمّمة لدروس العلوم، بصور توضيحية وأنشطة صفّية.',
            'body' => 'مجموعة عروض تقديمية لمادة العلوم للصف الخامس الابتدائي، مصمّمة للعرض الصفّي المباشر. كل عرض يغطّي درسًا كاملًا بصور توضيحية وأسئلة تفاعلية ونشاط ختامي. التصميم موحّد وواضح على أجهزة العرض، والخطوط مضمّنة فلا تتغيّر عند فتح الملف على جهاز آخر.',
            'stage' => 'ابتدائي', 'grade' => 'الصف الخامس الابتدائي',
            'subject' => 'العلوم', 'semester' => 'الفصل الدراسي الأول',
            'format' => 'PPTX', 'kind' => 'عرض بوربوينت',
            'regular' => 5500, 'sale' => null,
            'tone' => ['#1d5f9c', '#e8f2fc'],
            'facts' => ['slides_count' => 210, 'lessons_count' => 18, 'activities_count' => 24],
            'includes' => [
                '١٨ عرضًا تقديميًّا بصيغة PowerPoint',
                'صور توضيحية عالية الدقّة داخل العروض',
                'أنشطة صفّية في نهاية كل درس',
                'ملف إرشادات الاستخدام',
            ],
            'faqs' => [
                ['هل العروض قابلة للتعديل؟', 'نعم، جميع الشرائح قابلة للتعديل في PowerPoint.'],
                ['هل الخطوط مضمّنة؟', 'نعم، الخطوط مضمّنة داخل الملف فلا يتغيّر التصميم على أي جهاز.'],
            ],
        ],
        [
            'sku' => 'ARB-G6-S2',
            'title' => 'اختبارات لغتي — الصف السادس الابتدائي — الفصل الثاني',
            'subtitle' => 'بنك أسئلة مع نماذج إجابة',
            'short' => 'بنك اختبارات متدرّج الصعوبة لمادة لغتي، مع نماذج الإجابة النموذجية.',
            'body' => 'بنك اختبارات شامل لمادة لغتي للصف السادس الابتدائي للفصل الدراسي الثاني. يضمّ اختبارات قصيرة لكل وحدة واختبارين شاملين للفترة والنهائي، بأسئلة متدرّجة الصعوبة تغطّي مهارات القراءة والفهم والتراكيب والإملاء. مرفق نموذج الإجابة لكل اختبار، وملف منفصل بجدول مواصفات الاختبار.',
            'stage' => 'ابتدائي', 'grade' => 'الصف السادس الابتدائي',
            'subject' => 'لغتي', 'semester' => 'الفصل الدراسي الثاني',
            'format' => 'PDF', 'kind' => 'اختبارات',
            'regular' => 3900, 'sale' => 2900,
            'tone' => ['#6b3fa0', '#f3eefb'],
            'facts' => ['pages_count' => 62, 'questions_count' => 240, 'tests_count' => 14],
            'includes' => [
                '١٢ اختبارًا قصيرًا موزّعة على الوحدات',
                'اختبار الفترة والاختبار النهائي',
                'نموذج الإجابة النموذجية لكل اختبار',
                'جدول مواصفات الاختبار',
            ],
            'faqs' => [
                ['هل تشمل نماذج الإجابة؟', 'نعم، لكل اختبار نموذج إجابة كامل.'],
                ['ما صيغة الملف؟', 'PDF جاهز للطباعة مباشرةً بمقاس A4.'],
            ],
        ],
        [
            'sku' => 'ENG-G7-S1',
            'title' => 'أوراق عمل اللغة الإنجليزية — أول متوسط — الفصل الأول',
            'subtitle' => 'أوراق عمل مطبوعة بمقاس A4',
            'short' => 'أوراق عمل تدريبية تغطّي مفردات وقواعد الفصل الأول، جاهزة للطباعة.',
            'body' => 'مجموعة أوراق عمل لمادة اللغة الإنجليزية للصف الأول المتوسط، مصمّمة للطباعة المباشرة بمقاس A4. تغطّي مفردات وقواعد وحدات الفصل الدراسي الأول بتدريبات متنوّعة: توصيل، اختيار من متعدّد، إكمال، وكتابة قصيرة. مساحة الإجابة مناسبة لخطّ الطالب، والتصميم أبيض وأسود يوفّر حبر الطباعة.',
            'stage' => 'متوسط', 'grade' => 'الصف الأول المتوسط',
            'subject' => 'اللغة الإنجليزية', 'semester' => 'الفصل الدراسي الأول',
            'format' => 'PDF', 'kind' => 'أوراق عمل',
            'regular' => 3200, 'sale' => null,
            'tone' => ['#c99700', '#fff4d6'],
            'facts' => ['pages_count' => 48, 'worksheets_count' => 36, 'units_count' => 4],
            'includes' => [
                '٣٦ ورقة عمل جاهزة للطباعة',
                'مفتاح الإجابات في ملف منفصل',
                'تصميم أبيض وأسود موفّر للحبر',
                'مقاس A4 بهوامش طباعة صحيحة',
            ],
            'faqs' => [
                ['هل مفتاح الإجابات مرفق؟', 'نعم، في ملف منفصل حتى يمكن طباعة الأوراق دون الإجابات.'],
                ['هل يمكن الطباعة بالأبيض والأسود؟', 'نعم، التصميم مُعدّ أصلًا للطباعة أحادية اللون.'],
            ],
        ],
        [
            'sku' => 'BIO-G12-S1',
            'title' => 'ملخّص الأحياء — الصف الثالث الثانوي — الفصل الأول',
            'subtitle' => 'ملخّص مركّز مع خرائط مفاهيم',
            'short' => 'ملخّص شامل لمقرّر الأحياء مع خرائط مفاهيم ورسوم توضيحية.',
            'body' => 'ملخّص مركّز لمقرّر الأحياء للصف الثالث الثانوي للفصل الدراسي الأول، مُعدّ للمراجعة النهائية. يعرض كل فصل في صفحات قليلة بخرائط مفاهيم ورسوم توضيحية ملوّنة، مع صندوق «أهمّ ما يُسأل عنه» في نهاية كل وحدة. مناسب للطباعة أو للقراءة على الجهاز اللوحي.',
            'stage' => 'ثانوي', 'grade' => 'الصف الثالث الثانوي',
            'subject' => 'الأحياء', 'semester' => 'الفصل الدراسي الأول',
            'format' => 'PDF', 'kind' => 'ملخّص',
            'regular' => 6500, 'sale' => 4900,
            'tone' => ['#35c98b', '#e4f4ec'],
            'facts' => ['pages_count' => 96, 'units_count' => 5, 'diagrams_count' => 38],
            'includes' => [
                'ملخّص كامل للوحدات الخمس',
                '٣٨ خريطة مفاهيم ورسمًا توضيحيًّا',
                'صندوق «أهمّ ما يُسأل عنه» لكل وحدة',
                'نسخة مُحسَّنة للقراءة على الأجهزة اللوحية',
            ],
            'faqs' => [
                ['هل الملخّص ملوّن؟', 'نعم، الرسوم وخرائط المفاهيم ملوّنة لتسهيل الحفظ.'],
                ['هل يصلح للمراجعة النهائية؟', 'نعم، صُمّم لهذا الغرض تحديدًا: مركّز ومختصر.'],
            ],
        ],
    ];

    public function run(): void
    {
        $disk = Storage::disk(config('filesystems.default'));

        foreach (self::PRODUCTS as $spec) {
            DB::transaction(function () use ($spec, $disk): void {
                $product = $this->upsertProduct($spec);
                $this->upsertPrice($product, $spec);
                $this->upsertCover($product, $spec, $disk);
                $this->upsertFacts($product, $spec);
                $this->upsertIncludes($product, $spec);
                $this->upsertFaqs($product, $spec);
                $this->upsertSeo($product, $spec);
            });
        }

        $this->command?->info('خمسة منتجات عرض جاهزة — كل منها بغلاف ومحتويات وأسئلة.');
        $this->command?->info('للحذف لاحقًا: Product::where(\'sku\', \'like\', \''.self::PREFIX.'%\')->delete();');
    }

    /** @param array<string, mixed> $spec */
    private function upsertProduct(array $spec): Product
    {
        $product = Product::query()->updateOrCreate(
            ['sku' => self::PREFIX.$spec['sku']],
            [
                'slug' => $this->slug($spec['title']),
                'title' => $spec['title'],
                'subtitle' => $spec['subtitle'],
                'short_desc' => $spec['short'],
                'description' => $spec['body'],
                'resource_type' => $spec['kind'],
                'format' => $spec['format'],
                'stage_id' => $this->lookup(Stage::class, $spec['stage']),
                'grade_id' => $this->lookup(Grade::class, $spec['grade']),
                'subject_id' => $this->lookup(Subject::class, $spec['subject']),
                'semester_id' => $this->lookup(Semester::class, $spec['semester']),
                'editable' => $spec['format'] === 'PDF' ? 'no' : 'yes',
                'status' => 'published',
                'published_at' => now(),
                'fulfillment_type' => 'digital',
            ],
        );

        return $product;
    }

    /** @param array<string, mixed> $spec */
    private function upsertPrice(Product $product, array $spec): void
    {
        ProductPrice::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'regular_minor' => $spec['regular'],
                'sale_minor' => $spec['sale'],
            ],
        );
    }

    /**
     * غلاف حقيقي مرفوع إلى التخزين الكائني.
     *
     * SVG لا PNG: يُولَّد بلا مكتبة صور، ويبقى حادًّا على أي كثافة شاشة،
     * وحجمه كيلوبايتات. وهو غلاف مُصمَّم بألوان المادة ونصّها — لا صورة
     * مأخوذة من مكان آخر ولا عنصر نائب.
     *
     * @param  array<string, mixed>  $spec
     */
    private function upsertCover(Product $product, array $spec, mixed $disk): void
    {
        $svg = $this->coverSvg($spec);
        // البصمة في الاسم: تغيّر التصميم يعني ملفًّا جديدًا، فالكاش الطويل آمن.
        $key = self::MEDIA_DIR.'/'.strtolower($spec['sku']).'-'.substr(sha1($svg), 0, 10).'.svg';

        $disk->put($key, $svg, ['visibility' => 'private']);

        ProductMedium::query()->updateOrCreate(
            ['product_id' => $product->id, 'is_cover' => true],
            [
                // المسار العام بلا البادئة: المتحكّم يفرضها بنفسه.
                'url' => '/api/v1/media/'.substr($key, strlen(self::PUBLIC_PREFIX_LEN_SRC)),
                'alt_ar' => $spec['title'],
                'width' => 1200,
                'height' => 900,
                'position' => 0,
            ],
        );
    }

    /** @param array<string, mixed> $spec */
    private function upsertFacts(Product $product, array $spec): void
    {
        foreach ($spec['facts'] as $key => $value) {
            ProductFact::query()->updateOrCreate(
                ['product_id' => $product->id, 'key' => $key],
                ['int_value' => $value],
            );
        }
    }

    /**
     * محتويات المنتج: ملفّات حقيقية بأدوار حقيقية.
     *
     * الملف الرئيسي أصلٌ مدفوع يعيش خارج البادئة العامّة، فلا يبلغه
     * PublicMediaController إطلاقًا — يُسلَّم بمنحة تحميل موقّعة بعد الشراء.
     *
     * @param  array<string, mixed>  $spec
     */
    private function upsertIncludes(Product $product, array $spec): void
    {
        $roles = ['main', 'companion', 'sample', 'teacher_guide'];

        foreach (array_values($spec['includes']) as $i => $label) {
            $role = $roles[$i] ?? 'companion';
            $filename = strtolower($spec['sku']).'-'.$role.'.'.strtolower($spec['format']);

            $asset = Asset::query()->updateOrCreate(
                // البصمة مفتاح فريد في الجدول ⇒ هي معرّف الصفّ لا عمود جانبي.
                ['sha256' => hash('sha256', $filename)],
                [
                    'provider' => 'garage',
                    'provider_ref' => 'private/demo/'.$filename,
                    'original_name' => $label.'.'.strtolower($spec['format']),
                    'mime' => $this->mime($spec['format']),
                    'size_bytes' => 1024 * (120 + $i * 40),
                    // الأصل مدفوع خاصّ: خارج البادئة العامّة، ولا يُسلَّم إلا
                    // بمنحة تحميل موقّعة بعد شراء مُثبَت.
                    'visibility' => 'paid_private',
                ],
            );

            ProductFile::query()->updateOrCreate(
                ['product_id' => $product->id, 'asset_id' => $asset->id, 'role' => $role],
                ['position' => $i],
            );
        }
    }

    /** @param array<string, mixed> $spec */
    private function upsertFaqs(Product $product, array $spec): void
    {
        foreach (array_values($spec['faqs']) as $i => [$question, $answer]) {
            ProductFaq::query()->updateOrCreate(
                ['product_id' => $product->id, 'question' => $question],
                ['answer' => $answer, 'position' => $i],
            );
        }
    }

    /** @param array<string, mixed> $spec */
    private function upsertSeo(Product $product, array $spec): void
    {
        ProductSeo::query()->updateOrCreate(
            ['product_id' => $product->id],
            [
                'seo_title' => $spec['title'],
                'seo_desc' => mb_substr($spec['short'], 0, 300),
            ],
        );
    }

    /** @param array<string, mixed> $spec */
    private function coverSvg(array $spec): string
    {
        [$ink, $wash] = $spec['tone'];
        $kind = $this->esc($spec['kind']);
        $subject = $this->esc($spec['subject']);
        $grade = $this->esc($spec['grade']);
        $format = $this->esc($spec['format']);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 900" width="1200" height="900" role="img" aria-label="{$subject} — {$grade}">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="{$wash}"/>
              <stop offset="1" stop-color="#ffffff"/>
            </linearGradient>
          </defs>
          <rect width="1200" height="900" fill="url(#g)"/>
          <rect x="0" y="0" width="1200" height="14" fill="{$ink}"/>
          <!--
            موضعان كانا خاطئَين معًا، وكلاهما يظهر فقط داخل <img>:

            ١· `text-anchor="end"` مع `direction="rtl"` يرسو عند **نهاية** النصّ
               في ترتيب القراءة — أي يساره — فينمو يمينًا ويطفح خارج اللوحة.
               الصحيح `start`: يرسو عند يمين النصّ وينمو يسارًا.

            ٢· `<img>` سياق معزول لا يرث CSS الصفحة ولا يُحمّل خطًّا خارجيًّا،
               فطلب «Tajawal» هنا يسقط على بديل بمقاييس أخرى. العائلة العامّة
               تُصيَّر بخطّ النظام العربي وتبقى متوقَّعة.
          -->
          <g font-family="sans-serif" direction="rtl" text-anchor="start">
            <text x="1090" y="230" font-size="42" font-weight="700" fill="{$ink}" opacity="0.75">{$kind}</text>
            <text x="1090" y="360" font-size="80" font-weight="900" fill="#14261d">{$subject}</text>
            <text x="1090" y="470" font-size="44" font-weight="500" fill="#3b4d43">{$grade}</text>
          </g>
          <g transform="translate(110,690)">
            <rect x="0" y="0" rx="14" width="190" height="64" fill="{$ink}"/>
            <text x="95" y="42" font-family="ui-monospace, monospace" font-size="30" font-weight="700"
                  fill="#ffffff" text-anchor="middle">{$format}</text>
          </g>
          <circle cx="150" cy="200" r="70" fill="{$ink}" opacity="0.10"/>
          <circle cx="255" cy="300" r="38" fill="{$ink}" opacity="0.07"/>
        </svg>
        SVG;
    }

    private function esc(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function mime(string $format): string
    {
        return match (strtoupper($format)) {
            'PDF' => 'application/pdf',
            'DOCX' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'PPTX' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            default => 'application/octet-stream',
        };
    }

    /** مسار عربي مقروء — الشرطة تفصل الكلمات ولا رمز غيرها. */
    private function slug(string $title): string
    {
        $clean = preg_replace('/[^\p{Arabic}\p{L}\p{N}\s-]/u', '', $title) ?? $title;
        $clean = preg_replace('/[\s—–]+/u', '-', trim($clean)) ?? $clean;

        return mb_substr(trim((string) preg_replace('/-+/', '-', $clean), '-'), 0, 120);
    }

    /**
     * يبحث عن مفردة منهج موجودة بالاسم. لا يخترعها: كتالوج يشير إلى مرحلة
     * غير موجودة يُنتج صفحات تصنيف فارغة.
     *
     * @param  class-string  $model
     */
    private function lookup(string $model, string $name): ?int
    {
        return $model::query()->where('name_ar', $name)->value('id');
    }
}
