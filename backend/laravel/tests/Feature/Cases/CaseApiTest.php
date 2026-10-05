<?php

declare(strict_types=1);

namespace Tests\Feature\Cases;

use App\Domain\Admin\AdminRole;
use App\Domain\Cases\CaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/** عقد القضايا كما يراه المشغّل. */
class CaseApiTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    private function open(string $kind = 'FRAUD', string $severity = 'HIGH'): int
    {
        [$case] = app(CaseService::class)->open($kind, 'عنوان', ['SHARED_IP'], $severity);

        return $case->id;
    }

    public function test_reading_requires_order_read(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::SeoManager));
        $this->getJson('/api/v1/admin/cases')->assertStatus(403);
    }

    public function test_acting_requires_operations_manage(): void
    {
        $id = $this->open();
        // القراءة صلاحية، والفعل صلاحيةٌ أخرى: من يقرأ لا ينقل بالضرورة.
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Analytics));
        $this->patchJson("/api/v1/admin/cases/{$id}", ['state' => 'INVESTIGATING'])->assertStatus(403);
    }

    public function test_the_list_sorts_critical_first(): void
    {
        $this->open('INCIDENT', 'WARNING');
        $critical = $this->open('INCIDENT', 'CRITICAL');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // الشاشة تُفتح لتقرّر ما يُعمل الآن؛ الترتيب الزمنيّ يدفن الحرج.
        $this->getJson('/api/v1/admin/cases')
            ->assertOk()
            ->assertJsonPath('data.0.id', $critical)
            ->assertJsonPath('critical', 1)
            ->assertJsonPath('unassigned', 2);
    }

    public function test_one_endpoint_answers_across_kinds(): void
    {
        $this->open('FRAUD');
        $this->open('INCIDENT');
        $this->open('SUPPORT');

        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        // «أرِني كلّ ما هو مفتوح» سؤالٌ لا يُجاب حين تكون المسارات ثلاثة.
        $this->getJson('/api/v1/admin/cases?openOnly=1')->assertOk()->assertJsonPath('open', 3);
        $this->getJson('/api/v1/admin/cases?kind=FRAUD')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_a_refused_transition_returns_what_is_allowed(): void
    {
        $id = $this->open();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->patchJson("/api/v1/admin/cases/{$id}", ['state' => 'CLOSED'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'CASE_TRANSITION_REFUSED')
            /*
             * لا حسم من «جديدة» مباشرةً: إعلانُ نتيجةٍ قبل النظر هو الطريق
             * إلى تجاهل الإنذارات كلّها.
             */
            ->assertJsonPath('allowedNext', ['ASSIGNED', 'INVESTIGATING']);
    }

    public function test_the_detail_carries_the_timeline(): void
    {
        $id = $this->open();
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));

        $this->patchJson("/api/v1/admin/cases/{$id}", ['state' => 'INVESTIGATING'])->assertOk();
        $this->postJson("/api/v1/admin/cases/{$id}/notes", ['note' => 'راجعت'])->assertStatus(201);

        $this->getJson("/api/v1/admin/cases/{$id}")
            ->assertOk()
            ->assertJsonPath('data.timeline.0.action', 'opened')
            ->assertJsonPath('data.timeline.1.toState', 'INVESTIGATING')
            ->assertJsonPath('data.timeline.2.note', 'راجعت');
    }

    public function test_assignment_is_separate_from_state(): void
    {
        $id = $this->open();
        $admin = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($admin);

        $this->patchJson("/api/v1/admin/cases/{$id}/assignee", ['adminUserId' => $admin->id])
            ->assertOk()
            ->assertJsonPath('data.assignedTo', $admin->id)
            // الإسناد استجابة: الحالة تصير «مُسندة» لا تبقى «جديدة».
            ->assertJsonPath('data.state', 'ASSIGNED');
    }

    public function test_a_missing_case_is_404(): void
    {
        $this->actingAsAdmin($this->makeAdmin(AdminRole::Owner));
        $this->getJson('/api/v1/admin/cases/999999')->assertStatus(404);
        $this->patchJson('/api/v1/admin/cases/999999', ['state' => 'INVESTIGATING'])->assertStatus(404);
    }
}
