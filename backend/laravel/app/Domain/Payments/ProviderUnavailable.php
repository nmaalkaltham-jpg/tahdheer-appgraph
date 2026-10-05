<?php

namespace App\Domain\Payments;

use RuntimeException;

/** سؤالٌ للمزوّد لم يُجَب (مهلة، انقطاع، ٥xx، جسمٌ لا يُقرأ). لا يُستنتج منه شيء. */
final class ProviderUnavailable extends RuntimeException {}
