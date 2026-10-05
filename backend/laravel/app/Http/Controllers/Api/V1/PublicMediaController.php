<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * وسائط عامّة — أغلفة المنتجات وما يشبهها.
 *
 * لماذا وُجد: لا حجم مثبَّت على حاوية الـAPI، فأي ملف يُكتب على قرصها يختفي
 * مع أوّل نشر. والتخزين الكائني (Garage) داخلي على شبكة Docker ولا يبلغه
 * المتصفّح. فكان المنتج بلا صورة ليس عطل تحميل في الواجهة بل غياب مسار
 * عرضٍ من أصله.
 *
 * السلامة قبل الراحة — بادئة واحدة مسموحة:
 *
 *   الأصول المدفوعة تعيش في الدلو نفسه. مسارٌ يخدم أيّ مفتاح يعني أن تخمين
 *   اسم ملف يساوي تحميلًا مجّانيًّا لمنتج مدفوع. لذلك لا يُخدَم إلا ما تحت
 *   `media/`، ويُرفض كل ما عداه — لا لأن الاسم يبدو خطرًا، بل لأنه ليس في
 *   القائمة المسموحة.
 *
 *   والمسار يُطبَّع قبل الفحص: بادئة صحيحة مع `..` بعدها تخرج من النطاق،
 *   وفحصٌ يسبق التطبيع يُخدَع بها.
 *
 * التخزين المؤقّت طويل عمدًا: اسم الملف يحمل بصمة محتواه (يولّده الرافع)،
 * فتغيّر الصورة يعني اسمًا جديدًا ولا حاجة لإبطال شيء.
 */
final class PublicMediaController extends Controller
{
    /** البادئة الوحيدة القابلة للعرض العام. */
    private const PUBLIC_PREFIX = 'media/';

    /** أنواع نعرفها ونثق بها. أي امتداد آخر يُرفض بدل تخمين نوعه. */
    private const TYPES = [
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
    ];

    public function __invoke(string $path): Response
    {
        $key = $this->safeKey($path);
        if ($key === null) {
            abort(404);
        }

        $extension = strtolower(pathinfo($key, PATHINFO_EXTENSION));
        $mime = self::TYPES[$extension] ?? null;
        if ($mime === null) {
            abort(404);
        }

        $disk = Storage::disk(config('filesystems.default'));
        if (! $disk->exists($key)) {
            abort(404);
        }

        return new StreamedResponse(
            static function () use ($disk, $key): void {
                $stream = $disk->readStream($key);
                if ($stream !== null) {
                    fpassthru($stream);
                    fclose($stream);
                }
            },
            200,
            [
                'Content-Type' => $mime,
                // سنة كاملة: الاسم يحمل بصمة المحتوى فلا يتغيّر مضمونه تحته.
                'Cache-Control' => 'public, max-age=31536000, immutable',
                // SVG يُنفَّذ في المتصفّح إن فُتح مباشرةً — يُقدَّم مرفقًا
                // لا مضمَّنًا، وبسياسة تمنع أي سكربت داخله.
                'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /** مفتاح تخزين آمن داخل البادئة العامّة، أو null. */
    private function safeKey(string $path): ?string
    {
        $decoded = rawurldecode($path);

        // التطبيع أوّلًا: «media/../private/x» تمرّ على فحص البادئة وحدها.
        $segments = [];
        foreach (explode('/', $decoded) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        $relative = implode('/', $segments);

        if ($relative === '') {
            return null;
        }

        /*
         * البادئة تُفرَض هنا ولا تُطلَب من الطالب.
         *
         * لو قُبلت في المسار لَكان الفحص «هل يبدأ بـ media/؟» — وهو تحقّق من
         * نصّ يملكه الطالب. بفرضها نجعل الخروج من النطاق مستحيلًا لا مرفوضًا:
         * كل ما بعد التطبيع يقع تحتها حتمًا. و`..` أُزيلت قبل هذا السطر، فلا
         * تستطيع الصعود فوقها.
         */
        $key = self::PUBLIC_PREFIX.$relative;

        // بايت فارغ أو محرف تحكّم = محاولة تلاعب، لا اسم ملف.
        if (preg_match('/[\x00-\x1F]/', $key) === 1) {
            return null;
        }

        return $key;
    }
}
