<?php

namespace App\Domain\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * حجزٌ قانونيّ يغلب حقّ المحو (§١٠٦).
 *
 * الامتثال لطلب محوٍ أثناء نزاعٍ جارٍ إتلافُ دليل. الحجز يمنع، والمنع
 * يُعلَّل لصاحب البيانات ولا يُصمَت عنه.
 */
class LegalHold extends Model
{
    protected $table = 'legal_holds';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['placed_at' => 'datetime', 'released_at' => 'datetime'];
    }
}
