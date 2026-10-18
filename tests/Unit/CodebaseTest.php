<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Whole-codebase checks: every PHP file compiles, every interface text has
 * its French translation, the keyed catalogues match, every page starts its
 * session through session_config.php, the migrations are numbered and
 * recorded in schema.sql, SQL values are bound, CSRF tokens are checked in
 * one place, command-line scripts refuse the web and Apache serves no
 * configuration, dependency or uploaded file.
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

    public function testSchemaRecordsEveryMigration(): void
    {
        preg_match('/INSERT INTO `schema_migrations` \(`version`\) VALUES\s*(.*?);/s', (string) file_get_contents(APP_ROOT . '/database/schema.sql'), $m);
        preg_match_all("/'([^']+)'/", $m[1] ?? '', $versions);
        $this->assertSame(array_keys(migration_files()), $versions[1], 'database/schema.sql must record every migration it includes');
    }

    public function testSqlValuesAreBoundNotWrittenIntoQueries(): void
    {
        // ->query() / mysqli_query() take a constant SQL text (or a variable
        // holding one); values go through prepared statements (db_lib.php).
        // export_for_v2.php reads tables named by its own constant list.
        $offenders = [];
        foreach (self::phpFiles() as $rel => $path) {
            if (in_array($rel, ['export_for_v2.php'], true) || str_starts_with($rel, 'tools/')) {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($path));
            foreach ($tokens as $i => $token) {
                if (!is_array($token) || $token[0] !== T_STRING || !in_array($token[1], ['query', 'mysqli_query', 'multi_query', 'real_query'], true)) {
                    continue;
                }
                $j = $i + 1;
                while (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($tokens[$j] !== '(') {
                    continue;
                }
                $args = [];
                for ($k = $j, $depth = 0; $k < count($tokens); $k++) {
                    $depth += $tokens[$k] === '(' ? 1 : ($tokens[$k] === ')' ? -1 : 0);
                    if ($depth === 0) {
                        break;
                    }
                    if ($k > $j && !(is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT], true))) {
                        $args[] = $tokens[$k];
                    }
                }
                if ($token[1] === 'mysqli_query' && is_array($args[0] ?? null) && $args[0][1] === '$con' && ($args[1] ?? null) === ',') {
                    $args = array_slice($args, 2);
                }
                // A variable alone, or constant strings and constants joined with "."
                $constant = (count($args) === 1 && is_array($args[0]) && $args[0][0] === T_VARIABLE)
                    || ($args && !array_filter($args, fn($t) => !($t === '.' || (is_array($t) && in_array($t[0], [T_CONSTANT_ENCAPSED_STRING, T_STRING], true)))));
                if (!$constant) {
                    $offenders[] = $rel . ':' . $token[2];
                }
            }
        }
        $this->assertSame([], $offenders, 'SQL built with values: use db_rows() / a prepared statement');
    }

    public function testCsrfTokensAreCheckedThroughCsrfValid(): void
    {
        $offenders = [];
        foreach (self::phpFiles() as $rel => $path) {
            if ($rel !== 'session_config.php' && preg_match("/\\\$_POST\\['csrf_token'\\]\\s*(!==|===|!=|==)|hash_equals\\(\\\$_SESSION\\['csrf_token'\\]/", (string) file_get_contents($path))) {
                $offenders[] = $rel;
            }
        }
        $this->assertSame([], $offenders, 'Check the CSRF token with csrf_valid() (session_config.php)');
    }

    public function testCommandLineScriptsRefuseWebRequests(): void
    {
        foreach (['process_reminders.php', 'send_email.php', 'daily_round_alert.php', 'tools/migrate.php', 'tools/import_jcms.php'] as $rel) {
            $code = (string) file_get_contents(APP_ROOT . '/' . $rel);
            $guard = strpos($code, "PHP_SAPI !== 'cli'");
            $this->assertNotFalse($guard, "$rel must stop outside the command line");
            $this->assertLessThan(strpos($code, 'require'), $guard, "$rel must stop before loading anything");
        }
    }

    public function testApacheServesNoConfigurationDependencyOrUploadedFile(): void
    {
        $htaccess = (string) file_get_contents(APP_ROOT . '/.htaccess');
        preg_match_all('/<FilesMatch "([^"]+)">\s*Require all denied/', $htaccess, $files);
        preg_match('/RedirectMatch 404 "([^"]+)"/', $htaccess, $folders);
        $denied = fn(string $name): bool => (bool) array_filter($files[1], fn($re) => preg_match('#' . $re . '#', $name));
        foreach (['.env', '.htaccess', 'composer.json', 'composer.lock', 'phpunit.xml', 'README.md', 'dbcon.php', 'config.php', 'animal_lib.php', 'process_reminders.php', 'send_email.php', 'brand_banner.php'] as $name) {
            $this->assertTrue($denied($name), "$name must not be served");
        }
        foreach (['index.php', 'home.php', 'file_download.php', 'manifest.json', 'logo-mark.svg'] as $name) {
            $this->assertFalse($denied($name), "$name must be served");
        }
        foreach (['/vendor/autoload.php', '/database/schema.sql', '/uploads/abc.pdf', '/tools/migrate.php', '/tests/bootstrap.php', '/.git/config', '/sub/vendor/x'] as $url) {
            $this->assertMatchesRegularExpression('#' . $folders[1] . '#', $url, "$url must not be served");
        }
        $this->assertDoesNotMatchRegularExpression('#' . $folders[1] . '#', '/images/logo-mark.svg');
    }
}
