<?php

namespace App\Domain\Assets\Sources;

use App\Domain\Assets\Contracts\DigitalAssetSource;
use App\Domain\Assets\Exceptions\SourceAccessDenied;
use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\StorageResolver;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * محوّل Google Drive (§١٣ §١٤ §١٥ §١٦).
 *
 * «Drive محوّل مصدر لا نطاق تجاريّ»: لا يعرفه منتجٌ ولا طلبٌ ولا استحقاق.
 * ما يعرفه النظام صفٌّ في `asset_sources` بمعرّف ملفٍّ ومراجعة.
 *
 * الهويّة الدائمة هي `fileId` لا اسم الملفّ (§١٣): إعادة التسمية لا تكسر
 * شيئًا، والاسم يُخزَّن للعرض وحده.
 *
 * وتمييز رموز الحالة هو جوهر §١٦، لا تفصيلًا:
 *   404 → الملفّ ذهب            (يوجب استبدال مصدر)
 *   403 → الإذن ذهب والملفّ باقٍ (يوجب إصلاح اعتماد)
 *   401 → رمزُنا انتهى           (يوجب تجديدًا — لا علاقة له بالملفّ)
 *   5xx/شبكة → تعذّر بلوغ        (يوجب إعادة محاولة وحدها)
 * طيُّها في «فشل» واحد يُنتج لوحةً تطلب إصلاح شيءٍ مجهول.
 *
 * **غير مُتحقَّق منه مقابل حسابٍ حيّ.** البنية والمسارات مكتوبة على Drive v3،
 * ولم يُشغَّل شيءٌ منها على اعتمادٍ حقيقيّ في هذه الجلسة — لا اعتماد مضبوط.
 * الاختبارات تغطّي تعيين الاستجابات إلى الحالات، لا الشبكة.
 */
class GoogleDriveSource implements DigitalAssetSource
{
    private const API = 'https://www.googleapis.com/drive/v3';

    /** ما نطلبه من Drive — لا أكثر. `parents` للكشف عن النقل، لا لرسم شجرة (§١٥). */
    private const FIELDS = 'id,name,mimeType,size,md5Checksum,modifiedTime,version,parents,trashed';

    public function __construct(
        private readonly GoogleDriveCredentials $credentials,
        private readonly StorageResolver $storage,
    ) {}

    public function provider(): string
    {
        return 'GOOGLE_DRIVE';
    }

    public function inspect(string $externalId): SourceMetadata
    {
        $response = $this->get("/files/{$externalId}", ['fields' => self::FIELDS]);

        if ($response === null) {
            return SourceMetadata::missing($externalId);
        }

        $body = $response->json();

        /*
         * المهملات ليست حذفًا ولا وجودًا. Drive يُرجعها ٢٠٠ كاملةً، فلو
         * عوملت موجودةً لبقي المنتج معروضًا حتّى تُفرَّغ السلّة فجأةً.
         */
        if (($body['trashed'] ?? false) === true) {
            return SourceMetadata::missing($externalId);
        }

        return new SourceMetadata(
            externalId: $externalId,
            fileName: $body['name'] ?? null,
            mimeType: $body['mimeType'] ?? null,
            // الحجم نصٌّ في Drive، وغائبٌ تمامًا لمستندات Google الأصليّة.
            sizeBytes: isset($body['size']) ? (int) $body['size'] : null,
            // `version` عدّادٌ يتزايد مع كلّ تعديل — وهو ما تعنيه «المراجعة» هنا.
            revision: isset($body['version']) ? (string) $body['version'] : null,
            // md5 يغيب لمستندات Google؛ غيابه «غير متاح» لا «صفر» (§١٥٨).
            checksum: $body['md5Checksum'] ?? null,
            modifiedAt: isset($body['modifiedTime'])
                ? new DateTimeImmutable($body['modifiedTime'])
                : null,
            parentId: $body['parents'][0] ?? null,
        );
    }

    public function validate(string $externalId): array
    {
        $meta = $this->inspect($externalId);

        if (! $meta->exists) {
            return ['الملفّ غير موجود عند Drive بهذا المعرّف، أو في المهملات.'];
        }

        $problems = [];

        /*
         * مستندات Google الأصليّة ليست ملفّاتٍ قابلةً للتنزيل كما هي: لا حجم
         * لها ولا md5، وتنزيلها يحتاج تصديرًا إلى صيغةٍ يختارها المالك. رفضُها
         * هنا أصدق من تسليم صفرِ بايت للمشتري.
         */
        if (str_starts_with((string) $meta->mimeType, 'application/vnd.google-apps.')) {
            $problems[] = 'مستند Google أصليّ — يحتاج تصديرًا إلى صيغةٍ محدّدة قبل البيع.';
        }

        if ($meta->sizeBytes === 0) {
            $problems[] = 'حجم الملفّ صفر.';
        }

        return $problems;
    }

    /**
     * جلبٌ إلى تخزيننا بمفتاحٍ يحمل المراجعة (§١٤ IMPORT_ONCE).
     *
     * المفتاح `drive/{fileId}/{revision}` لا `drive/{fileId}`: النسخ لا يدهس
     * بعضها، فتبقى الرجعة إلى نسخةٍ سابقة ممكنةً (§١٦) ويبقى من اشترى
     * الأقدم قادرًا على تنزيل ما اشتراه.
     *
     * الحجم يُقاس من البايتات المكتوبة لا من تصريح Drive: مستندات Google لا
     * تُصرّح بحجم أصلًا، فالاعتماد عليه كان سيكتب صفرًا (§١٥٨).
     */
    public function import(string $externalId): array
    {
        $meta = $this->inspect($externalId);

        if (! $meta->exists) {
            throw new SourceUnavailable($this->provider(), 'الملفّ غير موجود عند الاستيراد.');
        }

        $stream = $this->openSecureStream($externalId);
        $stat = fstat($stream);
        $size = $stat['size'] ?? 0;

        if ($size === 0) {
            fclose($stream);
            throw new SourceUnavailable($this->provider(), 'الاستيراد أنتج صفر بايت — لا يُسجَّل أصلٌ فارغ.');
        }

        $key = sprintf('drive/%s/%s', $externalId, $meta->revision ?? 'head');
        $provider = $this->storage->provider();
        $provider->put($key, $stream);

        if (is_resource($stream)) {
            fclose($stream);
        }

        return [
            'key' => $key,
            // التجزئة من البايتات المخزَّنة، لا من md5 الذي يصرّح به Drive.
            'sha256' => $provider->checksum($key),
            'size_bytes' => $size,
        ];
    }

    public function sync(string $externalId, ?string $sinceRevision): ?array
    {
        $revision = $this->getRevision($externalId);

        // «لا تغيير» نتيجةٌ صحيحة لا فشل.
        return $revision !== null && $revision === $sinceRevision ? null : $this->import($externalId);
    }

    public function getMetadata(string $externalId): SourceMetadata
    {
        return $this->inspect($externalId);
    }

    /**
     * هل يقرأ اعتمادُنا هذا الملفّ؟ — سؤالُ إذنٍ لا سؤالُ وجود.
     *
     * ٤٠٣ تُعاد `false`: الحاكم يقرؤها ACCESS_REVOKED، وهي الحالة الصحيحة —
     * الملفّ سليم والإذن ذهب. ولو رُمي استثناءٌ لَصارت SYNC_FAILED، فتطلب
     * اللوحة إعادة محاولةٍ لن تنجح أبدًا.
     *
     * ─── و٤٠٤ تُعاد `true`، وهذا تصحيح ───
     *
     * كانت `get()` تُعيد `null` عند ٤٠٤، فيصير `null !== null` أي `false`، أي
     * **أنّ ملفًّا محذوفًا كان يُبلَّغ عنه «سُحب الإذن»**. والحاكم يفحص الإذن
     * أوّلًا، فلا يبلغ `inspect` أصلًا ولا يكتب SOURCE_MISSING أبدًا من هذا
     * المسار. فيُطلب من المشغّل إصلاح اعتمادٍ سليم بينما الواجب استبدال ملفّ —
     * وهو بعينه الخلط الذي يقول §١٦ إنّه يوجد ليمنعه. ولم يظهر في الاختبارات
     * لأنّ اختبار الحوكمة يستعمل محوّلًا مزيَّفًا يُملي الجواب.
     *
     * فالغياب لا يُجاب عنه هنا: `true` تعني «اعتمادُنا سليم»، ويتولّى
     * `inspect` الإجابة عن الوجود فيُكتب SOURCE_MISSING في موضعه.
     *
     * **المفاضلة، صريحةً:** ملفٌّ لم يُشارَك معنا قطّ يردّ Drive عنه ٤٠٤ لا
     * ٤٠٣، فيُبلَّغ «مفقودًا» وهو موجودٌ عند مالكه. وذاك أهون: الاستبدال فعلٌ
     * يملكه المشغّل، أمّا مطالبتُه بإصلاح اعتمادٍ سليم فطريقٌ مسدود. والحالة
     * الشائعة — ملفٌّ كان يُقرأ ثمّ سُحب إذنه — يردّ عنها Drive ٤٠٣.
     */
    public function verifyAccess(string $externalId): bool
    {
        try {
            $this->get("/files/{$externalId}", ['fields' => 'id']);

            return true;
        } catch (SourceAccessDenied) {
            return false;
        }
    }

    public function getRevision(string $externalId): ?string
    {
        $meta = $this->inspect($externalId);

        return $meta->exists ? $meta->revision : null;
    }

    public function getChecksum(string $externalId): ?string
    {
        return $this->inspect($externalId)->checksum;
    }

    public function openSecureStream(string $externalId)
    {
        $response = Http::withToken($this->credentials->accessToken())
            ->withOptions(['stream' => true])
            ->timeout(120)
            ->get(self::API."/files/{$externalId}", ['alt' => 'media', 'supportsAllDrives' => 'true']);

        $this->assertReadable($response, $externalId);

        /*
         * `body()` كان يجمع الملفّ كلَّه نصًّا ثمّ يُنسخ ثانيةً — ضعفَي حجمه في
         * الذاكرة، فملفُّ ٣٠٠ ميغابايت يقتل العامل. يُفصل مجرى الاستجابة نفسه؛
         * وإن تعذّر، يُنسخ على دفعاتٍ إلى ملفٍّ مؤقّت لا إلى الذاكرة.
         */
        $body = $response->toPsrResponse()->getBody();
        $detached = $body->detach();

        if (is_resource($detached)) {
            return $detached;
        }

        $stream = fopen('php://temp/maxmemory:2097152', 'r+b');
        $body->rewind();

        while (! $body->eof()) {
            fwrite($stream, $body->read(1048576));
        }

        rewind($stream);

        return $stream;
    }

    public function health(): SourceHealth
    {
        if (! $this->credentials->isConfigured()) {
            return new SourceHealth(
                provider: $this->provider(),
                configured: false,
                reachable: false,
                detail: 'لا اعتماد مضبوط. لا عمليّة Drive تُنفَّذ قبل الإعداد.',
            );
        }

        try {
            $response = Http::withToken($this->credentials->accessToken())
                ->timeout(10)->get(self::API.'/about', ['fields' => 'user']);

            return new SourceHealth(
                provider: $this->provider(),
                configured: true,
                reachable: $response->successful(),
                detail: $response->successful() ? null : "Drive ردّ {$response->status()}.",
            );
        } catch (ConnectionException) {
            return new SourceHealth($this->provider(), true, false, 'تعذّر الاتّصال بـDrive.');
        }
    }

    /** @return Response|null  null تعني «غير موجود» حصرًا. */
    private function get(string $path, array $query): ?Response
    {
        try {
            $response = Http::withToken($this->credentials->accessToken())
                ->timeout(30)
                ->get(self::API.$path, $query + ['supportsAllDrives' => 'true']);
        } catch (ConnectionException $e) {
            throw new SourceUnavailable($this->provider(), 'تعذّر الاتّصال بـDrive.');
        }

        return $this->assertReadable($response, $path);
    }

    /**
     * هل هذا ٤٠٣ حصّةٌ لا إذن؟
     *
     * Google يصرّح بالسبب في `error.errors[].reason`. وقراءتُه أصدق من
     * التخمين، وغيابُه يُقرأ «إذنًا» — وهو الأحوط: منعُ تسليمٍ قد يكون
     * ممنوعًا فعلًا أهون من منحِ وصولٍ لا نملكه.
     */
    private function isQuota(Response $response): bool
    {
        $reasons = ['userRateLimitExceeded', 'rateLimitExceeded', 'quotaExceeded', 'dailyLimitExceeded'];

        foreach ((array) $response->json('error.errors', []) as $error) {
            if (in_array($error['reason'] ?? '', $reasons, true)) {
                return true;
            }
        }

        return in_array($response->json('error.status'), ['RESOURCE_EXHAUSTED'], true);
    }

    private function assertReadable(Response $response, string $context): ?Response
    {
        if ($response->successful()) {
            return $response;
        }

        return match ($response->status()) {
            404 => null, // الملفّ ذهب
            401 => throw new SourceNotConfigured($this->provider(), 'رمز الوصول مرفوض أو منتهٍ'),
            /*
             * ٤٠٣ يحمل معنيين عند Google، وعلاجُهما متعاكس:
             *
             *   إذنٌ مسحوب     → دائم. يوجب إصلاح اعتماد، ولا تنفع إعادة المحاولة.
             *   حصّةٌ مستنفدة  → عابر. يوجب الانتظار وحده، والملفّ والإذن سليمان.
             *
             * وطيُّهما في «الإذن مسحوب» — كما كان — يكتب `ACCESS_REVOKED`
             * الدائمة عن ضغطٍ مؤقّت: فتُمنع تسليماتٌ مدفوعة ويُطلب من المشغّل
             * إصلاح اعتمادٍ لا عطل فيه، حتّى يتدخّل يدويًّا. والتمييز بسببِ
             * الخطأ الذي يصرّح به Google نفسه.
             */
            403 => throw $this->isQuota($response)
                ? new SourceUnavailable($this->provider(), 'حصّة Drive مستنفدة مؤقّتًا — أعد المحاولة لاحقًا.')
                : new SourceAccessDenied($this->provider(), 'الإذن مسحوب — الملفّ نفسه قد يكون سليمًا.'),
            429 => throw new SourceUnavailable($this->provider(), 'تجاوزُ حدّ الطلبات — أعد المحاولة لاحقًا.'),
            default => throw new SourceUnavailable($this->provider(), "Drive ردّ {$response->status()}."),
        };
    }
}
