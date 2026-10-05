<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Integrations\CredentialVault;
use App\Domain\Integrations\Models\IntegrationCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * مفاتيح الربط — والعقد الذي يحرسها.
 *
 * ─── لماذا يُختبر هذا بشدّة ───
 *
 * تسريبُ مفتاحٍ سرّيّ لا يُصدر عطلًا ولا يُرى في شاشة: يبدو كلّ شيء يعمل،
 * ثمّ يُحوَّل مال الزبائن إلى حسابٍ آخر أو يُنشر باسم المتجر. فالاختبارات
 * هنا تُثبّت المنع نفسه لا الميزة: **لا سرّ يخرج**، ولا يُكتب ظاهرًا، ولا
 * يقع في سجلّ التدقيق.
 *
 * ولا مفتاح حقيقيّ في هذا الملفّ: القيم مُختلَقة بالشكل وحده.
 */
class IntegrationCredentialsTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    /** قيمٌ بالشكل لا بالمعنى — لا مفتاح حقيقيّ في مستودع. */
    private const FAKE_SECRET = 'REDACTED_PUBLIC_TEST_CREDENTIAL';

    private const FAKE_PUBLIC = 'pk_test_1111111111111111111111111';

    private function save(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/admin/integrations/credentials', $overrides + [
            'provider' => 'moyasar',
            'environment' => 'test',
            'field' => 'secret_key',
            'value' => self::FAKE_SECRET,
        ]);
    }

    // ── الصلاحيات ────────────────────────────────────────────────────────

    /**
     * للمالك وحده — ولا حتّى لمدير النظام.
     *
     * من يملك مفتاح البوّابة يملك تحويل المال. هذه سلطةُ حسابٍ لا سلطةُ
     * تشغيل، ودمجُها مع إدارة المتجر اليوميّة يجعل كلّ مدير قادرًا عليها.
     */
    public function test_only_the_owner_may_read_integrations(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Administrator));
        $this->getJson('/api/v1/admin/integrations')->assertStatus(403);

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->getJson('/api/v1/admin/integrations')->assertOk();
    }

    public function test_a_non_owner_cannot_write_a_credential(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::OrderManager));
        $this->save()->assertStatus(403);

        $this->assertDatabaseCount('integration_credentials', 0);
    }

    // ── السرّ لا يخرج ────────────────────────────────────────────────────

    /**
     * لا قيمة سرّيّة في أيّ استجابة — لا عند الحفظ ولا بعده.
     *
     * «أرِني المفتاح» طلبٌ معقول ظاهريًّا وخطيرٌ عمليًّا: يجعل كلّ جلسةٍ
     * مسروقة تُخرج كلّ مفاتيح المتجر دفعةً واحدة.
     */
    public function test_a_secret_never_appears_in_any_response(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $created = $this->save()->assertStatus(201);
        $this->assertStringNotContainsString(self::FAKE_SECRET, $created->getContent());
        $created->assertJsonPath('data.value', null);
        $created->assertJsonPath('data.isSecret', true);

        $listed = $this->getJson('/api/v1/admin/integrations')->assertOk();
        $this->assertStringNotContainsString(self::FAKE_SECRET, $listed->getContent());
        $listed->assertJsonPath('policy.secretsReturned', false);
    }

    /** المعاينة تُميّز ولا تُستعمل: بادئةٌ وأربعة محارف. */
    public function test_the_preview_identifies_without_exposing(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $preview = $this->save()->json('data.preview');

        $this->assertStringStartsWith('sk_test_••••', (string) $preview);
        $this->assertStringEndsWith(substr(self::FAKE_SECRET, -4), (string) $preview);
        $this->assertLessThan(mb_strlen(self::FAKE_SECRET), mb_strlen((string) $preview));
    }

    /** السرّ مُعمًّى في العمود — لا نصّ ظاهر في الجدول. */
    public function test_a_secret_is_encrypted_at_rest(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->save()->assertStatus(201);

        $row = DB::table('integration_credentials')->first();

        $this->assertNull($row->value_public);
        $this->assertNotNull($row->value_encrypted);
        $this->assertStringNotContainsString(self::FAKE_SECRET, (string) $row->value_encrypted);

        /* والخزنة وحدها تفكّه. */
        $this->assertSame(
            self::FAKE_SECRET,
            app(CredentialVault::class)->reveal('moyasar', 'test', 'secret_key'),
        );
    }

    /**
     * لا قيمة في سجلّ التدقيق.
     *
     * السجلّ يُقرأ بصلاحيةٍ أوسع من صلاحية المفاتيح، ويُصدَّر، ويُنسخ.
     */
    public function test_the_audit_log_records_the_change_not_the_value(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->save()->assertStatus(201);

        $entries = DB::table('admin_audit_log')
            ->where('action', 'integration.credential.created')
            ->get();

        $this->assertCount(1, $entries);

        foreach ($entries as $entry) {
            $blob = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->assertStringNotContainsString(self::FAKE_SECRET, (string) $blob);
        }
    }

    // ── ما ليس سرًّا يُعرض كاملًا ────────────────────────────────────────

    /**
     * المعرّف والمفتاح العلنيّ ليسا سرًّا.
     *
     * تعميتُهما تمنع المالك من قراءة ما يحقّ له قراءته وتجعل كلّ حقلٍ يبدو
     * خطيرًا — فيتوقّف عن قراءة التحذيرات.
     */
    public function test_a_public_field_is_stored_and_returned_in_full(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->save(['field' => 'publishable_key', 'value' => self::FAKE_PUBLIC])
            ->assertStatus(201)
            ->assertJsonPath('data.isSecret', false)
            ->assertJsonPath('data.value', self::FAKE_PUBLIC)
            ->assertJsonPath('data.preview', null);

        $row = DB::table('integration_credentials')->first();
        $this->assertSame(self::FAKE_PUBLIC, $row->value_public);
        $this->assertNull($row->value_encrypted);
    }

    // ── الأخطاء التي تكلّف مالًا ─────────────────────────────────────────

    /**
     * مفتاح النشر في خانة السرّ — أو العكس.
     *
     * العكس هو الأخطر: `sk_` في خانة النشر يُرسَل إلى متصفّح كلّ زبون.
     * وهو خطأٌ لا يُصدر رسالةً من المزوّد.
     */
    public function test_a_wrong_prefix_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->save(['field' => 'secret_key', 'value' => self::FAKE_PUBLIC])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['value']]);

        $this->assertDatabaseCount('integration_credentials', 0);
    }

    /** مفتاح الإنتاج في بيئة تجربة يُحصّل مالًا حقيقيًّا. */
    public function test_a_live_key_cannot_be_stored_in_the_test_environment(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->save(['environment' => 'test', 'value' => 'REDACTED_PUBLIC_TEST_CREDENTIAL'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['value']]);

        $this->assertDatabaseCount('integration_credentials', 0);
    }

    /** الكتابة الحيّة تحتاج تأكيدًا صريحًا. */
    public function test_writing_a_live_credential_requires_confirmation(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $live = ['environment' => 'live', 'value' => 'REDACTED_PUBLIC_TEST_CREDENTIAL'];

        $this->save($live)->assertStatus(422)->assertJsonStructure(['errors' => ['confirmLive']]);
        $this->assertDatabaseCount('integration_credentials', 0);

        $this->save($live + ['confirmLive' => true])->assertStatus(201);
        $this->assertDatabaseCount('integration_credentials', 1);
    }

    /** حقلٌ لا يعرفه المزوّد يُرفض — المجموعة مغلقة عمدًا. */
    public function test_an_unknown_field_is_refused(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->save(['field' => 'note'])->assertStatus(422);
        $this->assertDatabaseCount('integration_credentials', 0);
    }

    // ── التدوير والسحب ───────────────────────────────────────────────────

    /** التدوير يكتب فوق الحقل نفسه — لا قيمتان فعّالتان لحقلٍ واحد. */
    public function test_rotating_replaces_the_value_and_keeps_one_row(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->save()->assertStatus(201);
        $rotated = $this->save(['value' => 'REDACTED_PUBLIC_TEST_CREDENTIAL'])->assertOk();

        $this->assertDatabaseCount('integration_credentials', 1);
        $this->assertNotNull($rotated->json('data.rotatedAt'));
        $this->assertStringEndsWith('9999', (string) $rotated->json('data.preview'));

        $this->assertSame(1, DB::table('admin_audit_log')
            ->where('action', 'integration.credential.rotated')->count());
    }

    /** السحب يمحو القيمة ويُبقي الصفّ — «من أبطله ومتى؟» سؤالٌ يُسأل بعد حادثة. */
    public function test_revoking_erases_the_value_but_keeps_the_record(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->save()->assertStatus(201);

        $this->deleteJson('/api/v1/admin/integrations/credentials', [
            'provider' => 'moyasar', 'environment' => 'test', 'field' => 'secret_key',
        ])->assertOk();

        $row = IntegrationCredential::query()->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->revoked_at);
        $this->assertNull($row->value_encrypted);
        $this->assertNull($row->value_public);
        /* والبصمة تبقى: «هل كان هذا هو المفتاح المسرَّب؟». */
        $this->assertNotNull($row->fingerprint);

        $this->assertNull(app(CredentialVault::class)->reveal('moyasar', 'test', 'secret_key'));
    }

    public function test_revoking_a_missing_credential_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->deleteJson('/api/v1/admin/integrations/credentials', [
            'provider' => 'tap', 'environment' => 'live', 'field' => 'secret_key',
        ])->assertStatus(404);
    }
}
