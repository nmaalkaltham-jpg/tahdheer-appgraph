<?php

namespace App\Domain\Assets\Exceptions;

use RuntimeException;

/**
 * المحوّل موجود ولم يُعَدّ بعد.
 *
 * يفشل مغلقًا عمدًا: محوّلٌ بلا اعتماد لا يُرجع فراغًا ولا «غير موجود»،
 * لأنّ ذلك يُقرأ «حُذف الملفّ» فتُتّخذ قرارات على كذبة.
 */
class SourceNotConfigured extends RuntimeException
{
    public function __construct(public readonly string $provider, string $missing)
    {
        parent::__construct("محوّل «{$provider}» غير مُعَدّ: {$missing}. لا عمليّة تُنفَّذ قبل الإعداد.");
    }
}
