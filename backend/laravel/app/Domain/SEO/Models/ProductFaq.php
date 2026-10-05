<?php

namespace App\Domain\SEO\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One real FAQ pair for a product. There is no generator: rows are authored.
 * The SAME rows render visibly on the PDP and feed FAQPage — one source
 * (MIP §7.4 «no fake FAQ»).
 */
class ProductFaq extends Model
{
    protected $table = 'product_faqs';

    protected $guarded = [];
}
