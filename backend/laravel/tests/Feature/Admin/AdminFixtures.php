<?php

namespace Tests\Feature\Admin;

use App\Domain\Admin\AdminRole;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Catalog\ProductGroupAuthoring;
use App\Http\Middleware\AdminAuthenticate;
use App\Models\Grade;
use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Semester;
use App\Models\Stage;
use App\Models\Subject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

trait AdminFixtures
{
    protected const PASSWORD = 'كلمة-مرور-اختبارية-9x';

    protected function makeAdmin(AdminRole $role, ?string $email = null): AdminUser
    {
        return AdminUser::query()->create([
            'email' => $email ?? str_replace('_', '-', $role->value).'@localhost',
            'name' => 'مشغّل الاختبار',
            'password_hash' => Hash::make(self::PASSWORD),
            'role' => $role->value,
        ]);
    }

    /**
     * Real login round-trip: posts credentials, then carries the session
     * cookie and the CSRF token the server issued on every later request.
     */
    protected function actingAsAdmin(AdminUser $admin): static
    {
        /*
         * كان هذا في `setUp()` داخل السِّمة، وصنفٌ يُعرّف `setUp()` خاصًّا
         * به يحجبها بلا إنذار — فستّة أصنافٍ كانت تعمل بلا اعتمادات وتمرّ
         * لسببٍ آخر. موضعُه هنا لا يُحجَب.
         */
        $this->withCredentials();

        unset($this->defaultCookies[config('session.cookie')]);

        $response = $this->postJson('/api/v1/admin/auth/login', [
            'email' => $admin->email,
            'password' => self::PASSWORD,
        ]);
        $response->assertOk();

        $this->withCookie(config('session.cookie'), app('session')->getId());
        $this->withHeader(AdminAuthenticate::CSRF_HEADER, (string) $response->json('csrfToken'));

        return $this;
    }

    /** Education dimensions, created once per test run. */
    protected function curriculum(): array
    {
        $stage = Stage::query()->firstOrCreate(['slug' => 'ibtidai'], ['name_ar' => 'ابتدائي']);
        $grade = Grade::query()->firstOrCreate(['slug' => 'grade-4'], ['name_ar' => 'الرابع', 'stage_id' => $stage->id]);
        $subject = Subject::query()->firstOrCreate(['slug' => 'math'], ['name_ar' => 'رياضيات']);
        $semester = Semester::query()->firstOrCreate(['slug' => 'term-1'], ['name_ar' => 'الفصل الأول']);

        return compact('stage', 'grade', 'subject', 'semester');
    }

    /**
     * منتجٌ ومجموعتُه معًا — كما يُنشئهما مسارُ الكتابة الحقيقيّ.
     *
     * إنشاء الصفّ القديم وحده كان يُنتج منتجًا بلا طبيعة، وهي حالةٌ لا
     * ينتجها `POST /admin/products` بعد اليوم. وتثبيتُ الاختبارات على حالةٍ
     * يستحيل بلوغُها يجعلها تحرس ماضيًا لا حاضرًا: بوّابةُ النشر مثلًا
     * تُقيَّم بالبنود المشتركة وحدها فلا يظهر بند الملفّ أصلًا.
     *
     * `productNature` في `$overrides` يختار الطبيعة؛ و`null` صريحةٌ تعني
     * «منتجٌ قديم بلا مجموعة» — وهي حالةٌ حقيقيّة تستحقّ اختبارًا أيضًا.
     */
    protected function makeProduct(string $sku, array $overrides = []): Product
    {
        $nature = array_key_exists('productNature', $overrides)
            ? $overrides['productNature']
            : 'DIGITAL';
        unset($overrides['productNature']);

        $strategies = $overrides['fulfillmentStrategies'] ?? [];
        unset($overrides['fulfillmentStrategies']);

        $product = Product::query()->create(array_merge([
            'sku' => $sku,
            'slug' => strtolower($sku),
            'title' => 'تحضير رياضيات — '.$sku,
            'status' => 'draft',
            'fulfillment_type' => 'digital',
            'format' => 'pptx',
        ], $overrides));

        if ($nature !== null) {
            app(ProductGroupAuthoring::class)
                ->create($product, $nature, $strategies);
        }

        return $product;
    }

    /** A product that satisfies every readiness item (publishable at 100%). */
    protected function makeReadyProduct(string $sku): Product
    {
        $c = $this->curriculum();

        $product = $this->makeProduct($sku, [
            'stage_id' => $c['stage']->id,
            'grade_id' => $c['grade']->id,
            'subject_id' => $c['subject']->id,
            'semester_id' => $c['semester']->id,
        ]);

        ProductPrice::query()->create([
            'product_id' => $product->id,
            'regular_minor' => 40000,
            'currency' => 'SAR',
        ]);

        $assetId = DB::table('assets')->insertGetId([
            'provider' => 'garage',
            'provider_ref' => 'test/'.$sku,
            'original_name' => $sku.'.pptx',
            'sha256' => hash('sha256', $sku),
            'size_bytes' => 1024,
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'visibility' => 'paid_private',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('product_files')->insert([
            'product_id' => $product->id, 'asset_id' => $assetId, 'role' => 'main',
            'position' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('product_media')->insert([
            'product_id' => $product->id, 'url' => '/media/'.$sku.'.webp',
            'alt_ar' => 'غلاف '.$sku, 'position' => 0, 'is_cover' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $product;
    }
}
