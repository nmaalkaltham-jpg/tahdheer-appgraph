<?php

namespace App\Domain\Admin\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * ملاحظة مشغّل على كيان. الكاتب منسوخ نصًّا كي يبقى الأثر بعد حذف حسابه.
 */
class AdminNote extends Model
{
    protected $table = 'admin_notes';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['edited_at' => 'datetime'];
    }

    /**
     * الكيانات التي تقبل ملاحظات، وصلاحية **قراءة** كلٍّ منها.
     *
     * القراءة بصلاحية الكيان نفسه: من يرى الطلب يرى ما كُتب عنه. والكتابة
     * بصلاحية مستقلّة (`notes.write`) — لأنّ «قراءة فقط» دورٌ يجب أن يبقى
     * قراءةً فقط، وملاحظةٌ يكتبها تظهر لغيره باسمه فتصير كتابةً في السجلّ.
     *
     * @var array<string, string>
     */
    public const ENTITIES = [
        'order' => 'orders.read',
        'customer' => 'customers.read',
    ];

    public function author(): ?AdminUser
    {
        return $this->admin_user_id === null
            ? null
            : AdminUser::query()->find($this->admin_user_id);
    }

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'entityType' => $this->entity_type,
            'entityId' => (int) $this->entity_id,
            'body' => $this->body,
            'authorEmail' => $this->author_email,
            'authorId' => $this->admin_user_id,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
            'editedAt' => $this->edited_at?->toIso8601String(),
            'edited' => $this->edited_at !== null,
        ];
    }
}
