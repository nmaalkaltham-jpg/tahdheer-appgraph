<?php

namespace App\Console\Commands;

use App\Domain\Payments\PaymentHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * `payments:health` — مقاييس صحّة الدفع سطرَ سجلٍّ مُهيكلًا واحدًا.
 *
 * يُجدوَل كلّ خمس دقائق: سطر `payments.health` يُقرأ بمنظومة الرصد، وسطر
 * `payments.health.alert` (خطأ) حين يستوجب شيءٌ مشغّلًا. `--json` للطباعة.
 */
class PaymentsHealthCommand extends Command
{
    protected $signature = 'payments:health {--window=24 : الساعات} {--json}';

    protected $description = 'مقاييس صحّة الدفع وإنذاراتها';

    public function handle(PaymentHealth $health): int
    {
        $metrics = $health->snapshot((int) $this->option('window'));
        $alerts = $health->alerts($metrics);

        Log::info('payments.health', $metrics);
        foreach ($alerts as $alert) {
            // سطرٌ لكلّ إنذار بمستوى خطورته: الرصد يوجّه الحرج إلى من يُوقَظ.
            $level = $alert['severity'] === 'WARNING' ? 'warning' : ($alert['severity'] === 'HIGH' ? 'error' : 'critical');
            Log::log($level, 'payments.health.alert', $alert);
        }

        if ($this->option('json')) {
            $this->line(json_encode(['metrics' => $metrics, 'alerts' => $alerts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($metrics as $k => $v) {
                $this->line(sprintf('%-34s %s', $k, $v ?? '—'));
            }
            $this->line($alerts === [] ? 'alerts: none' : 'alerts: '.implode(', ', array_map(fn ($a) => "{$a['severity']}:{$a['code']}={$a['value']}", $alerts)));
        }

        return self::SUCCESS;
    }
}
