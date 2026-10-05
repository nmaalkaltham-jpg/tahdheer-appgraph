<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminNote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * ملاحظات المشغّلين — والفصل بين «يرى» و«يكتب».
 *
 * القيمة كلّها في هذا الفصل: دور «قراءة فقط» يرى الطلب فيجب أن يرى ما كُتب
 * عنه، ولا يكتب — لأنّ ملاحظته ستظهر لغيره باسمه، وذلك فعلٌ لا قراءة.
 */
class AdminNotesTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function url(string $type = 'order', int $id = 7): string
    {
        return "/api/v1/admin/notes?entityType={$type}&entityId={$id}";
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'entityType' => 'order',
            'entityId' => 7,
            'body' => 'العميل طلب الاسترداد هاتفيًّا قبل التنزيل.',
        ], $overrides);
    }

    /* ───── الفصل بين القراءة والكتابة ───── */

    public function test_read_only_sees_notes_but_cannot_write_one(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($owner)->postJson('/api/v1/admin/notes', $this->payload())->assertStatus(201);

        $reader = $this->makeAdmin(AdminRole::ReadOnly);

        $this->actingAsAdmin($reader)->getJson($this->url())
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.canWrite', false);

        $this->actingAsAdmin($reader)->postJson('/api/v1/admin/notes', $this->payload())
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'notes.write');
    }

    /**
     * القراءة تتبع الكيان لا الملاحظة.
     *
     * «مدير المنتجات» يملك `notes.write` من عدمه لا يهمّ هنا: هو لا يملك
     * `orders.read`، فلا يرى طلبًا — ولا ما كُتب عنه.
     */
    public function test_reading_notes_needs_the_permission_of_the_entity_they_hang_on(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager))
            ->getJson($this->url('order', 7))
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'orders.read');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::SearchManager, 'search@localhost'))
            ->getJson($this->url('customer', 3))
            ->assertStatus(403)
            ->assertJsonPath('requiredPermission', 'customers.read');
    }

    public function test_an_unknown_entity_type_is_refused_before_anything_else(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->getJson($this->url('product', 1))
            ->assertStatus(422);
    }

    /* ───── الكتابة والنسبة ───── */

    public function test_a_note_records_its_author_and_survives_the_account_being_deleted(): void
    {
        $author = $this->makeAdmin(AdminRole::OrderManager);
        $this->actingAsAdmin($author)->postJson('/api/v1/admin/notes', $this->payload())->assertStatus(201);

        $author->delete();

        $note = AdminNote::query()->firstOrFail();
        // المفتاح الأجنبي صار NULL، والبريد المنسوخ باقٍ — فالأثر لم ينكسر.
        $this->assertNull($note->fresh()->admin_user_id);
        $this->assertSame('order-manager@localhost', $note->author_email);
    }

    public function test_only_the_author_may_edit_a_note(): void
    {
        $author = $this->makeAdmin(AdminRole::OrderManager);
        $id = $this->actingAsAdmin($author)
            ->postJson('/api/v1/admin/notes', $this->payload())
            ->json('data.id');

        $other = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($other)
            ->patchJson("/api/v1/admin/notes/{$id}", ['body' => 'إعادة صياغة من غير الكاتب.'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'NOT_AUTHOR');

        $this->actingAsAdmin($author)
            ->patchJson("/api/v1/admin/notes/{$id}", ['body' => 'صياغة الكاتب نفسه المحدَّثة.'])
            ->assertOk()
            ->assertJsonPath('data.edited', true);
    }

    /** المالك يحذف قول غيره — والمشغّل الآخر لا. */
    public function test_the_owner_may_delete_any_note_but_a_peer_may_not(): void
    {
        $author = $this->makeAdmin(AdminRole::OrderManager);
        $id = $this->actingAsAdmin($author)
            ->postJson('/api/v1/admin/notes', $this->payload())
            ->json('data.id');

        $peer = $this->makeAdmin(AdminRole::Administrator);
        $this->actingAsAdmin($peer)->deleteJson("/api/v1/admin/notes/{$id}")->assertStatus(403);

        $owner = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($owner)->deleteJson("/api/v1/admin/notes/{$id}")->assertOk();

        $this->assertDatabaseCount('admin_notes', 0);
    }

    public function test_the_server_says_who_may_edit_each_row_so_the_ui_never_guesses(): void
    {
        $author = $this->makeAdmin(AdminRole::OrderManager);
        $this->actingAsAdmin($author)->postJson('/api/v1/admin/notes', $this->payload())->assertStatus(201);

        $peer = $this->makeAdmin(AdminRole::Administrator);
        $this->actingAsAdmin($peer)->getJson($this->url())
            ->assertOk()
            ->assertJsonPath('data.0.canEdit', false)
            ->assertJsonPath('data.0.canDelete', false)
            ->assertJsonPath('meta.canWrite', true);
    }

    public function test_notes_are_scoped_to_their_entity(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($owner)->postJson('/api/v1/admin/notes', $this->payload(['entityId' => 7]));
        $this->actingAsAdmin($owner)->postJson('/api/v1/admin/notes', $this->payload(['entityId' => 8]));
        $this->actingAsAdmin($owner)->postJson('/api/v1/admin/notes', $this->payload(['entityType' => 'customer', 'entityId' => 7]));

        $this->actingAsAdmin($owner)->getJson($this->url('order', 7))->assertJsonCount(1, 'data');
        $this->actingAsAdmin($owner)->getJson($this->url('customer', 7))->assertJsonCount(1, 'data');
    }
}
