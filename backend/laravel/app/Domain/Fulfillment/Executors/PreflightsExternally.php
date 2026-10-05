<?php

namespace App\Domain\Fulfillment\Executors;

use App\Models\Order;
use App\Models\OrderItem;

/**
 * منفّذٌ يسأل جهةً خارجيّة قبل أن يقرّر.
 *
 * السؤال يقع في `preflight` **خارج** أيّ معاملة، وتُحفظ إجابته ليقرأها
 * `execute` داخلها. النداء الشبكيّ داخل معاملةٍ تحمل أقفال الطلب والمخزون
 * كان يُبقي الأقفال مفتوحةً بطول تعثّر Drive — فيتراكم كلّ خطّافٍ ودفعٍ خلفها.
 */
interface PreflightsExternally
{
    public function preflight(Order $order, OrderItem $item): void;
}
