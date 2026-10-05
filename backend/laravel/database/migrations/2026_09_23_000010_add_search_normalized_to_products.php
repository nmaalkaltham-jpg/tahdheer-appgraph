<?php

use App\Domain\Search\ProductSearchProjector;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ذاكرةُ الطيّ العربيّ على صفّ المنتج.
 *
 * ─── العيب ───
 *
 * `PgFallbackProvider::score()` كان يستدعي `ArabicNormalizer::normalize` على
 * عنوان كلّ مرشَّح وجسده ووصفه، في **كلّ** استعلام، على مجموعةٍ حدّها
 * `CANDIDATE_CAP = 1000`. والطيّ دالّةٌ خالصة: دخلُها لا يتغيّر إلّا بتغيّر
 * المنتج. فعملٌ يتكرّر بعدد الطلبات وهو من حقّ زمن الكتابة.
 *
 * وأثرٌ ثانٍ من الجذر نفسه: `ProductSearchProjector` يُسلّم إلى Meilisearch
 * نصًّا **خامًا**، بينما `QueryPipeline` يطوي الاستعلام — فالمنتج الذي في
 * عنوانه «أوراق» يُوجَد على مسار PostgreSQL بالبحث عن «اوراق» ولا يُوجَد على
 * مسار المحرّك. النصّ المطويّ المخزَّن يُفهرَس أيضًا، فيتّفق المسارَان.
 *
 * ─── لماذا `jsonb` بمفاتيح لا نصٌّ واحد ───
 *
 * الطبقات في المزوّد تفرّق بين العنوان (١٠٠٠…٤٥٠) والجسد (١٠٠) والوصف (٦٠).
 * نصٌّ واحد مسطَّح كان يدمجها فيتغيّر الترتيب — وهذا إصلاح أداءٍ واتّساق لا
 * إصلاح صلة. و`src` بصمةُ النصّ الخام: المخزَّن لا يُصدَّق إلّا إذا طابقها،
 * فالصفّ الذي عُدّل ولم يُعَد إسقاطه يعود إلى الطيّ الآنيّ بلا كذب.
 *
 * ─── التوافق الرجعيّ (§٢٠٤) ───
 *
 * إضافةٌ محضة: عمودٌ واحد يقبل `null`، ولا تعديل عمودٍ قائم ولا حذف. الصفوف
 * التي تبقى `null` تُطوى آنيًّا كما كانت تمامًا — فالفارغ ليس «لا مطابقة».
 * والتعبئة تمرّ بـ`ArabicNormalizer` نفسه عبر `ProductSearchProjector`: طيٌّ
 * ثانٍ مكتوبٌ بـSQL كان سيصير مطبِّعًا ثانيًا يتباعد عن الأوّل صامتًا.
 */
return new class extends Migration
{
    private const CHUNK = 200;

    public function up(): void
    {
        if (! Schema::hasColumn('products', ProductSearchProjector::FOLDED_COLUMN)) {
            Schema::table('products', function (Blueprint $table) {
                $table->jsonb(ProductSearchProjector::FOLDED_COLUMN)->nullable();
            });
        }

        /*
         * التعبئة بمنشئ الاستعلام لا بالنموذج: الهجرة تعيش أطول من أيّ نموذج،
         * ونموذجٌ يتغيّر لاحقًا (سمةٌ محسوبة، علاقةٌ محذوفة) يكسر هجرةً مرّت
         * منذ شهور. والصفوف تُقرأ بحقولها الخمسة وحدها وتُكتب بلا `updated_at`
         * — تحريكه هنا كان سيُطلق مزامنة فهرسٍ لسبعةٍ وعشرين منتجًا لم يتغيّر
         * منها شيء.
         */
        DB::table('products')
            ->select(['id', 'sku', 'title', 'subtitle', 'short_desc', 'description'])
            ->orderBy('id')
            ->chunk(self::CHUNK, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('products')->where('id', $row->id)->update([
                        ProductSearchProjector::FOLDED_COLUMN => json_encode(
                            ProductSearchProjector::foldFields(
                                $row->sku,
                                $row->title,
                                $row->subtitle,
                                $row->short_desc,
                                $row->description,
                            ),
                            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                        ),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', ProductSearchProjector::FOLDED_COLUMN)) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn(ProductSearchProjector::FOLDED_COLUMN);
            });
        }
    }
};
