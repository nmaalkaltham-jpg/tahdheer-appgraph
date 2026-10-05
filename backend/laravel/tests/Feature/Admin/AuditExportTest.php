<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * ترشيح سجلّ التدقيق وتصديره.
 *
 * السجلّ يُقرأ حين يُسأل سؤالٌ خارجيّ — مراجعة أو نزاع أو تحقيق — ويُسلَّم
 * الجواب لمن لا حساب له في اللوحة. وبلا تصدير كان يُنسخ من الشاشة يدويًّا،
 * فيُقتطع ويُعاد ترتيبه ويفقد قيمته دليلًا.
 */
class AuditExportTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function entry(array $overrides = []): void
    {
        DB::table('admin_audit_log')->insert($overrides + [
            'admin_user_id' => null,
            'actor_email' => 'ops@localhost',
            'action' => 'shipping.zone.updated',
            'entity_type' => 'shipping_zone',
            'entity_id' => 1,
            'before' => null,
            'after' => null,
            'reason' => 'تصحيح سعر',
            'ip' => '127.0.0.1',
            'created_at' => now(),
        ]);
    }

    public function test_the_export_requires_audit_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::ProductManager));
        $this->get('/api/v1/admin/audit/export')->assertStatus(403);
    }

    public function test_the_export_streams_csv_with_a_bom(): void
    {
        $this->entry();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $response = $this->get('/api/v1/admin/audit/export')->assertOk();
        $body = $response->streamedContent();

        // بلا BOM يقرأ Excel العربيّة رموزًا — والتقرير يُفتح بـExcel.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body);
        $this->assertStringContainsString('shipping.zone.updated', $body);
        $this->assertStringContainsString('تصحيح سعر', $body);
    }

    public function test_the_export_honours_the_action_filter(): void
    {
        $this->entry(['action' => 'shipping.zone.updated']);
        $this->entry(['action' => 'orders.refund']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $body = $this->get('/api/v1/admin/audit/export?action=orders.refund')->assertOk()->streamedContent();

        $this->assertStringContainsString('orders.refund', $body);
        // مرشّحٌ يُتجاهَل في التصدير يُسلّم دليلًا يخالف ما رآه المشغّل.
        $this->assertStringNotContainsString('shipping.zone.updated', $body);
    }

    public function test_the_index_filters_by_actor(): void
    {
        $this->entry(['actor_email' => 'a@localhost']);
        $this->entry(['actor_email' => 'b@localhost']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->getJson('/api/v1/admin/audit?actorEmail=b@localhost')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.actorEmail', 'b@localhost');
    }

    public function test_the_index_free_text_searches_action_and_reason(): void
    {
        $this->entry(['action' => 'orders.refund', 'reason' => 'شكوى زبون']);
        $this->entry(['action' => 'shipping.zone.updated', 'reason' => 'تصحيح سعر']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // «من غيّر السعر ولماذا؟» لا يُجاب بترشيحٍ على حقلٍ واحد.
        $this->getJson('/api/v1/admin/audit?q=سعر')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.action', 'shipping.zone.updated');
    }

    public function test_free_text_wildcards_are_escaped(): void
    {
        $this->entry(['action' => 'orders.refund', 'reason' => 'شكوى']);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // `%` حرفيًّا لا محرف بدل: بدون التهريب يُطابق كلّ شيء.
        $this->getJson('/api/v1/admin/audit?q=%')->assertOk()->assertJsonPath('meta.total', 0);
    }
}
