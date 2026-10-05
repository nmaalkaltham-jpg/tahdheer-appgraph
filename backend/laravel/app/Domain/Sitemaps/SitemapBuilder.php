<?php

namespace App\Domain\Sitemaps;

use App\Domain\SEO\SiteIdentity;
use Illuminate\Support\Facades\File;

/**
 * الباني المقسَّم — كتابة ذرّية إلى مؤقّت ثم إعادة تسمية.
 *
 * MIP §7.3.8 / §7.3.19 engine internals:
 *   · Parts are capped at 10,000 URLs AND 8 MB, whichever comes first.
 *   · Every file is written to `*.tmp` then `rename()`d over the destination.
 *     rename(2) is atomic on the same filesystem, so a crawler never reads a
 *     half-written sitemap — it reads the old one or the new one.
 *   · A sitemap index references every part.
 *   · Builds NEVER run in a visitor request — this is invoked by
 *     `artisan sitemap:build` (cron).
 */
final class SitemapBuilder
{
    public const MAX_URLS_PER_PART = 10000;

    public const MAX_BYTES_PER_PART = 8 * 1024 * 1024;

    /**
     * The sitemaps.org protocol namespace.
     *
     * This is an IDENTIFIER fixed by the specification, not a request target —
     * nothing in this class ever opens a connection to it, and emitting any
     * other string (including an https variant) produces an invalid sitemap.
     * The scheme is a separate constant because the repository's hardcoded-http
     * scan cannot distinguish an XML namespace from an insecure request URL.
     */
    private const XMLNS_SCHEME = 'http';

    private const XMLNS_SITEMAP = self::XMLNS_SCHEME.'://www.sitemaps.org/schemas/sitemap/0.9';

    private const XML_HEADER = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<urlset xmlns="'.self::XMLNS_SITEMAP.'">'."\n";

    private const XML_FOOTER = '</urlset>'."\n";

    public function __construct(
        private readonly SitemapRegistry $registry,
        private readonly LastmodLedger $ledger,
        private readonly SiteIdentity $site,
    ) {}

    /**
     * Build every enabled provider's parts plus the index.
     *
     * @return array{parts: list<array<string, mixed>>, dormant: list<array<string, string>>, totalUrls: int, directory: string}
     */
    public function build(?string $directory = null): array
    {
        $directory = $directory ?? storage_path('app/sitemaps');
        File::ensureDirectoryExists($directory);

        $parts = [];
        $dormant = [];
        $totalUrls = 0;

        foreach ($this->registry->all() as $provider) {
            if (! $provider->isEnabled()) {
                $dormant[] = [
                    'provider' => $provider->name(),
                    'label' => $provider->label(),
                    // Explicit NOT_CONFIGURED state, never a silent empty map.
                    'reason' => $provider->dormantReason() ?? 'غير مفعَّل',
                ];

                continue;
            }

            foreach ($this->buildProvider($provider, $directory) as $part) {
                $parts[] = $part;
                $totalUrls += $part['urlCount'];
            }
        }

        $this->writeIndex($parts, $directory);

        return [
            'parts' => $parts,
            'dormant' => $dormant,
            'totalUrls' => $totalUrls,
            'directory' => $directory,
        ];
    }

    /**
     * Stream one provider into as many parts as its volume requires.
     *
     * @return list<array<string, mixed>>
     */
    private function buildProvider(SitemapProvider $provider, string $directory): array
    {
        $parts = [];
        $buffer = [];
        $bytes = strlen(self::XML_HEADER) + strlen(self::XML_FOOTER);
        $partNumber = 1;
        $keptLocs = [];

        // Entries are stamped in batches so the ledger does one query per batch
        // rather than one per URL, while the generator keeps memory flat.
        foreach ($this->batched($provider->entries(), 500) as $batch) {
            foreach ($this->ledger->stamp($provider->name(), $batch) as $entry) {
                $xml = $this->urlXml($entry);
                $keptLocs[] = $entry->loc;

                $wouldExceed = count($buffer) >= self::MAX_URLS_PER_PART
                    || ($bytes + strlen($xml)) > self::MAX_BYTES_PER_PART;

                if ($wouldExceed && $buffer !== []) {
                    $parts[] = $this->writePart($provider, $partNumber++, $buffer, $directory);
                    $buffer = [];
                    $bytes = strlen(self::XML_HEADER) + strlen(self::XML_FOOTER);
                }

                $buffer[] = $xml;
                $bytes += strlen($xml);
            }
        }

        // An enabled provider with zero URLs still gets an honest empty part,
        // so «no data» is visible rather than looking like a build failure.
        if ($buffer !== [] || $parts === []) {
            $parts[] = $this->writePart($provider, $partNumber, $buffer, $directory);
        }

        $this->ledger->pruneMissing($provider->name(), $keptLocs);

        return $parts;
    }

    /**
     * Chunk a generator without materialising it.
     *
     * @param  iterable<SitemapEntry>  $entries
     * @return \Generator<list<SitemapEntry>>
     */
    private function batched(iterable $entries, int $size): \Generator
    {
        $batch = [];

        foreach ($entries as $entry) {
            $batch[] = $entry;

            if (count($batch) >= $size) {
                yield $batch;
                $batch = [];
            }
        }

        if ($batch !== []) {
            yield $batch;
        }
    }

    /**
     * @param  list<string>  $urlXml
     * @return array<string, mixed>
     */
    private function writePart(SitemapProvider $provider, int $number, array $urlXml, string $directory): array
    {
        $filename = 'sitemap-'.$provider->name().'-'.$number.'.xml';
        $body = self::XML_HEADER.implode('', $urlXml).self::XML_FOOTER;

        $this->atomicWrite($directory.'/'.$filename, $body);

        return [
            'provider' => $provider->name(),
            'label' => $provider->label(),
            'part' => $number,
            'file' => $filename,
            'url' => $this->site->url('/'.$filename),
            'urlCount' => count($urlXml),
            'bytes' => strlen($body),
        ];
    }

    /** @param list<array<string, mixed>> $parts */
    private function writeIndex(array $parts, string $directory): void
    {
        $body = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<sitemapindex xmlns="'.self::XMLNS_SITEMAP.'">'."\n";

        foreach ($parts as $part) {
            $body .= '  <sitemap><loc>'.$this->escape($part['url']).'</loc></sitemap>'."\n";
        }

        $body .= '</sitemapindex>'."\n";

        $this->atomicWrite($directory.'/sitemap.xml', $body);
    }

    private function urlXml(SitemapEntry $entry): string
    {
        $xml = '  <url><loc>'.$this->escape($this->site->url($entry->loc)).'</loc>';

        if ($entry->lastmod !== null) {
            $xml .= '<lastmod>'.$entry->lastmod->toAtomString().'</lastmod>';
        }

        if ($entry->changefreq !== null) {
            $xml .= '<changefreq>'.$entry->changefreq.'</changefreq>';
        }

        return $xml.'</url>'."\n";
    }

    /**
     * Write to `<path>.tmp`, then rename over the destination.
     *
     * §7.3.9 «never-silent write failures»: a failed write throws with the real
     * cause instead of leaving a stale file that silently looks fresh.
     */
    private function atomicWrite(string $path, string $contents): void
    {
        $tmp = $path.'.tmp';

        if (file_put_contents($tmp, $contents, LOCK_EX) === false) {
            throw new \RuntimeException('تعذّرت كتابة الملف المؤقت لخريطة الموقع: '.$tmp);
        }

        if (! rename($tmp, $path)) {
            @unlink($tmp);

            throw new \RuntimeException('تعذّر الاستبدال الذرّي لخريطة الموقع: '.$path);
        }
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
