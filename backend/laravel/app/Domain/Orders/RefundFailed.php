<?php

namespace App\Domain\Orders;

use App\Domain\Orders\Models\Refund;
use RuntimeException;

/** الاسترداد رُفض عند المزوّد؛ صفُّه `FAILED` وحجزُه أُفرج عنه. */
final class RefundFailed extends RuntimeException
{
    public function __construct(string $message, public readonly ?Refund $refund = null)
    {
        parent::__construct($message);
    }
}
