<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * End-to-end checks through PHP's built-in web server on the test database:
 * real sign-in, every page opened by an administrator, a user and an
 * anonymous visitor without PHP error, access control, CSRF and session
 * expiry. Skipped without a test database server.
 */
final class PagesTest extends TestCase
{
    private static $server = null;
    private static int $port = 0;
    private static string $log = '';
    private static array $users = [];

    // Pages that are not opened directly (libraries, cron / CLI scripts) or that
    // sign out.
    private const NOT_PAGES = [
        'animal_lib.php', 'cage_view_parts.php', 'config.php', 'daily_round_alert.php', 'dbcon.php', 'ethics_lib.php',
        'experiment_acts_lib.php', 'export_for_v2.php', 'footer.php', 'header.php', 'i18n.php', 'logout.php', 'message.php',
        'notes_lib.php', 'pdf_lib.php', 'process_reminders.php', 'send_email.php', 'session_config.php', 'training_lib.php',
    ];
    private const PUBLIC_PAGES = ['confirm_email.php', 'forgot_password.php', 'index.php', 'register.php', 'reset_password.php'];

    public static function setUpBeforeClass(): void
    {
        $con = TestDatabase::connection();
        if (!$con) {
            return;
        }
        $c = TestDatabase::config();
        self::$users['admin'] = TestDatabase::user($con, ['role' => 'admin', 'username' => TestDatabase::unique('admin') . '@test.local']);
        self::$users['user'] = TestDatabase::user($con);
        $con->query("INSERT INTO settings (name, value) VALUES ('session_timeout_minutes', '30') ON DUPLICATE KEY UPDATE value = '30'");

        // Some fixtures so that pages with an id have something to show.
        $cage = TestDatabase::cage($con);
        $animal = TestDatabase::animal($con, $cage);
        $experiment = TestDatabase::experiment($con);
        TestDatabase::enroll($con, $experiment, $animal);
        TestDatabase::medication($con, 5.0, 100.0, '+1 year', true);

        self::$port = random_int(20000, 40000);
        self::$log = tempnam(sys_get_temp_dir(), 'phplog');
        $env = [
            'DB_HOST' => $c['host'], 'DB_PORT' => (string) $c['port'], 'DB_USERNAME' => $c['user'], 'DB_PASSWORD' => $c['password'],
            'DB_DATABASE' => $c['name'], 'SMTP_HOST' => 'localhost', 'SMTP_PORT' => '25', 'SMTP_USERNAME' => '', 'SMTP_PASSWORD' => '',
            'SMTP_ENCRYPTION' => '', 'SENDER_EMAIL' => 'test@test.local', 'SENDER_NAME' => 'Test', 'PATH' => getenv('PATH'),
        ];
        $cmd = [PHP_BINARY, '-d', 'variables_order=EGPCS', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . self::$log,
            '-d', 'error_reporting=' . E_ALL, '-S', '127.0.0.1:' . self::$port, '-t', APP_ROOT];
        self::$server = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, APP_ROOT, $env);
        for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', self::$port); $i++) {
            usleep(100000);
        }
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
        if (self::$log) {
            @unlink(self::$log);
        }
    }

    protected function setUp(): void
    {
        if (!self::$server) {
            $this->markTestSkipped(TestDatabase::unavailableReason() ?: 'Web server not started.');
        }
    }

    /** GET or POST; returns [status, body, location]. */
    private function request(string $path, ?string $jar, array $post = null): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/' . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30]);
        if ($jar) {
            curl_setopt_array($ch, [CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar]);
        }
        if ($post !== null) {
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => http_build_query($post)]);
        }
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        preg_match('/^Location:\s*(.+)$/mi', substr($raw, 0, $headerSize), $m);
        return [$status, substr($raw, $headerSize), trim($m[1] ?? '')];
    }

    /** Sign in through the login form; returns the cookie jar. */
    private function login(string $who): string
    {
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        $username = TestDatabase::value(TestDatabase::connection(), 'SELECT username FROM users WHERE id = ' . self::$users[$who]);
        [$status, , $location] = $this->request('index.php', $jar, ['username' => $username, 'password' => 'secret-pass', 'login' => '1']);
        $this->assertSame(302, $status, "Sign-in of $who");
        $this->assertStringContainsString('home.php', $location);
        return $jar;
    }

    private function csrfToken(string $jar): string
    {
        [, $body] = $this->request('trial_manage.php', $jar);
        $this->assertSame(1, preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $body, $m), 'CSRF token on the page');
        return $m[1];
    }

    private function pages(): array
    {
        $pages = array_map('basename', glob(APP_ROOT . '/*.php'));
        return array_values(array_diff($pages, self::NOT_PAGES));
    }

    private function phpErrors(): array
    {
        clearstatcache();
        $lines = file(self::$log, FILE_IGNORE_NEW_LINES) ?: [];
        file_put_contents(self::$log, '');
        return array_values(array_filter($lines, fn($l) => preg_match('/PHP (Fatal|Warning|Notice|Deprecated|Parse)/', $l)));
    }

    public function testEveryPageOpensWithoutPhpError(): void
    {
        $query = '?id=1&experiment_id=1&protocol_id=1&trial_id=1&experiment_animal_id=1&user_id=1&product_id=1';
        $this->phpErrors();
        foreach (['admin', 'user'] as $who) {
            $jar = $this->login($who);
            foreach ($this->pages() as $page) {
                [$status] = $this->request($page . $query, $jar);
                $this->assertLessThan(500, $status, "$who: $page answered $status");
            }
            @unlink($jar);
        }
        $this->assertSame([], $this->phpErrors());
    }

    public function testAnonymousVisitorsOnlyReachPublicPages(): void
    {
        $open = [];
        foreach ($this->pages() as $page) {
            [$status, $body] = $this->request($page, null);
            if ($status === 200 && !in_array($page, self::PUBLIC_PAGES, true) && !str_contains($body, '"success":false') && !str_contains($body, '"error"')) {
                $open[] = $page;
            }
        }
        $this->assertSame([], $open, 'Pages answering without sign-in');
    }

    public function testAdministrationPagesAreRefusedToUsers(): void
    {
        $jar = $this->login('user');
        foreach (['manage_users.php', 'manage_lab.php', 'manage_strain.php', 'manage_animal_lines.php', 'log_export.php', 'training_overview.php', 'experiment_retrospective.php'] as $page) {
            [$status, $body] = $this->request($page, $jar);
            $this->assertTrue($status === 302 || $status === 403, "$page answered $status to a user");
        }
        @unlink($jar);
    }

    public function testPostsWithoutCsrfTokenAreRefused(): void
    {
        $jar = $this->login('admin');
        $before = TestDatabase::value(TestDatabase::connection(), 'SELECT COUNT(*) FROM trials');
        [$status, $body] = $this->request('trial_manage.php', $jar, ['add_trial' => '1', 'title' => 'CSRF attack']);
        $this->assertSame($before, TestDatabase::value(TestDatabase::connection(), 'SELECT COUNT(*) FROM trials'));
        [$status, $body] = $this->request('nt_rmv.php', $jar, ['note_id' => '1']);
        $this->assertSame(403, $status);
        [$status] = $this->request('delete_file.php?id=1', $jar);
        $this->assertSame(403, $status);
        @unlink($jar);
    }

    public function testNotesCanOnlyBeChangedByTheirAuthor(): void
    {
        $con = TestDatabase::connection();
        $con->query('INSERT INTO notes (note_text, user_id) VALUES (\'admin note\', ' . self::$users['admin'] . ')');
        $noteId = $con->insert_id;
        $jar = $this->login('user');
        $token = $this->csrfToken($jar);
        [$status] = $this->request('nt_edit.php', $jar, ['note_id' => $noteId, 'note_text' => 'changed', 'csrf_token' => $token]);
        $this->assertSame(403, $status);
        $this->assertSame('admin note', TestDatabase::value($con, "SELECT note_text FROM notes WHERE id = $noteId"));
        [$status] = $this->request('nt_edit.php', null, ['note_id' => $noteId, 'note_text' => 'anonymous']);
        $this->assertSame(401, $status);
        @unlink($jar);
    }

    public function testSessionExpiresAfterTheConfiguredInactivity(): void
    {
        $jar = $this->login('user');
        [$status] = $this->request('home.php', $jar);
        $this->assertSame(200, $status);
        // Make the session look idle for 31 minutes (limit: 30).
        $sid = null;
        foreach (file($jar) as $line) {
            if (preg_match('/\tPHPSESSID\t(\S+)/', $line, $m)) {
                $sid = $m[1];
            }
        }
        $this->assertNotNull($sid);
        $file = rtrim(ini_get('session.save_path') ?: sys_get_temp_dir(), '/') . '/sess_' . $sid;
        if (!is_writable($file)) {
            $this->markTestSkipped('Session files are not reachable from the test process.');
        }
        $data = (string) file_get_contents($file);
        file_put_contents($file, preg_replace('/LAST_ACTIVITY\|i:\d+;/', 'LAST_ACTIVITY|i:' . (time() - 31 * 60) . ';', $data));
        [$status, , $location] = $this->request('home.php', $jar);
        $this->assertSame(302, $status);
        $this->assertStringContainsString('index.php', $location);
        [, $body] = $this->request('index.php', $jar);
        $this->assertStringContainsString('30 minutes', $body);
        @unlink($jar);
    }
}
