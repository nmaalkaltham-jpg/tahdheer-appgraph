<?php

/*
 * منصّة العناوين — ضبطٌ من البيئة لا من الشيفرة.
 *
 * `storefront_revalidate_url` هو مسار `POST /api/revalidate` في المتجر،
 * و`secret` رأس `X-Revalidate-Secret` الذي يقبله. غيابهما يُعلَن في السجلّ
 * ولا يُدّعى إبطال.
 */
return [
    'slug_min_length' => (int) env('URL_SLUG_MIN', 1),
    'slug_max_length' => (int) env('URL_SLUG_MAX', 80),
    'storefront_revalidate_url' => env('STOREFRONT_REVALIDATE_URL', ''),
    'storefront_revalidate_secret' => env('STOREFRONT_REVALIDATE_SECRET', ''),
];
