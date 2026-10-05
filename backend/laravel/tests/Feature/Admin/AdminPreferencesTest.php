<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * تفضيلات المشغّل — لصاحبها وحده، بمخطّطٍ مفروض، وبتعارضٍ صريح.
 */
class AdminPreferencesTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/me/preferences';

    /** @return array<string, mixed> */
    private function dashboard(array $overrides = []): array
    {
        return array_merge([
            'preset' => 'custom',
            'mode' => 'grid',
            'widgets' => [
                'attention' => ['x' => 0, 'y' => 0, 'w' => 8, 'h' => 6, 'order' => 0, 'hidden' => false, 'locked' => true],
                'kpi-2' => ['x' => 8, 'y' => 0, 'w' => 4, 'h' => 5, 'order' => 1, 'type' => 'kpi', 'settings' => ['metric' => 'netSales']],
            ],
        ], $overrides);
    }

    public function test_anonymous_requests_are_rejected(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
        $this->putJson(self::URL, ['dashboard' => $this->dashboard()])->assertStatus(401);
    }

    public function test_a_fresh_account_reads_an_empty_document_as_objects(): void
    {
        $response = $this->actingAsAdmin($this->makeAdmin(AdminRole::ReadOnly))->getJson(self::URL);

        $response->assertOk()->assertJsonPath('meta.version', 0);
        /* كائنٌ فارغ لا قائمةٌ فارغة — الواجهة تقرأ `preferences.dashboard` لا فهرسًا. */
        $this->assertStringContainsString('"data":{}', $response->getContent());
    }

    /** دور «قراءة فقط» يرتّب لوحته — لا صلاحيةَ تحرس وثيقته هو. */
    public function test_read_only_saves_and_reads_back_its_own_layout(): void
    {
        $reader = $this->makeAdmin(AdminRole::ReadOnly);

        $this->actingAsAdmin($reader)
            ->putJson(self::URL, ['version' => 0, 'dashboard' => $this->dashboard()])
            ->assertOk()
            ->assertJsonPath('meta.version', 1)
            ->assertJsonPath('data.dashboard.widgets.attention.locked', true)
            ->assertJsonPath('data.dashboard.widgets.kpi-2.settings.metric', 'netSales');

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('meta.version', 1)
            ->assertJsonPath('data.dashboard.mode', 'grid');
    }

    public function test_unknown_keys_are_dropped_at_every_level(): void
    {
        $body = [
            'dashboard' => $this->dashboard([
                'surprise' => 'x',
                'widgets' => ['queue' => ['x' => 0, 'y' => 0, 'w' => 6, 'h' => 5, 'color' => 'red']],
            ]),
            'secrets' => ['token' => 'nope'],
        ];

        $response = $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))->putJson(self::URL, $body)->assertOk();

        $this->assertArrayNotHasKey('secrets', $response->json('data'));
        $this->assertArrayNotHasKey('surprise', $response->json('data.dashboard'));
        $this->assertArrayNotHasKey('color', $response->json('data.dashboard.widgets.queue'));
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function invalidBodies(): iterable
    {
        yield 'نمطٌ مجهول' => [['dashboard' => ['mode' => 'masonry']], 'dashboard.mode'];
        yield 'عرضٌ يتجاوز الشبكة' => [['dashboard' => ['widgets' => ['queue' => ['w' => 13]]]], 'dashboard.widgets.queue.w'];
        yield 'عرضٌ نصّيّ' => [['dashboard' => ['widgets' => ['queue' => ['w' => 'كبير']]]], 'dashboard.widgets.queue.w'];
        yield 'معرّفٌ بصيغةٍ غريبة' => [['dashboard' => ['widgets' => ['../etc' => ['w' => 2]]]], 'dashboard.widgets'];
        yield 'قفلٌ ليس منطقيًّا' => [['dashboard' => ['widgets' => ['queue' => ['locked' => 'yes']]]], 'dashboard.widgets.queue.locked'];
        yield 'سمةٌ مجهولة' => [['appearance' => ['theme' => 'neon']], 'appearance.theme'];
        yield 'مؤثّرٌ بقيمةٍ مجهولة' => [['appearance' => ['effects' => ['glow' => 'maybe']]], 'appearance.effects.glow'];
        yield 'قسمٌ ليس كائنًا' => [['dashboard' => ['a', 'b']], 'dashboard'];
        yield 'نسخةٌ سالبة' => [['version' => -1, 'dashboard' => []], 'version'];
    }

    #[DataProvider('invalidBodies')]
    public function test_wrong_values_for_known_keys_are_rejected_by_name(array $body, string $field): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson(self::URL, $body)
            ->assertStatus(422)
            ->assertJsonValidationErrors([$field]);
    }

    public function test_the_body_is_size_capped(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson(self::URL, ['appearance' => ['note' => str_repeat('س', 20000)]])
            ->assertStatus(413)
            ->assertJsonPath('code', 'PREFERENCES_TOO_LARGE');
    }

    public function test_the_widget_count_is_capped(): void
    {
        $widgets = [];
        for ($i = 0; $i < 65; $i++) {
            $widgets["kpi-{$i}"] = ['w' => 2];
        }

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner))
            ->putJson(self::URL, ['dashboard' => ['widgets' => $widgets]])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['dashboard.widgets']);
    }

    /** نسخةٌ قديمة تُرفض ٤٠٩ ومعها الحاليّة — لا دهسَ صامتًا بين تبويبين. */
    public function test_a_stale_version_conflicts_and_returns_the_current_document(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->putJson(self::URL, ['version' => 0, 'dashboard' => $this->dashboard()])->assertOk()->assertJsonPath('meta.version', 1);

        $this->putJson(self::URL, ['version' => 0, 'dashboard' => $this->dashboard(['mode' => 'canvas'])])
            ->assertStatus(409)
            ->assertJsonPath('code', 'PREFERENCES_CONFLICT')
            ->assertJsonPath('meta.version', 1)
            ->assertJsonPath('data.dashboard.mode', 'grid');

        $this->putJson(self::URL, ['version' => 1, 'dashboard' => $this->dashboard(['mode' => 'canvas'])])
            ->assertOk()
            ->assertJsonPath('meta.version', 2)
            ->assertJsonPath('data.dashboard.mode', 'canvas');
    }

    /** كلّ قسمٍ يُستبدل وحده: كتابة اللوحة لا تمحو المظهر، و`null` يحذف قسمه. */
    public function test_sections_are_replaced_independently(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->putJson(self::URL, ['appearance' => [
            'theme' => 'light', 'density' => 'compact', 'accent' => 'teal',
            'effects' => ['glow' => 'off', 'shadows' => 'low', 'sparkle' => 'on'],
            'fontScale' => 1.1,
        ]])->assertOk()
            ->assertJsonPath('data.appearance.effects.glow', 'off')
            ->assertJsonMissingPath('data.appearance.effects.sparkle')
            ->assertJsonPath('data.appearance.fontScale', 1.1);
        $this->putJson(self::URL, ['dashboard' => $this->dashboard()])
            ->assertOk()
            ->assertJsonPath('data.appearance.theme', 'light')
            ->assertJsonPath('data.appearance.accent', 'teal')
            ->assertJsonPath('data.dashboard.preset', 'custom');

        $response = $this->putJson(self::URL, ['dashboard' => null])->assertOk();
        $this->assertArrayNotHasKey('dashboard', $response->json('data'));
        $response->assertJsonPath('data.appearance.density', 'compact');
    }

    /** الوثيقة لصاحبها: لا تسرّب في قائمة الحسابات ولا تكتب في حساب غيره. */
    public function test_preferences_belong_to_the_session_owner_only(): void
    {
        $owner = $this->makeAdmin(AdminRole::Owner);
        $other = $this->makeAdmin(AdminRole::Analytics);

        $this->actingAsAdmin($other)->putJson(self::URL, ['dashboard' => $this->dashboard()])->assertOk();

        $this->actingAsAdmin($owner)->getJson(self::URL)->assertOk()->assertJsonPath('meta.version', 0);
        $this->assertNull(AdminUser::query()->find($owner->id)?->preferences);

        $list = $this->getJson('/api/v1/admin/users')->assertOk()->getContent();
        $this->assertStringNotContainsString('preferences', $list);
    }
}
