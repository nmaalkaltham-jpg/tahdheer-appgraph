<?php

namespace App\Domain\Assets;

use App\Domain\Assets\Contracts\DigitalAssetSource;
use App\Domain\Assets\Sources\GoogleDriveSource;
use App\Domain\Assets\Sources\InternalObjectStorageSource;
use InvalidArgumentException;

/**
 * محدِّد محوّل المصدر (§١١).
 *
 * بخلاف `StorageResolver` **لا تراجُع هنا**. التخزين يجوز أن يتراجع إلى
 * القرص المحلّي لأنّ البديل حالةٌ عاملة حقيقيّة. أمّا المصدر فلا بديل له:
 * ملفّ Drive لا يُستبدل بملفٍّ محلّيّ، ومحوّلٌ يتراجع صامتًا يُسلّم للزبون
 * شيئًا غير الذي اشتراه. فالمجهول يرمي، والمُعَدّ سلفًا وحده يعمل.
 */
class AssetSourceResolver
{
    /**
     * المزوّد ← صنف محوّله. مصدرٌ واحد للجوابين.
     *
     * كانت `match` تقرّر من يُحلّ، وقائمةٌ منفصلة تقول من مدعوم. ولا قارئ
     * للقائمة، فلا شيء يكشف افتراقها: من يُضيف `ONEDRIVE` إلى `match` وينسى
     * القائمة يُنتج جوابًا خاطئًا لأوّل مستدعٍ يسأل، بلا اختبارٍ يسقط.
     *
     * وعدم اكتمالها مقصود: المخطّط يعرف تسعة مزوّدين ولنا ثلاثة، والفرق
     * يبقى صاخبًا — يرمي ولا يتراجع. محوّلٌ يتراجع صامتًا يُسلّم للزبون غير
     * الذي اشتراه (§١١).
     *
     * @var array<string, class-string<DigitalAssetSource>>
     */
    private const ADAPTERS = [
        'INTERNAL_OBJECT_STORAGE' => InternalObjectStorageSource::class,
        'DIRECT_UPLOAD' => InternalObjectStorageSource::class,
        'GOOGLE_DRIVE' => GoogleDriveSource::class,
    ];

    /** @var array<string, DigitalAssetSource> */
    private array $cache = [];

    public function for(string $provider): DigitalAssetSource
    {
        $adapter = self::ADAPTERS[$provider] ?? throw new InvalidArgumentException(
            "لا محوّل لمزوّد «{$provider}». أضف محوّلًا صريحًا — لا مصدر افتراضيّ (§١١)."
        );

        return $this->cache[$provider] ??= app($adapter);
    }

    /** @return list<string> المزوّدون الذين لهم محوّل فعليّ اليوم. */
    public function supported(): array
    {
        return array_keys(self::ADAPTERS);
    }
}
