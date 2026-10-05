<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\Extractors\CapabilityGapExtractor;
use App\Domain\ContentIntelligence\Extractors\OcrImageExtractor;
use App\Domain\ContentIntelligence\Extractors\OfficeOpenXmlExtractor;
use App\Domain\ContentIntelligence\Extractors\PdfTextExtractor;
use App\Domain\ContentIntelligence\Extractors\PlainTextExtractor;
use App\Domain\ContentIntelligence\Extractors\SvgNativeTextExtractor;

/**
 * سجلّ المحوّلات — الحلّ بالنوع، لا `switch` على الصيغة (البند ٥).
 *
 * القائمة **مكتوبة صراحةً** لا مُكتشَفة من القرص، كسجلّ المنفّذين في
 * `app/Providers/AppServiceProvider.php:52`: ترتيبُ التسجيل يقرّر من يتولّى
 * نوعًا لو ادّعاه اثنان، والاكتشاف التلقائيّ يجعل ذلك يتبدّل بترتيب أسماء
 * الملفّات على القرص — فيتغيّر سلوك الاستخراج بإعادة تسمية ملفّ.
 *
 * وآخرُ القائمة `CapabilityGapExtractor` ويدّعي كلّ شيء: فلا نوعَ يخرج بلا
 * جوابٍ مكتوب. «لم أجد محوّلًا» ليست حالةً يصمت عنها النظام.
 */
final class ExtractorRegistry
{
    /** @var list<ContentExtractor> */
    private array $extractors;

    /**
     * @param  list<ContentExtractor>|null  $extractors  للاختبار وحده؛ الإنتاج يأخذ القائمة المكتوبة.
     */
    public function __construct(?array $extractors = null)
    {
        $this->extractors = $extractors ?? [
            new SvgNativeTextExtractor,
            new PlainTextExtractor,
            /* النصُّ فوق البيانات الوصفيّة؛ وبلا pdftotext يُعيد قراءتها وحدها. */
            new PdfTextExtractor,
            new OfficeOpenXmlExtractor,
            /* الصور النقطيّة بـOCR؛ وبلا محرّك تُعيد العجز المُسمّى نفسه. */
            new OcrImageExtractor,
            /* آخرًا دائمًا: يدّعي كلّ نوع، فما قبله هو ما يفوز. */
            new CapabilityGapExtractor,
        ];
    }

    /** أوّل محوّلٍ يدّعي النوع. لا يعود `null` أبدًا — آخر السجلّ يدّعي الكلّ. */
    public function for(string $mime): ContentExtractor
    {
        foreach ($this->extractors as $extractor) {
            if ($extractor->supports($mime)) {
                return $extractor;
            }
        }

        /*
         * لا يُبلَغ إلّا بسجلٍّ رُكِّب في اختبارٍ بلا محوّلٍ شامل. صريحٌ
         * لأنّ الصمت هنا يعني أصلًا بلا صفّ — وهو ما بُني هذا النطاق لمنعه.
         */
        return new CapabilityGapExtractor;
    }

    /** @return list<ContentExtractor> */
    public function all(): array
    {
        return $this->extractors;
    }
}
