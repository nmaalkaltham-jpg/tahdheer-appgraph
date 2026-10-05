<?php

namespace Tests\Unit\Storage;

use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Providers\GarageS3Provider;
use App\Domain\Assets\Providers\LocalSignedProvider;
use App\Domain\Assets\StorageResolver;
use Aws\S3\S3Client;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/** Provider selection is config-driven, and any degradation is announced. */
class StorageResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        StorageResolver::forgetFallbackLog();
    }

    public function test_local_is_the_default_provider(): void
    {
        config(['filesystems.assets.provider' => 'local']);

        $resolver = new StorageResolver;

        $this->assertInstanceOf(LocalSignedProvider::class, $resolver->provider());
        $this->assertSame('local', $resolver->provider()->name());
        $this->assertFalse($resolver->isDegraded());
    }

    /**
     * مع وجود المُشغّل وإعداد كامل، يُختار garage ولا ارتداد.
     *
     * كان هذا الاختبار يثبّت العكس — أن garage يرتدّ دائمًا — لأن حزمة
     * league/flysystem-aws-s3-v3 لم تكن مثبَّتة إطلاقًا في هذا البناء. فحص
     * الجاهزية الجديد كشف ذلك على الإنتاج: تخزين الملفات لم يعمل يومًا، لا
     * لعطل في Garage (تحقّقنا: رفع وتحميل وحذف عبر S3 ينجح) بل لغياب
     * المُشغّل. الحزمة مثبَّتة الآن، فالاختبار يثبّت الحالة الصحيحة.
     */
    public function test_garage_is_selected_when_the_driver_and_config_are_present(): void
    {
        config([
            'filesystems.assets.provider' => 'garage',
            'filesystems.disks.garage.key' => 'set-in-env',
            'filesystems.disks.garage.secret' => 'set-in-env',
            'filesystems.disks.garage.bucket' => 'tahdheer',
            'filesystems.disks.garage.endpoint' => 'http://garage:3900',
        ]);

        $resolver = new StorageResolver;

        $this->assertSame('garage', $resolver->provider()->name());
        $this->assertFalse($resolver->isDegraded(), 'لا ارتداد ما دام المُشغّل والإعداد موجودَين');
    }

    /**
     * الارتداد نفسه ما زال مُختبَرًا — لكن بسببه الحقيقي المتبقّي: إعداد ناقص.
     *
     * هذا هو المسار الذي يحمي الإنتاج يوم يُمحى مفتاح من البيئة: يُخدَم
     * المتجر من القرص المحلّي ويُعلَن التدهور مرّة واحدة، بدل انهيار صامت.
     */
    public function test_incomplete_credentials_fall_back_to_local_and_say_so_once(): void
    {
        config([
            'filesystems.assets.provider' => 'garage',
            'filesystems.disks.garage.key' => null,   // مفتاح مفقود من البيئة
            'filesystems.disks.garage.secret' => null,
            'filesystems.disks.garage.bucket' => 'tahdheer',
            'filesystems.disks.garage.endpoint' => 'http://garage:3900',
        ]);
        Log::spy();

        $resolver = new StorageResolver;

        $this->assertSame('local', $resolver->provider()->name());
        $this->assertTrue($resolver->isDegraded());
        $this->assertSame('garage', $resolver->configuredName());

        // مُحلِّل ثانٍ في العملية نفسها لا يكرّر التحذير.
        (new StorageResolver)->provider();
        Log::shouldHaveReceived('warning')
            ->with('assets.storage.fallback', \Mockery::type('array'))
            ->once();
    }

    /**
     * المزوّد لا يتظاهر: يرفض البناء حين ينقص ما يحتاجه، ولا يسرّب سرًّا في
     * رسالة الخطأ. السبب المُبلَّغ صار «إعداد ناقص» بعد تثبيت المُشغّل.
     */
    public function test_the_garage_provider_refuses_incomplete_configuration(): void
    {
        $this->assertTrue(class_exists(S3Client::class), 'المُشغّل يجب أن يكون مثبَّتًا');

        config([
            'filesystems.disks.garage.key' => null,
            'filesystems.disks.garage.secret' => null,
            'filesystems.disks.garage.bucket' => null,
        ]);

        try {
            new GarageS3Provider('garage');
            $this->fail('GarageS3Provider يجب ألّا يُبنى بإعداد ناقص.');
        } catch (StorageUnavailable $e) {
            $this->assertSame(StorageUnavailable::NOT_CONFIGURED, $e->reason);
            $this->assertStringNotContainsString('secret', strtolower($e->getMessage()));
        }
    }
}
