<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whole-codebase checks: every PHP file compiles, every interface text has
 * its French translation, the keyed catalogues match, every page starts its
 * session through session_config.php, and the migrations are numbered.
 */
final class CodebaseTest extends TestCase
{
    /** PHP files of the application (vendor and tests excluded). */
    private static function phpFiles(): array
    {
        $files = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(APP_ROOT, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = $file->getPathname();
            $rel = substr($path, strlen(APP_ROOT) + 1);
            if ($file->getExtension() === 'php' && !preg_match('#^(vendor|tests|storage|uploads)/#', $rel)) {
                $files[$rel] = $path;
            }
        }
        ksort($files);
        return $files;
    }

    public static function phpFileProvider(): array
    {
        return array_map(fn($p) => [$p], self::phpFiles());
    }

    #[DataProvider('phpFileProvider')]
    public function testFileCompiles(string $path): void
    {
        exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }

    public function testEveryTextIsTranslatedInFrench(): void
    {
        $catalogue = require APP_ROOT . '/lang/fr_text.php';
        $missing = [];
        foreach (self::phpFiles() as $rel => $path) {
            if (str_starts_with($rel, 'lang/')) {
                continue;
            }
            preg_match_all("/\\btr[hj]?\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($path), $m);
            foreach ($m[1] as $text) {
                $text = stripslashes($text);
                if (!isset($catalogue[$text])) {
                    $missing[$text] = $rel;
                }
            }
        }
        $this->assertSame([], $missing, 'Texts without French translation (lang/fr_text.php)');
    }

    public function testKeyedCataloguesHaveTheSameKeys(): void
    {
        $en = require APP_ROOT . '/lang/en.php';
        $fr = require APP_ROOT . '/lang/fr.php';
        $this->assertSame([], array_keys(array_diff_key($en, $fr)), 'Keys missing in fr.php');
        $this->assertSame([], array_keys(array_diff_key($fr, $en)), 'Keys missing in en.php');
    }

    public function testFrenchCatalogueHasNoDuplicateKeys(): void
    {
        preg_match_all("/^\\s*('(?:[^'\\\\]|\\\\.)*')\\s*=>/m", (string) file_get_contents(APP_ROOT . '/lang/fr_text.php'), $m);
        $this->assertSame([], array_values(array_unique(array_diff_assoc($m[1], array_unique($m[1])))));
    }

    public function testPagesStartTheirSessionThroughSessionConfig(): void
    {
        $offenders = [];
        foreach (self::phpFiles() as $rel => $path) {
            if ($rel === 'session_config.php') {
                continue;
            }
            if (preg_match('/\bsession_start\s*\(/', (string) file_get_contents($path))) {
                $offenders[] = $rel;
            }
        }
        $this->assertSame([], $offenders, 'Use require_once \'session_config.php\' instead of session_start()');
    }

    public function testMigrationsAreNumberedWithoutGaps(): void
    {
        $numbers = array_map(fn($f) => (int) basename($f), glob(APP_ROOT . '/database/migrations/*.sql'));
        sort($numbers);
        $this->assertSame(range(1, count($numbers)), $numbers);
    }
}
