<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Domain\Search\ProductSearchProjector;
use App\Domain\Search\Providers\MeilisearchProvider;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\TestCase;

/**
 * النصّ المطويّ المخزَّن — عيبان بجذرٍ واحد.
 *
 * (أ) `PgFallbackProvider::score()` كان يطوي عنوان كلّ مرشَّح وجسده ووصفه في
 *     كلّ استعلام، على مجموعةٍ حدّها ألف. والطيّ دالّةٌ خالصة لا يتغيّر
 *     دخلها إلّا بتغيّر المنتج.
 *
 * (ب) مسار PostgreSQL يطوي الطرفين، ومسار Meilisearch كان يفهرس النصّ خامًا
 *     ويطوي الاستعلام وحده — فالمنتج يُوجَد على محرّك ولا يُوجَد على الآخر.
 *
 * وما يحرسه هذا الملفّ ثلاثةٌ لا واحد: أنّ المخزَّن **هو** ما يُطابَق عليه
 * (وإلّا فالإصلاح تزيينٌ لا أثر له)، وأنّ غيابه وبَياتَه يسقطان إلى الطيّ
 * الآنيّ (وإلّا فالترحيل يُخفي منتجات)، وأنّ الترتيب لم يتحرّك بينهما.
 */
class NormalizedSearchTextTest extends TestCase
{
    use RefreshDatabase, SeedsSearchFixtures;

    /** كلمةٌ لا ترد في أيّ نصّ حقيقيّ — فمطابقتها دليلٌ على مصدر المطابقة. */
    private const GHOST = 'زقنبوطيه';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSearchWorld();
    }

    private function projector(): ProductSearchProjector
    {
        return $this->app->make(ProductSearchProjector::class);
    }

    /** @return array{v: int, src: string, sku: string, title: string, body: string, desc: string} */
    private function storedFold(Product $product): array
    {
        $fold = ProductSearchProjector::decodeFold(
            DB::table('products')->where('id', $product->id)->value(ProductSearchProjector::FOLDED_COLUMN)
        );

        $this->assertNotNull($fold, 'العمود المخزَّن غير مقروء — والاختبار بعده بلا معنى.');

        return $fold;
    }

    /** @return list<int> */
    private function searchIds(string $query): array
    {
        return array_map(
            static fn (array $item) => (int) $item['id'],
            $this->getJson('/api/v1/search?q='.rawurlencode($query))->assertOk()->json('results.items')
        );
    }

    public function test_projection_stores_the_folded_text_on_the_product_row(): void
    {
        $product = $this->makeProduct('TD-9001', 'أوراق عمل مادة لغتي', 1500, [
            'short_desc' => 'حزمة أنشطة صفّية',
            'description' => 'ملفّ جاهز للطباعة',
        ]);

        $this->projector()->forProduct($product);

        $fold = $this->storedFold($product);

        // الهمزات والتاء المربوطة مطويّة — وهي عين ما يقارنه المزوّد.
        $this->assertSame('اوراق عمل ماده لغتي', $fold['title']);
        $this->assertSame('حزمه انشطه صفيه', $fold['body']);
        $this->assertSame('ملف جاهز للطباعه', $fold['desc']);
        $this->assertSame('td-9001', $fold['sku']);

        // والنصّ المعروض يبقى كما كتبه الناشر — الطيّ للمطابقة لا للعرض.
        $this->assertSame('أوراق عمل مادة لغتي', $product->fresh()->title);
    }

    public function test_the_stored_fold_is_what_the_provider_matches(): void
    {
        $product = $this->makeProduct('TD-9002', 'ملزمة مراجعة الوحدة الرابعة', 1200);
        $this->projector()->forProduct($product);

        // لا وجود لهذه الكلمة في أيّ حقلٍ خام؛ فإن طابقت فمن العمود وحده.
        $this->assertSame([], $this->searchIds(self::GHOST));

        $fold = $this->storedFold($product);
        $fold['title'] = self::GHOST;
        DB::table('products')->where('id', $product->id)->update([
            ProductSearchProjector::FOLDED_COLUMN => json_encode($fold, JSON_UNESCAPED_UNICODE),
        ]);

        $this->assertSame([$product->id], $this->searchIds(self::GHOST));

        // وبإفراغ العمود يعود المزوّد إلى النصّ الخام فتختفي الكلمة الشبح.
        DB::table('products')->where('id', $product->id)->update([ProductSearchProjector::FOLDED_COLUMN => null]);
        $this->assertSame([], $this->searchIds(self::GHOST));
    }

    public function test_a_row_without_the_column_is_still_findable(): void
    {
        /*
         * عنوانٌ خارج مفردات المنهج عمدًا: عبارةٌ معروفة (مثل «أوراق عمل»)
         * يبتلعها استخراج الكيانات فتصير مرشِّحًا لا نصًّا يُطابَق، فيفشل
         * الاختبار لسببٍ لا علاقة له بما يدّعي حراسته.
         */
        $product = $this->makeProduct('TD-9003', 'زقنبوطية', 1800);

        // الصفّ السابق للترحيل: العمود فارغ. الفارغ ليس «لا مطابقة».
        DB::table('products')->where('id', $product->id)->update([ProductSearchProjector::FOLDED_COLUMN => null]);

        // والتاء المربوطة في العنوان تلتقي الهاء في الاستعلام — أي أنّ الطيّ جرى آنيًّا.
        $this->assertSame([$product->id], $this->searchIds(self::GHOST));
    }

    public function test_a_stale_fold_is_ignored_not_trusted(): void
    {
        $product = $this->makeProduct('TD-9004', 'ملزمة '.self::GHOST, 1000);
        $this->projector()->forProduct($product);

        $this->assertSame([$product->id], $this->searchIds(self::GHOST));

        /*
         * تعديلٌ لا يمرّ بالمُسقِط — وهو الحال الغالب لا الشاذّ:
         * `SyncProductJob::handle` يعود فورًا حين لا يكون المزوّد
         * `meilisearch`، و`config/search.php:16` يجعل الافتراضيّ `pg`.
         * فالعمود يبيت، والثقة به تعني مطابقةً على نصٍّ لم يعد موجودًا.
         */
        DB::table('products')->where('id', $product->id)->update(['title' => 'ملزمة سمرقندية']);

        $this->assertSame([], $this->searchIds(self::GHOST), 'الطيّ البائت صُدّق — وهذا بحثٌ يكذب صامتًا.');
        $this->assertSame([$product->id], $this->searchIds('سمرقنديه'));
    }

    public function test_the_engine_searches_the_same_content_the_live_provider_matches(): void
    {
        /*
         * ما يستحقّ الحراسة ليس وجود حقلٍ في الوثيقة بل أن يبحث فيه المحرّك.
         *
         * كان هنا اختبارٌ يؤكّد أنّ الوثيقة تحمل `search_text` مطويًّا — ويمرّ
         * والحقل خارج `searchableAttributes` تمامًا، أي بينما المحرّك لا يبحث
         * فيه أبدًا. حارسٌ لا يستطيع السقوط على العيب الذي وُضع له.
         *
         * والعيب الحقيقيّ أنّ المزوّدَين يختلفان فيما **يُعثَر عليه**: الوصف
         * يُطابَق على `pg` بأخفّ طبقة، فإن غاب عن قائمة المحرّك صار المنتج
         * موجودًا عند مزوّدٍ ومفقودًا عند الآخر. فهذا ما يُقاس.
         */
        $searchable = (new ReflectionClass(MeilisearchProvider::class))
            ->getMethod('settingsPayload');
        $searchable->setAccessible(true);
        $payload = $searchable->invoke($this->app->make(MeilisearchProvider::class));

        $this->assertContains(
            'description',
            $payload['searchableAttributes'],
            'الوصف يُطابَق في المزوّد الحيّ، فغيابه عن قائمة المحرّك تفاوتٌ بين المزوّدَين.',
        );
        /*
         * الثابت المحروس هو «أخفّ سمةٍ في المحرّك = أخفّ طبقةٍ في المزوّد»،
         * لا اسمُ حقلٍ بعينه. وقد نزلت أخفُّ طبقةٍ من الوصف إلى نصّ الملفّ
         * المستخرَج (`W_FILE_TOKEN` دون `W_DESC_TOKEN`)، فينزل معها الترتيب.
         * والوصف يبقى مثبَّتًا قبله مباشرةً — وهو ما يحفظ الترتيب النسبيّ.
         */
        $attrs = $payload['searchableAttributes'];
        $last = array_key_last($attrs);

        $this->assertSame(
            'extracted_content',
            $attrs[$last],
            'الترتيب وزن: نصّ الملفّ المستخرَج آخرًا ليطابق أخفّ طبقةٍ في المزوّد الحيّ.',
        );
        $this->assertSame(
            'description',
            $attrs[$last - 1],
            'الوصف يسبق نصّ الملفّ مباشرةً — كترتيب طبقتَيهما.',
        );
    }

    public function test_ranking_is_identical_with_and_without_the_stored_fold(): void
    {
        $this->seedCorpus();

        DB::table('products')->update([ProductSearchProjector::FOLDED_COLUMN => null]);
        $before = $this->searchIds('ملزمة مراجعة نهائية');

        foreach (Product::query()->get() as $product) {
            $this->projector()->forProduct($product);
        }

        // شرطُ صدق الاختبار: المسار الثاني يقرأ العمود فعلًا لا يسقط إليه.
        $this->assertSame(0, DB::table('products')->whereNull(ProductSearchProjector::FOLDED_COLUMN)->count());

        $after = $this->searchIds('ملزمة مراجعة نهائية');

        $this->assertNotSame([], $before);
        $this->assertSame($before, $after, 'الترتيب تحرّك — وهذا إصلاح أداءٍ لا إصلاح صلة.');
    }
}
