<?php

declare(strict_types=1);

namespace Tests\Feature\Assets;

use App\Domain\Assets\Exceptions\SourceNotConfigured;
use App\Domain\Assets\Exceptions\SourceUnavailable;
use App\Domain\Assets\Sources\GoogleDriveSource;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * تعيين استجابات Drive إلى حالات §١٦.
 *
 * **ما لا تغطّيه هذه الاختبارات:** لا اتّصال بحسابٍ حقيقيّ. الشبكة مزيَّفة،
 * والمُختبَر هو القرار المبنيّ على الاستجابة — وهو موضع الخطأ الذي يُسلّم
 * للزبون ملفًّا خاطئًا أو يطلب من المشغّل إصلاح ما ليس معطوبًا.
 * التحقّق مقابل Drive حيّ يبقى معلّقًا حتّى يُضبط اعتماد (§١٥٣).
 */
class GoogleDriveSourceTest extends TestCase
{
    private function drive(): GoogleDriveSource
    {
        config(['services.google_drive.access_token' => 'token-for-test']);

        return $this->app->make(GoogleDriveSource::class);
    }

    private function fake(int $status, array $body = []): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response($body, $status)]);
    }

    public function test_a_healthy_file_maps_version_to_revision(): void
    {
        $this->fake(200, [
            'id' => 'FILE_ID_1', 'name' => 'تحضير.pdf', 'mimeType' => 'application/pdf',
            'size' => '204800', 'md5Checksum' => 'abc123',
            'modifiedTime' => '2026-09-01T10:00:00.000Z', 'version' => 12,
            'parents' => ['FOLDER_1'],
        ]);

        $meta = $this->drive()->inspect('FILE_ID_1');

        $this->assertTrue($meta->exists);
        $this->assertSame('12', $meta->revision); // عدّاد Drive نصًّا، لا رقمًا يفيض
        $this->assertSame(204800, $meta->sizeBytes); // Drive يرسله نصًّا
        $this->assertSame('abc123', $meta->checksum);
        $this->assertSame('FOLDER_1', $meta->parentId);
    }

    public function test_the_permanent_identity_is_the_file_id_not_the_name(): void
    {
        $this->fake(200, ['id' => 'FILE_ID_1', 'name' => 'اسم جديد تمامًا.pdf', 'version' => 3]);

        $meta = $this->drive()->inspect('FILE_ID_1');

        // §١٣: إعادة التسمية لا تُفقد الملفّ — الاسم عرضٌ والمعرّف هويّة.
        $this->assertSame('FILE_ID_1', $meta->externalId);
        $this->assertTrue($meta->exists);
    }

    public function test_a_trashed_file_counts_as_missing(): void
    {
        $this->fake(200, ['id' => 'FILE_ID_1', 'name' => 'x.pdf', 'version' => 4, 'trashed' => true]);

        /*
         * Drive يُرجع المهملات ٢٠٠ كاملةً. عدُّها موجودةً يُبقي المنتج معروضًا
         * للبيع حتّى تُفرَّغ السلّة، فيختفي الملفّ فجأةً بعد الشراء.
         */
        $this->assertFalse($this->drive()->inspect('FILE_ID_1')->exists);
    }

    public function test_a_404_is_a_missing_file(): void
    {
        $this->fake(404);

        $this->assertFalse($this->drive()->inspect('GONE')->exists);
    }

    public function test_a_deleted_file_is_not_reported_as_a_permission_problem(): void
    {
        $this->fake(404);

        /*
         * §١٦ — «حُذف» و«سُحب الإذن» حالتان بعلاجين: الأولى تُوجب استبدال
         * مصدر، والثانية إصلاح اعتماد. وكانت ٤٠٤ تُعيد `false` هنا، فيكتب
         * الحاكم ACCESS_REVOKED عن ملفٍّ محذوف — ويُطلب من المشغّل إصلاح
         * اعتمادٍ سليم. سؤالُ الإذن يُجاب بالإذن وحده؛ والوجود يُجيب عنه
         * `inspect`.
         */
        $this->assertTrue($this->drive()->verifyAccess('GONE'));
    }

    public function test_a_403_means_access_revoked_not_failure(): void
    {
        $this->fake(403);

        // الفارق الحاسم: false تُقرأ ACCESS_REVOKED، والاستثناء يُقرأ SYNC_FAILED
        // فتطلب اللوحة إعادة محاولةٍ لن تنجح أبدًا.
        $this->assertFalse($this->drive()->verifyAccess('FILE_ID_1'));
    }

    public function test_a_quota_403_is_transient_not_a_revoked_permission(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([
            'error' => ['errors' => [['reason' => 'userRateLimitExceeded']], 'code' => 403],
        ], 403)]);

        /*
         * §١٦ — ٤٠٣ معنيان بعلاجين متعاكسين. الحصّة تُعاد محاولتُها، والإذن
         * يُصلَح. وطيُّهما معًا كان يكتب `ACCESS_REVOKED` الدائمة عن ضغطٍ
         * مؤقّت — فتُمنع تسليماتٌ مدفوعة حتّى يتدخّل مشغّل.
         */
        $this->expectException(SourceUnavailable::class);
        $this->drive()->inspect('FILE_ID_1');
    }

    public function test_a_plain_403_is_still_a_revoked_permission(): void
    {
        Http::fake(['www.googleapis.com/*' => Http::response([
            'error' => ['errors' => [['reason' => 'insufficientFilePermissions']], 'code' => 403],
        ], 403)]);

        $this->assertFalse($this->drive()->verifyAccess('FILE_ID_1'));
    }

    public function test_a_401_is_our_configuration_not_their_file(): void
    {
        $this->fake(401);

        $this->expectException(SourceNotConfigured::class);
        $this->drive()->inspect('FILE_ID_1');
    }

    public function test_rate_limiting_is_retryable_not_terminal(): void
    {
        $this->fake(429);

        $this->expectException(SourceUnavailable::class);
        $this->drive()->inspect('FILE_ID_1');
    }

    public function test_a_native_google_document_is_refused_for_sale(): void
    {
        $this->fake(200, [
            'id' => 'DOC_1', 'name' => 'ورقة عمل',
            'mimeType' => 'application/vnd.google-apps.document', 'version' => 2,
        ]);

        $problems = $this->drive()->validate('DOC_1');

        // لا حجم لها ولا md5، وتنزيلها يحتاج تصديرًا — بيعها يسلّم صفر بايت.
        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('تصدير', $problems[0]);
    }

    public function test_an_unconfigured_connector_reports_itself_and_calls_nothing(): void
    {
        config(['services.google_drive.access_token' => '']);
        Http::fake();

        $health = $this->app->make(GoogleDriveSource::class)->health();

        $this->assertFalse($health->configured);
        $this->assertFalse($health->ok());
        Http::assertNothingSent(); // لا يُجرَّب اتّصالٌ بلا اعتماد
    }

    public function test_credentials_never_appear_in_an_error_message(): void
    {
        config(['services.google_drive.access_token' => 'SUPER_SECRET_TOKEN_VALUE']);
        $this->fake(500);

        try {
            $this->drive()->inspect('FILE_ID_1');
            $this->fail('كان يجب أن يفشل.');
        } catch (SourceUnavailable $e) {
            // §١٥: لا يتسرّب اعتمادٌ إلى رسالةٍ تُعرض أو تُسجَّل.
            $this->assertStringNotContainsString('SUPER_SECRET_TOKEN_VALUE', $e->getMessage());
        }
    }
}
