<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\AdminValidation;
use App\Domain\Admin\Models\AdminNote;
use App\Domain\Admin\Models\AdminUser;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * ملاحظات المشغّلين — ما يعرفه الإنسان عن الحالة ولا يعرفه النظام.
 *
 * ─── صلاحيتان لا واحدة ───
 *
 * القراءة بصلاحية **الكيان** (`orders.read` أو `customers.read`)، والكتابة
 * بصلاحية مستقلّة (`notes.write`). فدور «قراءة فقط» يرى الطلب ويرى ما كُتب
 * عنه، ولا يكتب — وملاحظةٌ يكتبها ستظهر لغيره باسمه، وهذا فعلٌ لا قراءة.
 *
 * ولذلك القراءة لا تُحرَس بوسيط في المسار بل هنا: الصلاحية تختلف باختلاف
 * `entityType` في الطلب نفسه، ووسيطٌ واحد لا يستطيع التعبير عن ذلك.
 *
 * ─── من يعدّل ومن يحذف ───
 *
 * التعديل **للكاتب وحده**. الملاحظة قولٌ منسوب إلى شخص، وتمكينُ غيره من
 * إعادة صياغتها يجعل الاسم أسفلها كذبًا.
 *
 * والحذف للكاتب أو **للمالك**: ملاحظة خاطئة أو مسيئة يجب أن تكون قابلة
 * للإزالة حتى لو غادر كاتبها. والمالك وحده — لا كل من يملك `notes.write` —
 * لأنّ حذف قول زميلك سلطةٌ تُمنح بحساب لا بدور تشغيلي.
 */
class AdminNoteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'entityType' => ['required', Rule::in(array_keys(AdminNote::ENTITIES))],
            'entityId' => 'required|integer|min:1',
        ], AdminValidation::MESSAGES);

        $denied = $this->denyUnlessCanRead($filters['entityType']);
        if ($denied !== null) {
            return $denied;
        }

        $rows = AdminNote::query()
            ->where('entity_type', $filters['entityType'])
            ->where('entity_id', (int) $filters['entityId'])
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        $actor = $this->actor();

        return response()->json([
            'data' => $rows->map(fn (AdminNote $note) => $note->toApiArray() + [
                // القدرة تُحسب في الخادم وتُرسل: الواجهة لا تعيد استنتاج القاعدة.
                'canEdit' => $this->canEdit($note, $actor),
                'canDelete' => $this->canDelete($note, $actor),
            ])->all(),
            'meta' => [
                'canWrite' => $actor?->allows('notes.write') ?? false,
                'currentUserId' => $actor?->id,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'entityType' => ['required', Rule::in(array_keys(AdminNote::ENTITIES))],
            'entityId' => 'required|integer|min:1',
            'body' => 'required|string|min:2|max:4000',
        ], AdminValidation::MESSAGES);

        $denied = $this->denyUnlessCanRead($data['entityType']);
        if ($denied !== null) {
            return $denied;
        }

        $actor = $this->actor();

        $note = AdminNote::query()->create([
            'entity_type' => $data['entityType'],
            'entity_id' => (int) $data['entityId'],
            'admin_user_id' => $actor?->id,
            'author_email' => $actor?->email ?? 'unknown',
            'body' => trim($data['body']),
        ]);

        return response()->json([
            'data' => $note->toApiArray() + ['canEdit' => true, 'canDelete' => true],
        ], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $note = AdminNote::query()->find($id);
        if ($note === null) {
            return $this->missing();
        }

        $actor = $this->actor();
        if (! $this->canEdit($note, $actor)) {
            return response()->json([
                'message' => 'الملاحظة كتبها '.$note->author_email.' — ولا يعدّلها غير كاتبها. أضف ملاحظة جديدة بدل تعديل قول غيرك.',
                'code' => 'NOT_AUTHOR',
            ], 403);
        }

        $data = $request->validate([
            'body' => 'required|string|min:2|max:4000',
        ], AdminValidation::MESSAGES);

        $note->body = trim($data['body']);
        $note->edited_at = now();
        $note->save();

        return response()->json([
            'data' => $note->fresh()->toApiArray() + ['canEdit' => true, 'canDelete' => true],
        ]);
    }

    public function destroy(int $id): JsonResponse
    {
        $note = AdminNote::query()->find($id);
        if ($note === null) {
            return $this->missing();
        }

        $actor = $this->actor();
        if (! $this->canDelete($note, $actor)) {
            return response()->json([
                'message' => 'الملاحظة كتبها '.$note->author_email.'. يحذفها كاتبها أو المالك.',
                'code' => 'NOT_AUTHOR',
            ], 403);
        }

        $note->delete();

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    private function canEdit(AdminNote $note, ?AdminUser $actor): bool
    {
        return $actor !== null
            && $actor->allows('notes.write')
            && $note->admin_user_id === $actor->id;
    }

    private function canDelete(AdminNote $note, ?AdminUser $actor): bool
    {
        if ($actor === null) {
            return false;
        }

        return $actor->role === AdminRole::Owner || $this->canEdit($note, $actor);
    }

    /** ٤٠٣ بنفس شكل AdminAuthorize، فالواجهة تعالج حالة واحدة لا اثنتين. */
    private function denyUnlessCanRead(string $entityType): ?JsonResponse
    {
        $permission = AdminNote::ENTITIES[$entityType];
        $actor = $this->actor();

        if ($actor !== null && $actor->allows($permission)) {
            return null;
        }

        return response()->json([
            'message' => 'صلاحية «'.AdminRole::labelForPermission($permission).'» غير ممنوحة لدورك'
                .($actor === null ? '.' : ' ('.$actor->role->labelAr().').'),
            'code' => 'FORBIDDEN',
            'requiredPermission' => $permission,
        ], 403);
    }

    private function actor(): ?AdminUser
    {
        $user = app()->bound(AdminUser::class) ? app(AdminUser::class) : null;

        return $user instanceof AdminUser ? $user : null;
    }

    private function missing(): JsonResponse
    {
        return response()->json(['message' => 'الملاحظة غير موجودة.', 'code' => 'NOT_FOUND'], 404);
    }
}
