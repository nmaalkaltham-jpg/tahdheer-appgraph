<?php

namespace App\Domain\Payments;

use RuntimeException;

/** رفضٌ حاسم من المزوّد (٤xx أو حالة فشلٍ صريحة): المال لم يخرج. */
final class ProviderRefundRejected extends RuntimeException {}
