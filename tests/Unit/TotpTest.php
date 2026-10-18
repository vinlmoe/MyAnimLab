<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once APP_ROOT . '/auth_lib.php';

/** Two-factor authentication: TOTP (RFC 6238 test vectors), recovery codes. */
final class TotpTest extends TestCase
{
    // RFC 6238 appendix B, SHA1 key "12345678901234567890".
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

    public function testBase32RoundTrip(): void
    {
        $this->assertSame(self::RFC_SECRET, base32_encode_bytes('12345678901234567890'));
        $this->assertSame('12345678901234567890', base32_decode_string(strtolower(self::RFC_SECRET)));
        $bytes = random_bytes(20);
        $this->assertSame($bytes, base32_decode_string(base32_encode_bytes($bytes)));
    }

    public function testRfc6238Vectors(): void
    {
        $this->assertSame('94287082', totp_code(self::RFC_SECRET, intdiv(59, 30), 8));
        $this->assertSame('07081804', totp_code(self::RFC_SECRET, intdiv(1111111109, 30), 8));
        $this->assertSame('89005924', totp_code(self::RFC_SECRET, intdiv(1234567890, 30), 8));
        $this->assertSame('287082', totp_code(self::RFC_SECRET, 1));
    }

    public function testVerifyAcceptsOneStepAroundAndRefusesReplay(): void
    {
        $secret = totp_new_secret();
        $time = 1_800_000_000;
        $step = intdiv($time, 30);
        $this->assertSame($step, totp_verify($secret, totp_code($secret, $step), null, $time));
        $this->assertSame($step - 1, totp_verify($secret, totp_code($secret, $step - 1), null, $time));
        $this->assertSame($step + 1, totp_verify($secret, totp_code($secret, $step + 1), null, $time));
        $this->assertNull(totp_verify($secret, totp_code($secret, $step + 2), null, $time));
        // Already used (last step) or older: refused.
        $this->assertNull(totp_verify($secret, totp_code($secret, $step), $step, $time));
        $this->assertNull(totp_verify($secret, 'abcdef', null, $time));
        $this->assertNull(totp_verify($secret, '12345', null, $time));
    }

    public function testRecoveryCodesWorkOnce(): void
    {
        [$plain, $json] = totp_new_recovery_codes();
        $this->assertCount(TOTP_RECOVERY_CODES, $plain);
        $this->assertSame(TOTP_RECOVERY_CODES, totp_recovery_count($json));
        $this->assertStringNotContainsString($plain[0], $json, 'Codes are stored hashed');
        $left = totp_use_recovery_code($json, strtoupper($plain[3]));
        $this->assertNotNull($left);
        $this->assertSame(TOTP_RECOVERY_CODES - 1, totp_recovery_count($left));
        $this->assertNull(totp_use_recovery_code($left, $plain[3]), 'A code works only once');
        $this->assertNull(totp_use_recovery_code($left, 'zzzz-zzzz'));
    }

    public function testUriAndRedirect(): void
    {
        $uri = totp_uri('ABCDEF', 'jane@lab.org', 'My Lab');
        $this->assertStringStartsWith('otpauth://totp/My%20Lab%3Ajane%40lab.org?secret=ABCDEF', $uri);
        $this->assertSame('experiment_manage.php?protocol_id=2', login_redirect_target('experiment_manage.php?protocol_id=2'));
        $this->assertSame('home.php', login_redirect_target('https://evil.example/a.php'));
        $this->assertSame('home.php', login_redirect_target(null));
    }
}
