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
        'experiment_acts_lib.php', 'export_for_v2.php', 'footer.php', 'header.php', 'brand_banner.php', 'i18n.php', 'logout.php', 'message.php',
        'notes_lib.php', 'auth_lib.php', 'cage_files_lib.php', 'followup_lib.php', 'db_lib.php', 'corrections_lib.php', 'pdf_lib.php', 'process_reminders.php', 'send_email.php', 'session_config.php', 'training_lib.php', 'terrarium_lib.php', 'terrarium_form_fields.php', 'medication_lib.php', 'pedigree_lib.php',
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
        // Two-factor authentication is tested on its own (testTwoFactorAuthentication).
        $con->query("INSERT INTO settings (name, value) VALUES ('require_2fa_privileged', '0') ON DUPLICATE KEY UPDATE value = '0'");

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

    /** GET or POST; returns [status, body, location, headers]. */
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
        return [$status, substr($raw, $headerSize), trim($m[1] ?? ''), substr($raw, 0, $headerSize)];
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

    // The page's CSRF token (not the one of the header's language form, which has its own).
    private function pageToken(string $body): string
    {
        $body = preg_replace('#<form[^>]*set_locale\.php.*?</form>#s', '', $body);
        $this->assertGreaterThan(0, preg_match_all('/name="csrf_token" value="([0-9a-f]{64})"/', $body, $m), 'CSRF token on the page');
        return $m[1][0];
    }

    private function csrfToken(string $jar): string
    {
        // The sticky-note form of the home page carries the session's token for every user.
        [, $body] = $this->request('home.php', $jar);
        return $this->pageToken($body);
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
        // With the same token, the author's own note can be changed (the token is right).
        $con->query('INSERT INTO notes (note_text, user_id) VALUES (\'user note\', ' . self::$users['user'] . ')');
        $ownId = $con->insert_id;
        [$status] = $this->request('nt_edit.php', $jar, ['note_id' => $ownId, 'note_text' => 'changed', 'csrf_token' => $token]);
        $this->assertSame(200, $status);
        $this->assertSame('changed', TestDatabase::value($con, "SELECT note_text FROM notes WHERE id = $ownId"));
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

    public function testTwoFactorAuthentication(): void
    {
        require_once APP_ROOT . '/auth_lib.php';
        $con = TestDatabase::connection();
        $con->query("UPDATE settings SET value = '1' WHERE name = 'require_2fa_privileged'");
        $adminId = TestDatabase::user($con, ['role' => 'admin']);
        $username = TestDatabase::value($con, "SELECT username FROM users WHERE id = $adminId");
        $jar = tempnam(sys_get_temp_dir(), 'jar');
        try {
            // Required for an administrator: sent to the set-up page, nothing else is reachable.
            [$status, , $location] = $this->request('index.php', $jar, ['username' => $username, 'password' => 'secret-pass', 'login' => '1']);
            $this->assertSame(302, $status);
            $this->assertStringContainsString('two_factor.php', $location);
            [$status, , $location] = $this->request('trial_manage.php', $jar);
            $this->assertSame(302, $status);
            $this->assertStringContainsString('two_factor.php', $location);

            // Set-up: the key is shown, the first code turns it on, recovery codes appear once.
            [, $body] = $this->request('two_factor.php', $jar);
            $token = $this->pageToken($body);
            [, $body] = $this->request('two_factor.php', $jar, ['csrf_token' => $token, 'start_setup' => '1']);
            [, $body] = $this->request('two_factor.php', $jar);
            $this->assertSame(1, preg_match('/user-select-all">([A-Z2-7 ]+)</', $body, $m), 'Key shown');
            $secret = str_replace(' ', '', $m[1]);
            [$status, $body] = $this->request('two_factor.php', $jar, ['csrf_token' => $token, 'confirm_setup' => '1', 'code' => totp_code($secret, intdiv(time(), 30))]);
            $this->assertSame(200, $status);
            $this->assertStringContainsString('recoveryCodes', $body);
            preg_match_all('/<div class="col-6 col-md-4">([a-z2-7]{4}-[a-z2-7]{4})<\/div>/', $body, $codes);
            $this->assertCount(TOTP_RECOVERY_CODES, $codes[1]);
            $this->assertSame('1', (string) TestDatabase::value($con, "SELECT totp_enabled FROM users WHERE id = $adminId"));
            [$status] = $this->request('trial_manage.php', $jar);
            $this->assertSame(200, $status);

            // Next sign-in: the code is asked after the password.
            $this->request('logout.php', $jar);
            [$status, , $location] = $this->request('index.php', $jar, ['username' => $username, 'password' => 'secret-pass', 'login' => '1']);
            $this->assertStringContainsString('login_2fa.php', $location);
            [$status] = $this->request('home.php', $jar);
            $this->assertSame(302, $status, 'Not signed in before the code');
            [, $body] = $this->request('login_2fa.php', $jar);
            preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $body, $m);
            [$status, $body] = $this->request('login_2fa.php', $jar, ['csrf_token' => $m[1], 'code' => '000000']);
            $this->assertSame(200, $status);
            $this->assertStringContainsString('alert-danger', $body);
            [$status, , $location] = $this->request('login_2fa.php', $jar, ['csrf_token' => $m[1], 'code' => totp_code($secret, intdiv(time(), 30) + 1)]);
            $this->assertSame(302, $status);
            $this->assertStringContainsString('home.php', $location);
            [$status] = $this->request('home.php', $jar);
            $this->assertSame(200, $status);

            // A recovery code works once.
            $this->request('logout.php', $jar);
            $this->request('index.php', $jar, ['username' => $username, 'password' => 'secret-pass', 'login' => '1']);
            [, $body] = $this->request('login_2fa.php', $jar);
            preg_match('/name="csrf_token" value="([0-9a-f]{64})"/', $body, $m);
            [$status, , $location] = $this->request('login_2fa.php', $jar, ['csrf_token' => $m[1], 'code' => $codes[1][0]]);
            $this->assertStringContainsString('home.php', $location);
            $this->assertSame(TOTP_RECOVERY_CODES - 1, totp_recovery_count(TestDatabase::value($con, "SELECT totp_recovery_codes FROM users WHERE id = $adminId")));
        } finally {
            $con->query("UPDATE settings SET value = '0' WHERE name = 'require_2fa_privileged'");
            @unlink($jar);
        }
    }

    public function testCageFilesAreDownloadedBySignedInUsersOnly(): void
    {
        $con = TestDatabase::connection();
        $cage = TestDatabase::cage($con, false);
        @mkdir(APP_ROOT . '/uploads', 0750);
        $stored = 'uploads/' . bin2hex(random_bytes(16)) . '.txt';
        file_put_contents(APP_ROOT . '/' . $stored, '<script>alert(1)</script>');
        try {
            $s = $con->prepare("INSERT INTO files (file_name, file_path, cage_id) VALUES ('notes.txt', ?, ?)");
            $s->bind_param('ss', $stored, $cage);
            $s->execute();
            $fileId = (int) $con->insert_id;
            // A recorded path is never followed outside uploads/.
            $s = $con->prepare("INSERT INTO files (file_name, file_path, cage_id) VALUES ('env', '.env', ?)");
            $s->bind_param('s', $cage);
            $s->execute();
            $outsideId = (int) $con->insert_id;

            [$status, , $location] = $this->request("file_download.php?id=$fileId", null);
            $this->assertSame(302, $status);
            $this->assertStringContainsString('index.php', $location);

            $jar = $this->login('user');
            [$status, $body, , $headers] = $this->request("file_download.php?id=$fileId", $jar);
            $this->assertSame(200, $status);
            $this->assertSame('<script>alert(1)</script>', $body);
            $this->assertMatchesRegularExpression('/^Content-Disposition: attachment; filename="notes\.txt"/mi', $headers);
            $this->assertMatchesRegularExpression('/^Content-Type: text\/plain/mi', $headers);
            $this->assertMatchesRegularExpression('/^X-Content-Type-Options: nosniff/mi', $headers);
            [$status] = $this->request("file_download.php?id=$outsideId", $jar);
            $this->assertSame(404, $status);
            @unlink($jar);
        } finally {
            @unlink(APP_ROOT . '/' . $stored);
        }
    }

    public function testCorrectingAFollowupRequiresAReasonAndKeepsTheReplacedValues(): void
    {
        $con = TestDatabase::connection();
        $animal = TestDatabase::animal($con, TestDatabase::cage($con));
        $enrollment = TestDatabase::enroll($con, TestDatabase::experiment($con), $animal);
        $page = "experiment_followup.php?experiment_animal_id=$enrollment";
        $jar = $this->login('admin');
        [, $body] = $this->request($page, $jar);
        $entry = fn(string $weight, array $more = []): array => [
            'csrf_token' => $this->pageToken($body), 'experiment_animal_id' => $enrollment,
            'observed_at' => date('Y-m-d'), 'weight_g' => $weight, 'clinical_score' => '1', 'observations' => '',
        ] + $more;
        $weight = fn() => TestDatabase::value($con, "SELECT weight_g FROM experiment_followups WHERE experiment_animal_id = $enrollment");
        $revisions = fn() => (int) TestDatabase::value($con, "SELECT COUNT(*) FROM experiment_followup_revisions r JOIN experiment_followups f ON f.id = r.followup_id WHERE f.experiment_animal_id = $enrollment");

        $this->request($page, $jar, $entry('24'));
        $this->assertSame('24.00', $weight());

        // Same day again without a reason: refused, nothing changed.
        $this->request($page, $jar, $entry('22'));
        $this->assertSame('24.00', $weight());
        $this->assertSame(0, $revisions());

        // With a reason: corrected, the replaced weight is kept.
        $this->request($page, $jar, $entry('22', ['revision_reason' => 'wrong animal weighed']));
        $this->assertSame('22.00', $weight());
        $this->assertSame(1, $revisions());
        $followupId = (int) TestDatabase::value($con, "SELECT id FROM experiment_followups WHERE experiment_animal_id = $enrollment");
        [, $view] = $this->request("experiment_followup_view.php?id=$followupId", $jar);
        $this->assertStringContainsString('wrong animal weighed', $view);
        $this->assertStringContainsString('24', $view);
        $this->assertSame(1, (int) TestDatabase::value($con, "SELECT COUNT(*) FROM activity_log WHERE action = 'followup_corrected' AND entity_id = '$animal'"));
        @unlink($jar);
    }

    public function testExportForV2LeavesSecretsOutAndIsLogged(): void
    {
        $con = TestDatabase::connection();
        $before = (int) TestDatabase::value($con, "SELECT COUNT(*) FROM activity_log WHERE action = 'export'");
        $jar = $this->login('admin');
        [$status, $body] = $this->request('export_for_v2.php', $jar);
        $this->assertSame(200, $status);
        $data = json_decode($body, true);
        $this->assertNotEmpty($data['tables']['users']);
        foreach ($data['tables']['users'] as $user) {
            foreach (['password', 'reset_token', 'email_token', 'totp_secret', 'totp_recovery_codes'] as $secret) {
                $this->assertArrayNotHasKey($secret, $user);
            }
            $this->assertArrayHasKey('username', $user);
        }
        $this->assertSame($before + 1, (int) TestDatabase::value($con, "SELECT COUNT(*) FROM activity_log WHERE action = 'export'"));
        [$status] = $this->request('export_for_v2.php', $this->login('user'));
        $this->assertSame(302, $status);
        @unlink($jar);
    }

    public function testCommandLineScriptsAreRefusedOverHttp(): void
    {
        foreach (['process_reminders.php', 'send_email.php', 'daily_round_alert.php'] as $script) {
            [$status] = $this->request($script, null);
            $this->assertSame(403, $status, $script);
        }
    }

    public function testRegisterCorrectionsNeedAnAdministratorAndAReason(): void
    {
        $con = TestDatabase::connection();
        $animal = TestDatabase::animal($con, TestDatabase::cage($con));
        set_animal_origin($con, $animal, ['origin_type' => 'supplier', 'origin_detail' => 'Janvier', 'origin_reference' => null, 'entry_date' => '2026-09-01'], null);
        exit_animal($con, $animal, 'retrouve_mort', '2026-10-01', 'found dead', null, null, null);
        $exitCode = fn() => TestDatabase::value($con, "SELECT exit_code FROM mice WHERE id = $animal");
        $exit = fn(string $jar, array $more) => $this->request('animal_exit.php', $jar, [
            'csrf_token' => $this->csrfToken($jar), 'animal_id' => $animal, 'correct_exit' => '1',
            'exit_code' => 'euthanasie', 'exit_date' => '2026-10-01', 'reason' => 'endpoint', 'destination' => '',
        ] + $more);

        $user = $this->login('user');
        $exit($user, ['correction_reason' => 'try']);
        $this->assertSame('retrouve_mort', $exitCode(), 'a user cannot correct an exit');
        @unlink($user);

        $admin = $this->login('admin');
        $exit($admin, ['correction_reason' => '']);
        $this->assertSame('retrouve_mort', $exitCode(), 'no correction without a reason');
        $exit($admin, ['correction_reason' => 'wrong exit code']);
        $this->assertSame('euthanasie', $exitCode());

        // A recorded entry is corrected with a reason only.
        $entry = fn(array $more) => $this->request('animal_origin.php', $admin, [
            'csrf_token' => $this->csrfToken($admin), 'animal_id' => $animal, 'origin_type' => 'supplier',
            'origin_detail' => 'Charles River', 'origin_reference' => '', 'entry_date' => '2026-09-01',
        ] + $more);
        $entry([]);
        $this->assertSame('Janvier', TestDatabase::value($con, "SELECT origin_detail FROM mice WHERE id = $animal"));
        $entry(['correction_reason' => 'wrong supplier']);
        $this->assertSame('Charles River', TestDatabase::value($con, "SELECT origin_detail FROM mice WHERE id = $animal"));

        [, $view] = $this->request("animal_view.php?id=$animal", $admin);
        $this->assertStringContainsString('wrong exit code', $view);
        $this->assertStringContainsString('wrong supplier', $view);
        @unlink($admin);
    }

    /** Multipart POST of a file; returns [status, location]. */
    private function upload(string $path, string $jar, array $fields, string $file, string $name): array
    {
        $ch = curl_init('http://127.0.0.1:' . self::$port . '/' . $path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields + ['fileUpload' => new CURLFile($file, '', $name)],
        ]);
        $raw = (string) curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        preg_match('/^Location:\s*(.+)$/mi', $raw, $m);
        return [$status, trim($m[1] ?? '')];
    }

    public function testCageFilesAreAddedAndDeletedByTheCageUsers(): void
    {
        $con = TestDatabase::connection();
        $cage = TestDatabase::cage($con, false);
        $con->query("INSERT INTO holding (cage_id, dob) VALUES ('$cage', CURDATE())");
        $count = fn() => (int) TestDatabase::value($con, "SELECT COUNT(*) FROM files WHERE cage_id = '$cage'");
        $dir = sys_get_temp_dir() . '/cagefiles-' . getmypid();
        @mkdir($dir);
        file_put_contents("$dir/protocol.pdf", "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n");
        file_put_contents("$dir/fake.png", '<html><script>alert(1)</script></html>');
        file_put_contents("$dir/image.svg", '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $jar = $this->login('user');
        $fields = fn() => ['csrf_token' => $this->csrfToken($jar), 'cage_id' => $cage, 'page' => 'hc_view'];
        try {
            // Not a user of the cage: no form, upload refused.
            [, $page] = $this->request('hc_view.php?id=' . rawurlencode($cage), $jar);
            $this->assertStringNotContainsString('id="cageFileUpload"', $page);
            $this->upload('file_upload.php', $jar, $fields(), "$dir/protocol.pdf", 'protocol.pdf');
            $this->assertSame(0, $count());

            $con->query("INSERT INTO cage_users (cage_id, user_id) VALUES ('$cage', " . self::$users['user'] . ")");
            [, $page] = $this->request('hc_view.php?id=' . rawurlencode($cage), $jar);
            $this->assertStringContainsString('id="cageFileUpload"', $page);

            // Disguised or scriptable files are refused.
            $this->upload('file_upload.php', $jar, $fields(), "$dir/fake.png", 'fake.png');
            $this->upload('file_upload.php', $jar, $fields(), "$dir/image.svg", 'image.svg');
            $this->assertSame(0, $count());

            [$status, $location] = $this->upload('file_upload.php', $jar, $fields(), "$dir/protocol.pdf", 'protocol.pdf');
            $this->assertSame(302, $status);
            $this->assertStringContainsString('hc_view.php?id=', $location);
            $this->assertSame(1, $count());
            $file = TestDatabase::value($con, "SELECT id FROM files WHERE cage_id = '$cage'");
            $stored = TestDatabase::value($con, "SELECT file_path FROM files WHERE id = $file");
            $this->assertMatchesRegularExpression('#^uploads/[0-9a-f]{32}\.pdf$#', $stored);
            [$status, $body] = $this->request("file_download.php?id=$file", $jar);
            $this->assertSame(200, $status);
            $this->assertSame(file_get_contents("$dir/protocol.pdf"), $body);
            $this->assertSame(1, (int) TestDatabase::value($con, "SELECT COUNT(*) FROM activity_log WHERE action = 'cage_file_added' AND entity_id = '$cage'"));

            // Deleted by POST with the token: record and stored file removed.
            $this->request("delete_file.php?url=hc_view&id=$file", $jar, ['csrf_token' => $this->csrfToken($jar)]);
            $this->assertSame(0, $count());
            $this->assertFileDoesNotExist(APP_ROOT . '/' . $stored);
            $this->assertSame(1, (int) TestDatabase::value($con, "SELECT COUNT(*) FROM activity_log WHERE action = 'cage_file_deleted' AND entity_id = '$cage'"));
        } finally {
            foreach (TestDatabase::connection()->query("SELECT file_path FROM files WHERE cage_id = '$cage'") as $f) {
                @unlink(APP_ROOT . '/' . $f['file_path']);
            }
            array_map('unlink', glob("$dir/*"));
            @rmdir($dir);
            @unlink($jar);
        }
    }

    public function testBreedingCagePageShowsTheLittersBeingNursed(): void
    {
        $con = TestDatabase::connection();
        $cage = TestDatabase::cage($con, false);
        $con->query("INSERT INTO breeding (cage_id, `cross`) VALUES ('$cage', 'A x B')");
        $con->query("INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, status, expected_weaning_date) VALUES
            ('$cage', CURDATE() - INTERVAL 25 DAY, 7, 0, 'nursing', CURDATE() - INTERVAL 4 DAY),
            ('$cage', '2025-01-10', 5, 1, 'weaned', '2025-01-31')");
        $overdueLitter = (int) TestDatabase::value($con, "SELECT id FROM litters WHERE cage_id = '$cage' AND status = 'nursing'");
        // Two pups died since birth: 5 alive now.
        $con->query("INSERT INTO litter_events (litter_id, event, event_date, count) VALUES ($overdueLitter, 'death', CURDATE(), 2)");
        $jar = $this->login('user');
        [$status, $body] = $this->request('bc_view.php?id=' . rawurlencode($cage), $jar);
        $this->assertSame(200, $status);
        $box = substr($body, strpos($body, 'id="currentLitters"'), 4000);
        $this->assertStringContainsString('table-danger', $box, 'overdue weaning in red');
        $this->assertStringContainsString("litter_id=$overdueLitter", $box);
        $this->assertMatchesRegularExpression('/<td>5<\/td>|<td>5 /', $box, 'pups alive now, deaths deducted');
        $this->assertStringContainsString('25', $box, 'age in days');
        $this->assertStringContainsString('id="litterHistory"', $body);
        $this->assertStringContainsString('2025-01-10', substr($body, strpos($body, 'id="litterHistory"')));
        $this->assertStringNotContainsString('2025-01-10', $box, 'weaned litters are in the history only');
        @unlink($jar);
    }

    public function testBreedingCageCardInvitesPencilEntriesReportedInTheSoftware(): void
    {
        $con = TestDatabase::connection();
        $cage = TestDatabase::cage($con, false);
        $con->query("INSERT INTO breeding (cage_id, `cross`) VALUES ('$cage', 'A x B')");
        $con->query("INSERT INTO litters (cage_id, litter_dob, pups_alive, pups_dead, status, expected_weaning_date) VALUES ('$cage', '2026-10-01', 8, 0, 'nursing', '2026-10-22')");
        $litter = (int) $con->insert_id;
        $con->query("INSERT INTO litter_events (litter_id, event, event_date, count) VALUES ($litter, 'death', '2026-10-05', 1)");
        $jar = $this->login('user');

        [$status, $card] = $this->request('cage_labels.php?id=' . rawurlencode($cage), $jar);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('à compléter au crayon', $card);
        $this->assertStringContainsString('01/10/26', $card, 'litter being nursed printed');
        $this->assertStringContainsString('1 saisi(s)', $card, 'deaths already recorded printed');
        $this->assertSame(4, substr_count($card, '<span class="box"></span>'), 'empty rows to fill by hand');
        $this->assertStringContainsString('puis enregistrez-le aussi dans MyAnimLab', $card);

        // The QR code page offers to record what was noted.
        [$status, $check] = $this->request('cage_check.php?id=' . rawurlencode($cage) . '&via=qr', $jar);
        $this->assertSame(200, $status);
        $this->assertStringContainsString('id="checkLitters"', $check);
        $this->assertStringContainsString("litter_manage.php?litter_id=$litter", $check);
        $this->assertStringContainsString('litter_manage.php?cage_id=' . rawurlencode($cage) . '#birth', $check);
        @unlink($jar);
    }

    public function testOnlyAdministratorsChangeCageOwnershipInBulk(): void
    {
        $con = TestDatabase::connection();
        $cage = TestDatabase::cage($con, false);
        $pi = TestDatabase::user($con, ['position' => 'Principal Investigator']);
        $post = fn(string $jar) => $this->request('cage_location.php', $jar, [
            'csrf_token' => $this->csrfToken($jar), 'return' => 'hc_dash.php', 'cage_ids' => [$cage],
            'location_action' => 'ownership', 'owner_pi' => $pi, 'owner_users_mode' => 'add', 'owner_users' => [self::$users['user']],
        ]);
        $user = $this->login('user');
        [, $dash] = $this->request('hc_dash.php', $user);
        $this->assertStringNotContainsString('id="ownershipPanel"', $dash);
        $post($user);
        $this->assertNull(TestDatabase::value($con, "SELECT pi_name FROM cages WHERE cage_id = '$cage'"));
        @unlink($user);

        $admin = $this->login('admin');
        [, $dash] = $this->request('hc_dash.php', $admin);
        $this->assertStringContainsString('id="ownershipPanel"', $dash);
        $post($admin);
        $this->assertSame($pi, (int) TestDatabase::value($con, "SELECT pi_name FROM cages WHERE cage_id = '$cage'"));
        $this->assertSame(self::$users['user'], (int) TestDatabase::value($con, "SELECT user_id FROM cage_users WHERE cage_id = '$cage'"));
        @unlink($admin);
    }
}
