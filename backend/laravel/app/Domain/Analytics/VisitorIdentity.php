<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * كعكات القياس — عقدٌ منشور لا تفصيلُ تنفيذ.
 *
 * ─── لماذا يمنحها الخادم لا المتصفّح ولا الوسيط ───
 *
 * سياسة الخصوصيّة تضع قاعدةً حاكمةً بالخطّ العريض: «لا يُنشأ معرّف زائر ولا
 * تُكتب أي كوكي قياس قبل موافقتك الصريحة». وأيّ تصميمٍ يكتب المعرّف في وسيط
 * المتجر — على كلّ زيارة، قبل أن يُسأل أحد — يكسر هذه الجملة حرفيًّا مهما
 * كان الحقل بعدها فارغًا في الجدول.
 *
 * فمنحُ المعرّف حدثٌ واحد: لحظةُ الموافقة. ومن يكتب سجلّ الموافقة هو من
 * يكتب الكعكة، في الطلب نفسه، فلا تُوجد كعكةٌ بلا سطرٍ يبرّرها ولا سطرٌ
 * بلا كعكةٍ تقابله.
 *
 * ─── ولماذا هنا لا في مكانين ───
 *
 * الاسم والمدّة والنطاق مذكورةٌ في «سياسة ملفات تعريف الارتباط» المنشورة:
 * الزائر **سنة**، والجلسة **٣٠ دقيقة**. تكرارُها في المتحكّم وفي العميل
 * يجعل تعديلَ الوعد تعديلًا في ثلاثة ملفّات — ونسيانُ أحدها وعدٌ مكسور.
 *
 * والنطاق والتأمين يُقرآن من ضبط الجلسة القائم (`SESSION_DOMAIN`): المتجر
 * والـAPI على نطاقين فرعيّين في الإنتاج، وكعكةُ السلّة تعبر بينهما بهذا
 * الضبط بالذات. مفتاحٌ ثانٍ يعني انحرافًا صامتًا بين كعكةٍ تعبر وأخرى لا.
 */
final class VisitorIdentity
{
    /** معرّف الزائر — سنة، ولا يقرؤه العميل. */
    public const VISITOR_COOKIE = 'tahdheer_vid';

    /** معرّف الجلسة — ثلاثون دقيقة منزلقة. */
    public const SESSION_COOKIE = 'tahdheer_sid';

    /**
     * قرار الموافقة — «ضرورية» في السياسة، ويقرؤه العميل.
     *
     * لا يحمل معرّفًا: قيمتُه `granted` أو `declined` لا غير. وكشفُه للعميل
     * هو ما يجعل الشريط يعرف أن يصمت بلا نداءِ شبكةٍ على كلّ صفحة.
     */
    public const CONSENT_COOKIE = 'tahdheer_consent';

    public const VISITOR_MINUTES = 60 * 24 * 365;

    public const SESSION_MINUTES = 30;

    public const CONSENT_MINUTES = 60 * 24 * 365;

    public const GRANTED = 'granted';

    public const DECLINED = 'declined';

    /**
     * الشكل المقبول للمعرّف.
     *
     * الكعكة غير مشفّرة (لا وسيط تشفيرٍ على مسارات الـAPI) فقيمتها يملكها
     * العميل. والتحقّق هنا حدٌّ للشكل لا إثباتٌ للملكيّة — ولا يُبنى على هذا
     * المعرّف وصولٌ ولا صلاحيّة أبدًا.
     */
    private const SHAPE = '/^[0-9a-f-]{16,64}$/i';

    /** معرّفٌ جديد — عشوائيّ لا مشتقّ: الاشتقاق من الجهاز بصمة. */
    public function mint(): string
    {
        return (string) Str::uuid();
    }

    /** قيمة كعكةٍ بعد التحقّق من شكلها، أو `null`. */
    public function read(Request $request, string $name): ?string
    {
        $raw = $request->cookie($name);

        if (! is_string($raw)) {
            return null;
        }

        return preg_match(self::SHAPE, $raw) === 1 ? $raw : null;
    }

    /** هل يحمل هذا الطلب قرار موافقةٍ مُعلَنًا؟ */
    public function decided(Request $request): ?bool
    {
        return match ($request->cookie(self::CONSENT_COOKIE)) {
            self::GRANTED => true,
            self::DECLINED => false,
            default => null,
        };
    }

    public function visitor(string $id): Cookie
    {
        return $this->make(self::VISITOR_COOKIE, $id, self::VISITOR_MINUTES, httpOnly: true);
    }

    public function session(string $id): Cookie
    {
        return $this->make(self::SESSION_COOKIE, $id, self::SESSION_MINUTES, httpOnly: true);
    }

    public function decision(bool $granted): Cookie
    {
        return $this->make(
            self::CONSENT_COOKIE,
            $granted ? self::GRANTED : self::DECLINED,
            self::CONSENT_MINUTES,
            httpOnly: false,
        );
    }

    /**
     * محوُ كعكة.
     *
     * «سحب الموافقة يحذف كوكيز القياس فورًا» — والمحو يجب أن يحمل النطاق
     * والمسار نفسيهما، وإلّا كتب المتصفّح كعكةً منتهيةً على نطاقٍ آخر وبقيت
     * الأصليّة حيّة.
     */
    public function forget(string $name): Cookie
    {
        return $this->make($name, '', -2628000, httpOnly: true);
    }

    private function make(string $name, string $value, int $minutes, bool $httpOnly): Cookie
    {
        return cookie()->make(
            name: $name,
            value: $value,
            minutes: $minutes,
            path: '/',
            domain: config('session.domain'),
            secure: config('session.secure'),
            httpOnly: $httpOnly,
            raw: false,
            sameSite: config('session.same_site', 'lax'),
        );
    }
}
