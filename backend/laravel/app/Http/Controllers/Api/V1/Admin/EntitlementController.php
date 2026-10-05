<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminAuditLogger;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Entitlements\EntitlementService;
use App\Domain\Entitlements\RevocationPolicy;
use App\Http\Controllers\Controller;
use App\Models\Entitlement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Entitlement revocation (MIP §8.4 lists it as a high-risk operation, so a
 * reason is mandatory and every call is audited). The ledger mutation itself
 * belongs to App\Domain\Entitlements\EntitlementService — never reimplemented.
 */
class EntitlementController extends Controller
{
    public function __construct(private readonly AdminAuditLogger $audit) {}

    public function revoke(Request $request, AdminUser $actor, EntitlementService $entitlements, int $id): JsonResponse
    {
        $entitlement = Entitlement::query()->findOrFail($id);

        if ($entitlement->status === 'REVOKED') {
            return response()->json([
                'message' => 'هذه الصلاحية مسحوبة مسبقًا.',
                'code' => 'ALREADY_REVOKED',
            ], 422);
        }

        $data = $request->validate([
            'reason' => 'required|string|max:255',
            'policy' => ['required', Rule::in(array_column(RevocationPolicy::cases(), 'value'))],
        ], AdminValidation::MESSAGES);

        $before = ['status' => $entitlement->status, 'expiresAt' => $entitlement->expires_at?->toIso8601String()];

        $entitlements->revoke($entitlement, $data['reason'], RevocationPolicy::from($data['policy']));
        $fresh = $entitlement->fresh();

        $this->audit->log(
            $actor, 'entitlement.revoked', 'entitlement', $entitlement->id, $before,
            ['status' => $fresh->status, 'expiresAt' => $fresh->expires_at?->toIso8601String(), 'policy' => $data['policy']],
            $data['reason'], $request,
        );

        return response()->json([
            'data' => [
                'id' => $fresh->id,
                'status' => $fresh->status,
                'policy' => $data['policy'],
                'revokedAt' => $fresh->revoked_at?->toIso8601String(),
                'expiresAt' => $fresh->expires_at?->toIso8601String(),
                'revocationReason' => $fresh->revocation_reason,
            ],
        ]);
    }
}
