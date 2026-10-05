<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * سجلّ استخراج محتوى الأصول — الجدول الذي كان يعمل في التطوير بلا هجرة.
 *
 * ─── الفخّ الذي عالجه هذا الملفّ ───
 *
 * في قاعدة التطوير جدولٌ اسمه `asset_extractions` فيه ستّةٌ وعشرون صفًّا
 * (أربعةٌ وعشرون `capability_gap/ocr_unavailable` واثنان `plain_text/ok`)
 * خلّفتها شيفرةٌ حُذفت، ولا هجرةَ تُنشئه — مسجَّلٌ في
 * `scripts/known-schema-residue.json:45` ومحذَّرٌ منه في
 * `app/Domain/Insights/Aggregators/OcrInsight.php:15`.
 *
 * فالهجرة تواجه حالتين لا واحدة:
 *   · نشرٌ نظيف — لا جدول: يُنشأ كاملًا.
 *   · قاعدة تطوير — الجدول قائمٌ بصفوفه: يُتبنّى **إضافةً فقط**.
 *
 * ولذلك لا `Schema::create` عارية: كانت ستفشل في التطوير بـ«relation already
 * exists» فتُوقف كلّ هجرةٍ بعدها. ولا `dropIfExists` ثمّ إنشاء: كانت ستمحو
 * ستّةً وعشرين صفًّا ليست لنا (القاعدة ٢٠٤: إضافيّ، بلا فقد بيانات).
 *
 * ─── ولماذا هذا الشكل بعينه ───
 *
 * · الفريد `(asset_sha256, extractor, extractor_version)` = تجزئة المحتوى:
 *   البايتات نفسها لا تُستخرَج مرّتين مهما تكرّر الأمر، والقيد هو ما يلتقط
 *   السباق لا فحصٌ قبل الإدراج (قاعدة ٣).
 * · `extractor_version` = إعادةُ معالجةٍ محكومة: تحسينُ مستخرِجٍ يرفع نسخته
 *   فتُنتَج صفوفٌ جديدة بجوار القديمة، ولا يُدهَس ما قِيس بنسخةٍ سابقة.
 * · `status` + `error_code` = تعافٍ من الفشل: الفاشل يُعاد، والمعلَن عجزًا
 *   (`capability_gap`) لا يُعاد إلّا برفع النسخة.
 * · `asset_id` يُصفَّر عند حذف الأصل لا يُحذف الصفّ: قياسُ «ما استُخرج»
 *   يبقى صادقًا بعد حذف الأصل، والبصمة تبقى مفتاح المحتوى.
 *
 * ولا قيد `CHECK` على `status`: صفوف الشيفرة المحذوفة تحمل `ocr_unavailable`
 * وهي ليست من مفردات `ExtractionStatus`، فقيدٌ اليوم يفشل على بياناتٍ قائمة.
 * المفردات تُفرَض في النطاق (`ExtractionStatus`) — وهو تنازلٌ مكتوب لا سهو.
 */
return new class extends Migration
{
    private const TABLE = 'asset_extractions';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table) {
                $table->id();
                /* يُصفَّر عند حذف الأصل: القياس يبقى، والبصمة تبقى مفتاح المحتوى. */
                $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
                $table->string('asset_sha256', 64)->index();
                $table->string('extractor', 40);
                $table->string('extractor_version', 20)->default('1');
                $table->string('status', 24)->default('pending')->index();
                $table->text('text')->nullable();
                /* `null` = «لم يُقَس» لا صفر: صفرٌ مكانه يُقرأ «لا نصّ فيه». */
                $table->integer('units')->nullable();
                $table->json('meta')->nullable();
                $table->string('error_code', 40)->nullable();
                $table->integer('duration_ms')->nullable();
                $table->bigInteger('bytes_read')->nullable();
                /* بلا منطقة زمنيّة كبقيّة المستودع (قاعدة ٣٠). */
                $table->timestamps();

                $table->unique(['asset_sha256', 'extractor', 'extractor_version'], 'asset_extractions_content_unique');
            });

            return;
        }

        /*
         * تبنٍّ لا إنشاء: كلّ عمودٍ ناقصٍ يُضاف قابلًا للإفراغ، ولا عمودٍ
         * قائمٍ يُعدَّل أو يُحذف — الصفوف الستّة والعشرون تبقى مقروءة.
         */
        $columns = Schema::getColumnListing(self::TABLE);

        Schema::table(self::TABLE, function (Blueprint $table) use ($columns) {
            if (! in_array('asset_id', $columns, true)) {
                $table->foreignId('asset_id')->nullable()->constrained('assets')->nullOnDelete();
            }
            if (! in_array('asset_sha256', $columns, true)) {
                $table->string('asset_sha256', 64)->nullable()->index();
            }
            if (! in_array('extractor', $columns, true)) {
                $table->string('extractor', 40)->nullable();
            }
            if (! in_array('extractor_version', $columns, true)) {
                $table->string('extractor_version', 20)->default('1');
            }
            if (! in_array('status', $columns, true)) {
                $table->string('status', 24)->default('pending')->index();
            }
            if (! in_array('text', $columns, true)) {
                $table->text('text')->nullable();
            }
            if (! in_array('units', $columns, true)) {
                $table->integer('units')->nullable();
            }
            if (! in_array('meta', $columns, true)) {
                $table->json('meta')->nullable();
            }
            if (! in_array('error_code', $columns, true)) {
                $table->string('error_code', 40)->nullable();
            }
            if (! in_array('duration_ms', $columns, true)) {
                $table->integer('duration_ms')->nullable();
            }
            if (! in_array('bytes_read', $columns, true)) {
                $table->bigInteger('bytes_read')->nullable();
            }
            if (! in_array('created_at', $columns, true)) {
                $table->timestamps();
            }
        });

        /*
         * الفريد آخرًا وبالاسم: لو غاب على جدولٍ متبنًّى فإضافته قد تفشل على
         * تَكرارٍ قائم — وذلك فشلٌ **مطلوب** يُرى، لا يُبتلع: جدولٌ فيه
         * بصمتان متطابقتان لنفس المستخرِج يعني أنّ التجزئة لم تكن تعمل.
         */
        $indexes = array_column(Schema::getIndexes(self::TABLE), 'name');

        if (! in_array('asset_extractions_content_unique', $indexes, true)) {
            Schema::table(self::TABLE, function (Blueprint $table) {
                $table->unique(['asset_sha256', 'extractor', 'extractor_version'], 'asset_extractions_content_unique');
            });
        }
    }

    /**
     * لا تراجع.
     *
     * `up()` قد يكون **تبنّى** جدولًا لم يُنشئه، ولا سبيل في `down()` إلى
     * تمييز «أنا صنعته» من «وجدته»، فـ`dropIfExists` هنا تعني احتمال محو
     * صفوفٍ سابقةٍ لهذه الهجرة. والمحتوى المستخرَج مشتقٌّ كلّه من الأصول
     * ويُعاد بناؤه بأمرٍ واحد، فكلفة بقاء جدولٍ بعد التراجع أدنى بكثير من
     * كلفة حذفٍ خاطئ. التراجع — إن لزم — قرارٌ يدويّ مكتوب.
     */
    /**
     * التراجع يُسقط الجدول — ويُقال ثمنُه بدل أن يُسكت عنه.
     *
     * ─── المعضلة الحقيقيّة ───
     *
     * `up()` مشروطةٌ بـ`! Schema::hasTable`: في قاعدة التطوير كان الجدول
     * موجودًا سلفًا (راسبُ شيفرةٍ حُذفت، ستّةٌ وعشرون صفًّا)، فتبنّته الهجرة
     * ولم تُنشئه. فالتراجع لا يملك معرفةَ مَن أنشأ الجدول، وأيُّ اختيارٍ
     * يخسر شيئًا: الإسقاط يمحو صفوفًا لم تُنشئها هذه الهجرة، والامتناع
     * يترك جدولًا لا هجرةَ تصفه فينكسر `migrate:rollback` ثمّ `migrate`.
     *
     * ─── ولماذا الإسقاط ───
     *
     * أُسقِط: هذا ما تعِد به هجرةُ إنشاء، وما يفترضه `migrate:rollback`،
     * وبدونه يبقى العمودُ يتيمًا يُفشل إعادة التشغيل. والصفوف التي تُفقد في
     * الحالة الشاذّة الوحيدة هي صفوفُ الراسب نفسه — من شيفرةٍ محذوفة،
     * ومُتجاوَزةٌ أصلًا بإصداراتٍ أحدث لنفس الأصول.
     *
     * وكانت هذه الدالّة `//` فارغةً بلا كلمة: صمتٌ يُقرأ سهوًا لا قرارًا.
     */
    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
