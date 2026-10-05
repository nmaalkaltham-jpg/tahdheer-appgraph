<?php

declare(strict_types=1);

namespace Tests\Feature\Demand;

use App\Domain\Admin\AdminRole;
use App\Domain\Demand\MaterialRequest;
use Database\Seeders\CurriculumSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Admin\AdminFixtures;
use Tests\TestCase;

/**
 * الطلب الفائت — «اطلب ملفًا غير متوفّر».
 *
 * الغرض من الميزة كلّها هو العدّ: ما أكثر ما يُطلب ولا نملكه. لذلك تحرس هذه
 * الاختبارات ما يفسد العدّ — النصّ الحرّ، والتكرار، والطلب الفارغ — قبل أن
 * تحرس الشكل.
 */
class MaterialRequestTest extends TestCase
{
    use AdminFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(CurriculumSeeder::class);
    }

    /** @param array<string, mixed> $payload */
    private function send(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/material-requests', $payload);
    }

    /* ───── التسجيل ───── */

    public function test_a_request_is_recorded_against_real_taxonomy_entities(): void
    {
        $this->send([
            'stage' => 'ابتدائي',
            'grade' => 'رابع-ابتدائي',
            'subject' => 'الرياضيات',
            'semester' => 'الفصل-الأول',
            'kind' => 'أوراق-عمل',
            'note' => 'أحتاج أوراق عمل للوحدة الثالثة',
        ])->assertStatus(201)->assertJsonPath('recorded', true);

        $row = MaterialRequest::query()->firstOrFail();

        // كيانات لا نصّ: التقرير يُجمَّع على المعرّف.
        $this->assertNotNull($row->grade_id);
        $this->assertNotNull($row->subject_id);
        $this->assertSame('أوراق عمل', $row->kind, 'شرطة المسار لم تُردّ إلى مفردة الكتالوج');
        $this->assertSame(1, $row->hits);
    }

    /**
     * سلسلة لا يعرفها الكتالوج حقلٌ فارغ لا «قيمة أخرى».
     *
     * تخزين النصّ كما جاء يجعل «سادس» و«الصف السادس» و«٦» ثلاثة صفوف في تقرير
     * وُجد ليَعُدّ صفًّا واحدًا.
     */
    public function test_an_unknown_taxonomy_string_is_dropped_not_stored_as_free_text(): void
    {
        $this->send(['grade' => 'سادس', 'note' => 'أي شيء لسادس'])->assertStatus(201);

        $row = MaterialRequest::query()->firstOrFail();

        $this->assertNull($row->grade_id);
        $this->assertSame('أي شيء لسادس', $row->note);
    }

    /** طلبان لنفس التقاطع صفٌّ واحد بعدّاد — وإلّا قِيس إرسالُ النموذج لا الطلب. */
    public function test_the_same_intersection_increments_one_row(): void
    {
        $payload = ['grade' => 'رابع-ابتدائي', 'subject' => 'الرياضيات'];

        $this->send($payload)->assertStatus(201)->assertJsonPath('alsoRequested', 0);
        $this->send($payload + ['contact' => 'teacher@example.com'])
            ->assertStatus(200)
            ->assertJsonPath('alsoRequested', 1);

        $this->assertSame(1, MaterialRequest::query()->count());
        $this->assertSame(2, MaterialRequest::query()->value('hits'));
    }

    public function test_an_empty_request_is_refused_with_a_reason(): void
    {
        $this->send(['note' => '   '])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'EMPTY_REQUEST');

        $this->assertSame(0, MaterialRequest::query()->count());
    }

    /* ───── وسيلة التواصل ───── */

    public function test_a_contact_is_typed_and_a_meaningless_one_is_not_stored(): void
    {
        $this->send(['subject' => 'الرياضيات', 'contact' => 'teacher@example.com'])
            ->assertStatus(201)
            ->assertJsonPath('contactStored', true);
        $this->assertSame('email', MaterialRequest::query()->value('contact_kind'));

        // ليست بريدًا ولا رقمًا ⇒ وسيلة لا تصل، فلا تُخزَّن ولا يُوعَد بها.
        $this->send(['subject' => 'العلوم', 'contact' => 'اتصل بي'])
            ->assertStatus(201)
            ->assertJsonPath('contactStored', false);
    }

    /* ───── اللوحة ───── */

    public function test_the_inbox_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/demand')->assertStatus(401);
    }

    /** الترتيب بالشدّة لا بالأحدث: السؤال «ما أكثر ما يُطلب» لا «ما آخر رسالة». */
    public function test_the_inbox_ranks_by_how_many_asked_and_masks_the_contact(): void
    {
        $this->send(['subject' => 'العلوم', 'contact' => 'one@example.com'])->assertStatus(201);
        foreach (range(1, 3) as $ignored) {
            $this->send(['subject' => 'الرياضيات'])->assertSuccessful();
        }

        $admin = $this->makeAdmin(AdminRole::Owner);
        $body = $this->actingAsAdmin($admin)->getJson('/api/v1/admin/demand')->assertOk()->json();

        $this->assertSame('الرياضيات', $body['data'][0]['subject']);
        $this->assertSame(3, $body['data'][0]['hits']);
        $this->assertSame(2, $body['counts']['new']);

        // البريد مُقنَّع في القائمة — قائمةٌ تعرض مئة عنوان كامل تسريبٌ بالتصميم.
        $science = collect($body['data'])->firstWhere('subject', 'العلوم');
        $this->assertSame('on…@example.com', $science['contact']);
    }

    public function test_a_decision_is_recorded_on_the_request(): void
    {
        $this->send(['subject' => 'الرياضيات'])->assertStatus(201);
        $id = MaterialRequest::query()->value('id');

        $admin = $this->makeAdmin(AdminRole::Owner);
        $this->actingAsAdmin($admin)
            ->patchJson("/api/v1/admin/demand/{$id}", ['status' => 'planned', 'decisionNote' => 'ضمن دفعة الفصل الثاني'])
            ->assertOk()
            ->assertJsonPath('data.status', 'planned');

        $this->assertSame('ضمن دفعة الفصل الثاني', MaterialRequest::query()->value('decision_note'));
    }
}
