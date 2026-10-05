<?php

namespace App\Domain\Demand;

use RuntimeException;

/** طلبٌ بلا تصنيف ولا ملاحظة ولا استعلام — لا شيء فيه يُقاس. */
final class EmptyDemandException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('طلب بلا محتوى: اختر مرحلة أو صفًّا أو مادة، أو اكتب ما تبحث عنه.');
    }
}
