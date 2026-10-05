<?php

namespace App\Domain\Reviews;

use Exception;

/**
 * Typed refusal from the Reviews domain. Every reason carries the exact
 * Arabic sentence the customer sees and the HTTP status the API answers with —
 * so the message can never drift between service, controller and test.
 */
class ReviewDenied extends Exception
{
    public const NOT_PURCHASED = 'not_purchased';

    public const ALREADY_REVIEWED = 'already_reviewed';

    public const NOT_APPROVED = 'not_approved';

    public const ALREADY_VOTED = 'already_voted';

    private const MESSAGES = [
        self::NOT_PURCHASED => ['التقييم متاح لمن اشترى المنتج فقط', 403],
        self::ALREADY_REVIEWED => ['لديك تقييم منشور لهذا المنتج بالفعل', 409],
        self::NOT_APPROVED => ['هذا التقييم غير متاح', 404],
        self::ALREADY_VOTED => ['سجّلنا رأيك في هذا التقييم من قبل', 409],
    ];

    public readonly int $status;

    public function __construct(public readonly string $reason)
    {
        [$message, $status] = self::MESSAGES[$reason] ?? ['تعذّر إتمام الطلب', 422];
        $this->status = $status;

        parent::__construct($message);
    }
}
