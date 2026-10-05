<?php

namespace App\Domain\Cart\Models;

use Illuminate\Database\Eloquent\Model;

class Coupon extends Model
{
    protected $table = 'coupons';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'first_order_per_account' => 'boolean'];
    }

    /** Human label derived from real values only — never free text. */
    public function label(): string
    {
        if ($this->type === 'percent') {
            return "خصم {$this->value}٪";
        }

        $sar = rtrim(rtrim(number_format($this->value / 100, 2, '.', ''), '0'), '.');

        return "خصم {$sar} ر.س";
    }
}
