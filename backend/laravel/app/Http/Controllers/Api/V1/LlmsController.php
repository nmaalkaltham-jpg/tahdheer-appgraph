<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\SEO\LlmsCatalog;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * بيانات llms.txt — فهرس الكتالوج الحي للنماذج اللغوية.
 *
 * The storefront renders this as Markdown at /llms.txt with an
 * `X-Robots-Tag: noindex` header.
 */
class LlmsController extends Controller
{
    public function __construct(private readonly LlmsCatalog $catalog) {}

    public function __invoke(): JsonResponse
    {
        return response()->json($this->catalog->build());
    }
}
