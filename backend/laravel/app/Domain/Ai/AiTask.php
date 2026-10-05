<?php

declare(strict_types=1);

namespace App\Domain\Ai;

/**
 * قدرات البوّابة — قائمةٌ مغلقة.
 *
 * ─── لماذا تعدادٌ لا سلسلةٌ حرّة ───
 *
 * اسم المهمّة يختار الموجّه والمخطَّط والمهلة. وسلسلةٌ حرّة تعني مهمّةً
 * مكتوبةً بخطأٍ مطبعيّ تمرّ فتُنفَّذ بموجّهٍ افتراضيّ لا يناسبها، أو تُرَدّ
 * بخطأٍ غامضٍ في الإنتاج. التعداد يجعل الخطأ خطأ ترجمة.
 *
 * ─── ولماذا `structured` جزءٌ من التعريف ───
 *
 * مهمّةٌ تُغذّي بياناتٍ يجب أن تردّ JSON يُتحقَّق منه. ومهمّةٌ تُعرض على إنسان
 * قد تردّ نصًّا. الفرق خاصّيّةُ المهمّة لا قرارَ كلّ مُستدعٍ، وإلّا كتب أحدهم
 * نصًّا حرًّا في عمودٍ مُهيكَل.
 */
enum AiTask: string
{
    case Generate = 'generate';
    case Extract = 'extract';
    case Classify = 'classify';
    case Summarize = 'summarize';
    case Rewrite = 'rewrite';
    case Translate = 'translate';
    case Tag = 'tag';
    case Suggest = 'suggest';
    case Rerank = 'rerank';
    case AnswerWithContext = 'answer_with_context';

    /** هل يجب أن يكون الخرج JSON مُتحقَّقًا منه؟ */
    public function isStructured(): bool
    {
        return match ($this) {
            self::Extract, self::Classify, self::Tag, self::Rerank => true,
            self::Generate, self::Summarize, self::Rewrite, self::Translate,
            self::Suggest, self::AnswerWithContext => false,
        };
    }

    /**
     * هل يصلح للتنفيذ المتزامن أمام مستعمل؟
     *
     * التوليد الطويل والترجمة والإجابة بسياق تتجاوز ميزانيّة الشاشة على معالجٍ
     * بلا مسرّع، فمكانها الطابور. والتصنيف والوسم قصيران.
     */
    public function isInteractive(): bool
    {
        return match ($this) {
            self::Classify, self::Tag, self::Rerank, self::Extract => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Generate => 'توليد نصّ',
            self::Extract => 'استخراج حقول',
            self::Classify => 'تصنيف',
            self::Summarize => 'تلخيص',
            self::Rewrite => 'إعادة صياغة',
            self::Translate => 'ترجمة',
            self::Tag => 'وسم',
            self::Suggest => 'اقتراح',
            self::Rerank => 'إعادة ترتيب',
            self::AnswerWithContext => 'إجابة بسياق',
        };
    }
}
