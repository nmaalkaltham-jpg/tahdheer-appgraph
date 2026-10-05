<?php

declare(strict_types=1);

namespace App\Domain\ContentIntelligence\Entities;

/**
 * أبعاد الاشتقاق الخمسة — ولماذا خمسة لا ستّة.
 *
 * البند ١٦ يسمّي: المادّة · الصفّ · الفصل · نوع المورد · صيغة الملفّ. وهي
 * الخمسة التي يلتقطها `EntityExtractor` من **نصٍّ موجود** (اسم مستعار من
 * `curriculum_aliases` أو كلمة صيغة).
 *
 * و`stage` مستبعَدة عمدًا: `EntityExtractor.php:104` يشتقّ المرحلة من الصفّ
 * حين تغيب («الصف السادس الابتدائي» يبتلع اسم الصفّ كاملًا فلا يبقى للمرحلة
 * اسمٌ مستعار يُطابَق)، فالمرحلة تصل استنتاجًا لا شاهدًا. ومرشّحٌ لا يشير
 * إلى المقطع الذي أنتجه ليس شاهدًا بل ظنًّا — وهذا الجدول كلّه قائمٌ على
 * أنّ كلّ صفٍّ يقول من أين جاء. البديل المرفوض: تخزينها بعَلَم
 * `inferred=true` — يضيف عمودًا ليقول «لا تثق بهذا الصفّ».
 */
enum EntityDimension: string
{
    case Subject = 'subject';
    case Grade = 'grade';
    case Semester = 'semester';
    case Kind = 'kind';
    case Format = 'format';

    /**
     * هل لهذا البُعد جدولُ تصنيفٍ يُربط بمعرّفه؟
     *
     * `format` وحده لا جدول له — «PDF/DOCX/PPTX» مفرداتٌ ثابتة في
     * `EntityExtractor::FORMAT_WORDS` لا صفوفُ قاعدة. فيُخزَّن نصًّا في
     * `entity_value` و`entity_id` فيه NULL، وقيد CHECK في الهجرة يفرض
     * التلازم فلا يتسلّل معرّفٌ لا جدولَ له.
     */
    public function hasTaxonomyTable(): bool
    {
        return $this !== self::Format;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $d) => $d->value, self::cases());
    }
}
