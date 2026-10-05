<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * تسجيل الأصول القائمة في سجلّ المصادر.
 *
 * الأصول الستّة والعشرون كلّها `provider = 'local'`: رُفعت مباشرةً وتعيش في
 * تخزيننا. فمصدرها `INTERNAL_OBJECT_STORAGE` ونمطها `IMPORT_ONCE` — نملك
 * النسخة ولا مصدر خارجيّ يتغيّر تحتها، فحالتها `CURRENT` بحقّ لا بافتراض.
 *
 * `external_id` يأخذ `provider_ref` القائم: هو الهويّة الفعليّة داخل تخزيننا.
 * و`content_hash` يأخذ `sha256` المحسوب سلفًا — لا نعيد حسابه ولا نخترعه.
 * المراجعتان تبقيان فارغتين: التخزين الداخليّ لا يُصدر مراجعات، والفراغ
 * يساوي الفراغ فيمرّ قيد `CURRENT` بصدق.
 *
 * إضافيّة: `assets` لا يُمسّ.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('assets')->orderBy('id')->each(function ($asset) use ($now) {
            $provider = match ($asset->provider) {
                'local', 'garage' => 'INTERNAL_OBJECT_STORAGE',
                'external' => 'EXTERNAL_PROVIDER',
                default => throw new RuntimeException(
                    "مزوّد أصلٍ غير معروف على الأصل {$asset->id}: «{$asset->provider}». "
                    .'أضف تعيينًا صريحًا — لا مصدر افتراضيّ.'
                ),
            };

            $externalId = trim((string) $asset->provider_ref);
            if ($externalId === '') {
                throw new RuntimeException("الأصل {$asset->id} بلا `provider_ref` — لا هويّة مصدرٍ تُختلق (§١٣).");
            }

            // قابلة لإعادة التشغيل.
            $exists = DB::table('asset_sources')
                ->where('provider', $provider)->where('external_id', $externalId)->exists();
            if ($exists) {
                return;
            }

            DB::table('asset_sources')->insert([
                'asset_id' => $asset->id,
                'provider' => $provider,
                /*
                 * الخارجيّ يُسجَّل وسيطًا لا مستورَدًا: لا نملك نسخته، وادّعاء
                 * امتلاكها يجعل `asset_id` يكذب. لا صفّ خارجيّ اليوم، والفرع
                 * مكتوبٌ لئلّا يُفترض لاحقًا.
                 */
                'delivery_mode' => $provider === 'EXTERNAL_PROVIDER' ? 'EXTERNAL_ACCESS_BROKER' : 'IMPORT_ONCE',
                'external_id' => $externalId,
                'file_name' => $asset->original_name,
                'mime_type' => $asset->mime,
                'size_bytes' => $asset->size_bytes,
                'content_hash' => $asset->sha256,
                'sync_state' => 'CURRENT',
                'last_verified_at' => $now,
                'created_at' => $asset->created_at ?? $now,
                'updated_at' => $now,
            ]);
        });
    }

    public function down(): void
    {
        DB::table('asset_sources')
            ->whereIn('provider', ['INTERNAL_OBJECT_STORAGE', 'EXTERNAL_PROVIDER'])
            ->delete();
    }
};
