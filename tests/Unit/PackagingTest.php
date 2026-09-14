<?php

declare(strict_types=1);

namespace Uptimeify\Tests\Unit;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Addon\Uptimeify\Version;

/**
 * Release packaging (spec §13): marketplace metadata and the real contents of the release ZIP built by bin/build.sh.
 */
final class PackagingTest extends TestCase
{
    /** Top-level runtime files of the module; lib/, templates/ and lang/ are compared file by file. */
    private const RUNTIME_FILES = ['uptimeify.php', 'hooks.php', 'whmcs.json', 'logo.png', 'LICENSE', 'README.md', 'CHANGELOG.md'];

    private const RUNTIME_DIRS = ['lib', 'templates', 'lang'];

    /** Links and domain names are not allowed in Marketplace listing texts (spec §13). */
    private const LINK_PATTERN = '~://|www\.|\b[a-z0-9-]+\.(io|com|net|org|de)\b~i';

    private ?string $tmp = null;

    protected function tearDown(): void
    {
        if ($this->tmp !== null && is_dir($this->tmp)) {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->tmp);
        }
        $this->tmp = null;
    }

    public function testWhmcsJsonMatchesVersionAndSchema(): void
    {
        $json = $this->whmcsJson();
        // Values from developers.whmcs.com/advanced/json-file/ (schema "1.0", type whmcs-addons).
        self::assertSame('1.0', $json['schema'] ?? null);
        self::assertSame('whmcs-addons', $json['type'] ?? null);
        self::assertSame('uptimeify', $json['name'] ?? null);
        self::assertSame('MIT', $json['license'] ?? null);
        self::assertSame(Version::VERSION, $json['version'] ?? null);
        self::assertSame('logo.png', $json['logo']['filename'] ?? null);
        self::assertLessThanOrEqual(255, strlen((string) ($json['description']['tagline'] ?? '')));
    }

    public function testMarketplaceListingTextContainsNoLinks(): void
    {
        $description = $this->whmcsJson()['description'] ?? null;
        self::assertIsArray($description);
        $texts = [$description['name'] ?? '', $description['tagline'] ?? '', $description['long'] ?? ''];
        self::assertIsArray($description['features'] ?? null);
        foreach ($description['features'] as $feature) {
            $texts[] = $feature;
        }
        foreach ($texts as $text) {
            self::assertIsString($text);
            self::assertNotSame('', trim($text));
            self::assertDoesNotMatchRegularExpression(self::LINK_PATTERN, $text, 'listing text must not contain links (spec §13)');
        }
    }

    public function testMarketplaceListingDraftRepeatsTheWhmcsJsonTexts(): void
    {
        $path = self::root() . '/docs/marketplace/listing.md';
        self::assertFileExists($path);
        $raw = (string) file_get_contents($path);
        $listing = (string) preg_replace('/\s+/', ' ', $raw);
        $json = $this->whmcsJson();
        self::assertIsString($json['category'] ?? null);
        self::assertStringContainsString('`category`: `' . $json['category'] . '`', $listing);
        $description = $json['description'] ?? null;
        self::assertIsArray($description);
        self::assertIsArray($description['features'] ?? null);
        foreach ([$description['tagline'] ?? null, $description['long'] ?? null] as $text) {
            self::assertIsString($text);
            self::assertStringContainsString($text, $listing, 'listing.md repeats whmcs.json verbatim');
        }
        foreach ($description['features'] as $feature) {
            self::assertIsString($feature);
            self::assertMatchesRegularExpression('/^- ' . preg_quote($feature, '/') . '$/m', $raw, 'feature bullet in listing.md');
        }
    }

    public function testMarketplaceExtendedDescriptionContainsNoLinks(): void
    {
        $raw = (string) file_get_contents(self::root() . '/docs/marketplace/listing.md');
        self::assertSame(1, preg_match('/^### Extended description[^\n]*\n(.*?)(?=^#{1,3} )/ms', $raw, $match), 'listing.md has an "Extended description" section followed by another heading');
        self::assertNotSame('', trim($match[1]));
        self::assertDoesNotMatchRegularExpression(self::LINK_PATTERN, $match[1], 'the Marketplace body text must not contain links (spec §13)');
    }

    public function testLogoIsA200PixelSquarePng(): void
    {
        self::assertFileExists(self::root() . '/logo.png');
        $info = getimagesize(self::root() . '/logo.png');
        self::assertIsArray($info);
        self::assertSame([200, 200, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]]);
    }

    public function testReleaseZipContainsExactlyTheRuntimeFilesUnderUptimeify(): void
    {
        $this->tmp = sys_get_temp_dir() . '/uptimeify-build-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->tmp, 0o700));

        $output = [];
        exec('DIST_DIR=' . escapeshellarg($this->tmp) . ' sh ' . escapeshellarg(self::root() . '/bin/build.sh') . ' 2>&1', $output, $code);
        self::assertSame(0, $code, "bin/build.sh failed:\n" . implode("\n", $output));

        $zip = $this->tmp . '/uptimeify-whmcs-' . Version::VERSION . '.zip';
        self::assertFileExists($zip);

        $entries = [];
        exec('unzip -Z1 ' . escapeshellarg($zip) . ' 2>&1', $entries, $code);
        self::assertSame(0, $code, "unzip failed:\n" . implode("\n", $entries));
        $files = array_values(array_filter($entries, static fn (string $e): bool => !str_ends_with($e, '/')));

        foreach ($files as $file) {
            self::assertStringStartsWith('uptimeify/', $file, 'the ZIP must unpack into modules/addons/uptimeify');
            foreach (explode('/', $file) as $segment) {
                self::assertStringStartsNotWith('.', $segment, "dotfile in the ZIP: {$file}");
                self::assertNotContains($segment, ['tests', 'docker', 'docs', 'vendor', 'bin'], "dev path in the ZIP: {$file}");
            }
        }

        sort($files);
        self::assertSame($this->expectedFiles(), $files);
    }

    /** @return list<string> */
    private function expectedFiles(): array
    {
        $root = self::root();
        $expected = array_map(static fn (string $f): string => 'uptimeify/' . $f, self::RUNTIME_FILES);
        foreach (self::RUNTIME_DIRS as $dir) {
            $items = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($items as $item) {
                /** @var \SplFileInfo $item */
                $relative = substr($item->getPathname(), strlen($root) + 1);
                if ($item->isFile() && !str_contains('/' . $relative, '/.')) {
                    $expected[] = 'uptimeify/' . $relative;
                }
            }
        }
        sort($expected);
        return $expected;
    }

    /** @return array<string,mixed> */
    private function whmcsJson(): array
    {
        $path = self::root() . '/whmcs.json';
        self::assertFileExists($path);
        $json = json_decode((string) file_get_contents($path), true);
        self::assertIsArray($json);
        /** @var array<string,mixed> $json */
        return $json;
    }

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }
}
