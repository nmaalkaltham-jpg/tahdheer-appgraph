<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Orders\OrderToken;
use App\Domain\Privacy\CredentialPaths;
use App\Domain\Redirects\NotFoundHit;
use App\Domain\Redirects\RedirectService;
use App\Models\Order;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Removes order access tokens that were stored in plaintext before the sinks redacted them.
 *
 * Sinks: analytics_events.path, order_attributions.landing_path, not_found_hits.path/referrer,
 * and the provider payloads that echo the buyer's link (webhook_deliveries, payment_events).
 * Every value is rewritten by CredentialPaths — the same function the live endpoints use —
 * so a scrubbed row is byte-identical to what the fixed code would have written.
 *
 * `--rotate` also revokes the exposed tokens. There is no mail or account channel in this
 * codebase to hand a buyer the new link, so rotation cuts the buyer off too until an
 * operator sends it; `--show-links` prints the new links for exactly that. Dry run unless
 * `--apply`, and `--apply` needs `--rotate` or `--keep-tokens`: the scrub destroys the only
 * record of which tokens leaked, so leaving them valid has to be a decision, not a default.
 */
final class PrivacyScrubOrderTokensCommand extends Command
{
    protected $signature = 'privacy:scrub-order-tokens
        {--apply : Write the changes (default is a dry run)}
        {--rotate : Also rotate the access token of every order whose token was exposed}
        {--keep-tokens : Scrub without rotating — the leaked tokens stay valid and can no longer be found}
        {--tokens-from=* : Files (log extracts) whose order tokens also count as exposed; they are only read}
        {--show-links : Print the rotated order links so they can be delivered to buyers}';

    protected $description = 'Redact plaintext order tokens from analytics and 404 logs, optionally rotating the exposed tokens';

    /** [table, key column, value column, how the value is redacted] */
    private const SINKS = [
        ['analytics_events', 'id', 'path', 'path'],
        ['order_attributions', 'order_id', 'landing_path', 'path'],
        ['not_found_hits', 'id', 'path', 'path'],
        ['not_found_hits', 'id', 'referrer', 'url'],
        ['webhook_deliveries', 'id', 'payload', 'json'],
        ['payment_events', 'id', 'payload', 'json'],
    ];

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $rotate = (bool) $this->option('rotate');

        if ($apply && $rotate === (bool) $this->option('keep-tokens')) {
            $this->error('--apply needs exactly one of --rotate or --keep-tokens: scrubbing erases the record of which tokens leaked.');

            return self::FAILURE;
        }

        $changes = [];
        $tokens = [];
        foreach (self::SINKS as [$table, $key, $column, $kind]) {
            $rows = $this->changedRows($table, $key, $column, $kind);
            $changes[] = [$table, $key, $column, $rows];
            foreach ($rows as $row) {
                array_push($tokens, ...$row['tokens']);
            }
        }

        /*
         * Logs are not rewritten: they are appended to while we read, and a truncated rewrite
         * loses lines other people need. A token found in one is revoked by rotation instead.
         */
        foreach ((array) $this->option('tokens-from') as $file) {
            $handle = is_file($file) && is_readable($file) ? fopen($file, 'r') : false;
            if ($handle === false) {
                $this->error("Cannot read {$file}.");

                return self::FAILURE;
            }
            while (($line = fgets($handle)) !== false) {
                array_push($tokens, ...CredentialPaths::tokensIn($line));
            }
            fclose($handle);
        }

        $tokens = array_values(array_unique($tokens));
        $orders = Order::query()
            ->whereIn('order_key_hash', array_map(OrderToken::hash(...), $tokens))
            ->orderBy('id')
            ->get();

        $this->table(['sink', 'rows to rewrite'], array_map(
            static fn (array $c): array => ["{$c[0]}.{$c[2]}", count($c[3])],
            $changes,
        ));
        $this->line(sprintf('Distinct tokens exposed: %d — matching live orders: %d (%s)',
            count($tokens),
            $orders->count(),
            $orders->countBy('status')->map(fn ($n, $s) => "{$s}: {$n}")->implode(', ') ?: 'none',
        ));

        if (! $apply) {
            $this->warn('Dry run — nothing written. Re-run with --apply'.($rotate ? ' --rotate' : '').'.');

            return self::SUCCESS;
        }

        $links = DB::transaction(function () use ($changes, $orders, $rotate): array {
            $links = [];
            if ($rotate) {
                foreach ($orders as $order) {
                    $locked = Order::query()->lockForUpdate()->findOrFail($order->id);

                    // Rotated since the scan (an overlapping run): the exposed token is already dead.
                    if ($locked->order_key_hash !== $order->order_key_hash) {
                        continue;
                    }

                    $links[] = [$locked->number, $locked->status, $this->link(OrderToken::rotate($locked))];
                }
            }

            foreach ($changes as [$table, $key, $column, $rows]) {
                $table === 'not_found_hits' && $column === 'path'
                    ? $this->mergeNotFound($rows)
                    : $rows->each(fn (array $r) => DB::table($table)->where($key, $r['key'])->update([$column => $r['to']]));
            }

            return $links;
        });

        Log::notice('privacy.order_tokens_scrubbed', [
            'rows' => array_sum(array_map(static fn (array $c): int => count($c[3]), $changes)),
            'tokens' => count($tokens),
            'orders_rotated' => count($links),
        ]);

        $this->info(sprintf('Rewrote %d rows; rotated %d order tokens.',
            array_sum(array_map(static fn (array $c): int => count($c[3]), $changes)),
            count($links),
        ));

        /*
         * A charge opened before rotation keeps the return link it was created with: the buyer
         * pays (the webhook still captures and funds the order) and lands on the revoked link.
         * Touching the open charge risks a second payment, so the operator is told instead.
         */
        $stale = $links === [] ? 0 : DB::table('payments')
            ->whereIn('order_id', $orders->pluck('id'))
            ->where('state', 'CREATED')
            ->whereNotNull('intent_id')
            ->distinct()
            ->count('order_id');
        if ($stale > 0) {
            $this->warn("{$stale} rotated order(s) have an open provider charge created before rotation: after paying, those buyers return to the revoked link. Send them the new link.");
        }

        if ($links !== [] && $this->option('show-links')) {
            $this->table(['order', 'status', 'new link'], $links);
        } elseif ($links !== []) {
            $this->warn('Rotated buyers can no longer open their old link. Deliver the new one: OrderToken::forOrder($order) re-derives it at any time.');
        }

        return self::SUCCESS;
    }

    /** @return Collection<int, array{key: int, from: string, tokens: list<string>, to: ?string}> */
    private function changedRows(string $table, string $key, string $column, string $kind): Collection
    {
        $query = DB::table($table)->whereNotNull($column)->orderBy($key);
        $width = $kind === 'json' ? null : $this->width($table, $column);

        // Paths and payloads only need rows that can hold a credential segment; referrers are all re-shaped to origin + path.
        if ($kind !== 'url') {
            $query->whereRaw("{$column}::text ilike ?", ['%orders%']);
        }

        return $query->get([$key, $column])
            ->map(function (object $row) use ($key, $column, $kind, $width): array {
                $from = (string) $row->{$column};

                if ($kind === 'json') {
                    $decoded = json_decode($from, true);
                    $redacted = CredentialPaths::redactDeep($decoded);

                    // Compared decoded: re-encoding alone must not count as a change.
                    return ['key' => (int) $row->{$key}, 'from' => $from, 'tokens' => CredentialPaths::tokensInDeep($decoded),
                        'to' => $redacted === $decoded ? $from : (string) json_encode($redacted)];
                }

                $to = $kind === 'path' ? CredentialPaths::redactPath($from) : CredentialPaths::redactUrl($from);

                // `[token]` is longer than a short junk segment: a value near the column width must not abort the run.
                if ($to !== null && $width !== null) {
                    $to = mb_substr($to, 0, $width);
                }

                return ['key' => (int) $row->{$key}, 'from' => $from, 'tokens' => CredentialPaths::tokensIn($from), 'to' => $to];
            })
            ->filter(static fn (array $r): bool => $r['to'] !== $r['from'])
            ->values();
    }

    /**
     * `path` and `url_hash` are unique, and many token paths collapse onto one redacted path:
     * fold each group into its earliest row, summing the counters, instead of colliding.
     *
     * @param  Collection<int, array{key: int, from: string, tokens: list<string>, to: ?string}>  $rows
     */
    private function mergeNotFound(Collection $rows): void
    {
        // Keyed as recordNotFound keys them: '//orders/[token]' and '/orders/[token]' are one row.
        $normalized = $rows->map(fn (array $r): array => $r + ['target' => RedirectService::normalizePath((string) $r['to'])]);

        foreach ($normalized->groupBy('target') as $target => $group) {
            $target = (string) $target;
            $members = NotFoundHit::query()
                ->whereIn('id', $group->pluck('key'))
                ->orWhere('path', $target)
                ->orWhere('url_hash', NotFoundHit::hashFor($target))
                ->orderBy('first_seen_at')->orderBy('id')
                ->lockForUpdate()
                ->get();

            $keeper = $members->first();
            if ($keeper === null) {
                continue; // pruned since the scan
            }
            $others = $members->slice(1);

            $referrer = $keeper->referrer ?? $others->pluck('referrer')->filter()->first();
            NotFoundHit::query()->whereIn('id', $others->pluck('id'))->delete();

            $keeper->forceFill([
                'path' => $target,
                'url_hash' => NotFoundHit::hashFor($target),
                'hits' => (int) $members->sum('hits'),
                'first_seen_at' => $members->min('first_seen_at'),
                'last_seen_at' => $members->max('last_seen_at'),
                'referrer' => $referrer === null ? null : CredentialPaths::redactUrl($referrer),
            ])->save();
        }
    }

    private function width(string $table, string $column): ?int
    {
        $length = DB::table('information_schema.columns')
            ->where('table_name', $table)->where('column_name', $column)
            ->value('character_maximum_length');

        return $length === null ? null : (int) $length;
    }

    private function link(string $token): string
    {
        $frontend = rtrim((string) config('services.tap.frontend_url'), '/');

        return $frontend.'/orders/'.rawurlencode($token);
    }
}
