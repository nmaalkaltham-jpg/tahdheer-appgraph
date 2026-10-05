<?php

namespace App\Console\Commands;

use App\Domain\Assets\Exceptions\StorageUnavailable;
use App\Domain\Assets\Models\Asset;
use App\Domain\Assets\StorageResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * «القاعدة تقول إنّ الملفّ موجود» ⇔ «التخزين يُعيده فعلًا».
 *
 * قراءةٌ محضة — لا يُصلح ولا يحذف: يسأل التخزين عن كلّ أصلٍ ويُبلّغ عمّا
 * غاب وعمّا تعذّر سؤاله («لم أستطع السؤال» ليست «غير موجود»). والمفتاح لا
 * يُطبَع أبدًا: هو سرٌّ داخليّ.
 */
class AssetsReconcileCommand extends Command
{
    protected $signature = 'assets:reconcile
        {--checksum : قارن البصمة المخزَّنة ببصمة الكائن (أبطأ)}
        {--json : أخرج النتيجة JSON}
        {--limit=0 : أقصى عددٍ يُفحص (٠ = الكلّ)}';

    protected $description = 'يوفّق صفوف الأصول بكائنات التخزين ويُبلّغ الغائب والمتعذّر — بلا تعديل';

    public function handle(StorageResolver $storage): int
    {
        $provider = $storage->provider();
        $limit = (int) $this->option('limit');
        $checksum = (bool) $this->option('checksum');

        $tally = ['checked' => 0, 'present' => 0, 'missing' => [], 'unreachable' => 0, 'checksum_mismatch' => []];

        $query = Asset::query()->orderBy('id');

        if ($limit > 0) {
            $query->limit($limit);
        }

        foreach ($query->cursor() as $asset) {
            $tally['checked']++;
            $ref = (string) $asset->provider_ref;

            try {
                if (! $provider->exists($ref)) {
                    $tally['missing'][] = (int) $asset->id;

                    continue;
                }

                if ($checksum && $provider->checksum($ref) !== $asset->sha256) {
                    $tally['checksum_mismatch'][] = (int) $asset->id;

                    continue;
                }

                $tally['present']++;
            } catch (StorageUnavailable) {
                // تعذّر السؤال ليس غيابًا — يُعدّ على حدة ولا يُخلط.
                $tally['unreachable']++;
            }
        }

        $broken = count($tally['missing']) + count($tally['checksum_mismatch']);

        if ($broken > 0) {
            Log::error('assets.reconcile.mismatch', [
                'missing' => count($tally['missing']),
                'checksum_mismatch' => count($tally['checksum_mismatch']),
                'unreachable' => $tally['unreachable'],
            ]);
        }

        if ($this->option('json')) {
            $this->line(json_encode($tally, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->line("فُحص {$tally['checked']} · موجود {$tally['present']} · غائب ".count($tally['missing'])
                .' · بصمةٌ مخالفة '.count($tally['checksum_mismatch'])." · تعذّر السؤال {$tally['unreachable']}");
            foreach ($tally['missing'] as $id) {
                $this->warn("  أصلٌ غائب عن التخزين: asset #{$id}");
            }
            foreach ($tally['checksum_mismatch'] as $id) {
                $this->warn("  بصمةٌ مخالفة: asset #{$id}");
            }
        }

        return $broken > 0 ? self::FAILURE : self::SUCCESS;
    }
}
