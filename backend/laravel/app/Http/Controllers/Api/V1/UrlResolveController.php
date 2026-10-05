<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\UrlPlatform\UrlRegistry;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/v1/url/resolve?path= — كيف يُحلّ مسارٌ، بخطواته.
 *
 * للعموم لأنّ المتجر يستشيره قبل ٤٠٤: يعيد الكيان أو التحويل أو ٤١٠ أو
 * ٤٠٤ مع اقتراحات. لا يسجّل إصابةً — التسجيل من مسار `not-found` وحده.
 */
final class UrlResolveController extends Controller
{
    public function __construct(private readonly UrlRegistry $registry) {}

    public function resolve(Request $request): JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:2048']);

        $resolution = $this->registry->resolve($validated['path']);

        return response()->json(['data' => $resolution], $resolution['httpStatus'] === 400 ? 400 : 200);
    }
}
