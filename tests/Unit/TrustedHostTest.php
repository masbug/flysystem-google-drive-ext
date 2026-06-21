<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Unit;

use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Security regression tests for the trusted-host check that prevents the OAuth
 * access token from leaking to an untrusted redirect target in readStream().
 */
class TrustedHostTest extends TestCase
{
    private static function isTrusted(string $host): bool
    {
        // Since PHP 8.1, reflection can invoke private methods without setAccessible().
        $method = new ReflectionMethod(GoogleDriveAdapter::class, 'isTrustedGoogleHost');

        return (bool) $method->invoke(null, $host);
    }

    #[Test]
    #[DataProvider('trustedHosts')]
    public function it_trusts_google_owned_hosts(string $host): void
    {
        $this->assertTrue(self::isTrusted($host));
    }

    public static function trustedHosts(): array
    {
        return [
            ['www.googleapis.com'],
            ['googleapis.com'],
            ['doc-0g-9c-docs.googleusercontent.com'],
            ['drive.google.com'],
            ['lh3.googleusercontent.com'],
            ['WWW.GOOGLEAPIS.COM'],
        ];
    }

    #[Test]
    #[DataProvider('untrustedHosts')]
    public function it_rejects_untrusted_hosts(string $host): void
    {
        $this->assertFalse(self::isTrusted($host));
    }

    public static function untrustedHosts(): array
    {
        return [
            'unrelated domain' => ['evil.com'],
            'suffix spoofing' => ['googleapis.com.evil.com'],
            'prefix spoofing' => ['notgoogleapis.com'],
            'embedded' => ['googleapis.com.attacker.net'],
            'lookalike' => ['google-apis.com'],
            'empty' => [''],
        ];
    }
}
