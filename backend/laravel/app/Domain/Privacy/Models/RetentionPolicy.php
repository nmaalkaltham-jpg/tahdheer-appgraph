<?php

namespace App\Domain\Privacy\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * سياسة احتفاظ لفئة بيانات (§١٠٦).
 *
 * `legal_basis` نصٌّ مطلوب: «لماذا نحتفظ بهذا؟» سؤالٌ نظاميّ له جوابٌ
 * مكتوب أو لا يُحتفظ.
 */
class RetentionPolicy extends Model
{
    protected $table = 'retention_policies';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
