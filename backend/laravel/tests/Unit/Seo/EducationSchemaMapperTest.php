<?php

namespace Tests\Unit\Seo;

use App\Domain\SEO\EducationSchemaMapper;
use App\Domain\SEO\SiteIdentity;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The parsers ported verbatim from the legacy theme
 * (`tahdheer_edu_schema_iso_duration` / `..._encoding_format`).
 */
class EducationSchemaMapperTest extends TestCase
{
    private EducationSchemaMapper $mapper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mapper = new EducationSchemaMapper(new SiteIdentity);
    }

    /** @return list<array{0: string, 1: ?string}> */
    public static function durations(): array
    {
        return [
            ['ساعتان', null],                 // no digits ⇒ nothing to parse
            ['٣ ساعات', 'PT3H'],              // Arabic-Indic digits
            ['3 ساعات', 'PT3H'],
            ['45 دقيقة', 'PT45M'],
            ['٩٠ دقيقة', 'PT1H30M'],          // minutes roll into hours
            ['1.5 ساعة', 'PT1H30M'],          // fractional hours
            ['2 hours', 'PT2H'],
            ['PT2H30M', 'PT2H30M'],           // already ISO ⇒ passthrough
            ['', null],
            ['غير محدد', null],
        ];
    }

    #[DataProvider('durations')]
    public function test_arabic_durations_become_iso_8601(string $input, ?string $expected): void
    {
        $this->assertSame($expected, $this->mapper->isoDuration($input));
    }

    /** @return list<array{0: ?string, 1: ?string}> */
    public static function formats(): array
    {
        return [
            ['PDF', 'application/pdf'],
            ['pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
            ['.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
            ['XLSX', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
            ['zip', 'application/zip'],
            ['unknown-format', null],
            [null, null],
        ];
    }

    #[DataProvider('formats')]
    public function test_written_formats_map_to_media_types(?string $input, ?string $expected): void
    {
        // An unknown format yields NULL rather than echoing the raw string into
        // markup as if it were a media type.
        $this->assertSame($expected, $this->mapper->encodingFormat($input));
    }
}
