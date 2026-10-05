<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Extractors;

use App\Domain\ContentIntelligence\Contracts\ContentExtractor;
use App\Domain\ContentIntelligence\ExtractionOutcome;

/**
 * ما لا نستطيع قراءته — يُسجَّل باسمه ولا يُسكَت عنه ولا يُدّعى.
 *
 * ─── لماذا صفٌّ أصلًا ───
 *
 * البديل المرفوض: تخطّي الصيغة غير المدعومة بلا كتابة. عندها يصير الجدول
 * جوابًا عن سؤالٍ لم يُسأل: «ما استُخرج» يبدو «ما يوجد»، ولا أحد يعرف أنّ
 * pptx لم يُقرأ أصلًا. والصفّ هنا يقول ثلاثة أشياء: النوع، والقدرة الناقصة
 * باسمها، وما يلزم لسدّها.
 *
 * (Office الحديث — pptx/docx/xlsx — صار يقرؤه `OfficeOpenXmlExtractor`، والصور النقطيّة `OcrImageExtractor`.)
 *
 * ولا يُخزَّن نصٌّ ولا يُعلَن `ok`: صفٌّ ناجحٌ بنصٍّ فارغ يدخل كلّ إحصاءٍ
 * بوصفه مقروءًا، وهو الكذب الذي وُجد هذا الصنف ليمنعه.
 */
final class CapabilityGapExtractor implements ContentExtractor
{
    public const NAME = 'capability_gap';

    /** مؤرّخة لا `1.0.0`: صفوف الشيفرة المحذوفة تحمل الاسم نفسه بنسخة `1.0.0`. */
    public const VERSION = '2026.09.23';

    public function name(): string
    {
        return self::NAME;
    }

    public function version(): string
    {
        return self::VERSION;
    }

    /** يتولّى كلّ ما لم يتولّه غيره — وهو آخر السجلّ دائمًا. */
    public function supports(string $mime): bool
    {
        return true;
    }

    /** لا يقرأ البايتات: القرار من النوع المعلَن وحده. */
    public function extract(string $bytes, string $mime): ExtractionOutcome
    {
        $base = MimeFamily::normalise($mime);

        if (MimeFamily::isLegacyOffice($base)) {
            return ExtractionOutcome::capabilityGap('legacy_office_unsupported', [
                'capability' => 'legacy_office_text',
                'mime' => $base,
                'requires' => ['محوّل يقرأ صيغة OLE القديمة (doc/ppt/xls)'],
                'note_ar' => 'صيغة Office قديمة بتخزين OLE ثنائيّ. لا محوّل لها اليوم.',
            ]);
        }

        return ExtractionOutcome::capabilityGap('unsupported_mime', [
            'capability' => 'format_adapter',
            'mime' => $base,
            'requires' => ['محوّل لهذا النوع في App\\Domain\\ContentIntelligence\\Extractors'],
            'note_ar' => 'لا محوّل يدّعي هذا النوع. الإضافة ملفُّ محوّلٍ وسطرٌ في ExtractorRegistry.',
        ]);
    }
}
