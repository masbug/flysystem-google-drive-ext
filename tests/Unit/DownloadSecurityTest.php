<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Unit;

use Google\Client;
use Google\Service\Drive;
use League\Flysystem\UnableToReadFile;
use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * Regression tests for readStream(): the access token must go only to Google hosts.
 */
class DownloadSecurityTest extends TestCase
{
    private static function invoke(string $method, ?GoogleDriveAdapter $adapter, ...$args)
    {
        return (new ReflectionMethod(GoogleDriveAdapter::class, $method))->invoke($adapter, ...$args);
    }

    public static function hosts(): array
    {
        return [
            'api host' => ['www.googleapis.com', true],
            'bare domain' => ['googleapis.com', true],
            'download host' => ['doc-0c-9s-docs.googleusercontent.com', true],
            'drive host' => ['drive.google.com', true],
            'upper case' => ['WWW.GoogleApis.com', true],
            'prefix on the domain' => ['evilgoogleapis.com', false],
            'domain as subdomain' => ['googleapis.com.evil.example', false],
            'google.com as subdomain' => ['google.com.evil.example', false],
            'similar domain' => ['notgoogle.com', false],
            'different domain' => ['example.com', false],
            'top-level domain only' => ['com', false],
            'empty' => ['', false],
        ];
    }

    #[DataProvider('hosts')]
    public function testIsTrustedGoogleHost(string $host, bool $expected): void
    {
        $this->assertSame($expected, self::invoke('isTrustedGoogleHost', null, $host));
    }

    public function testHostMatchesDomain(): void
    {
        $this->assertTrue(self::invoke('hostMatchesDomain', null, 'a.b.google.com', '.google.com'));
        $this->assertTrue(self::invoke('hostMatchesDomain', null, 'google.com', 'GOOGLE.com'));
        $this->assertFalse(self::invoke('hostMatchesDomain', null, 'xgoogle.com', 'google.com'));
        $this->assertFalse(self::invoke('hostMatchesDomain', null, 'google.com', 'a.google.com'));
        $this->assertFalse(self::invoke('hostMatchesDomain', null, 'google.com', ''));
    }

    public function testRequestToGoogleHostHasTokenAndMatchingCookies(): void
    {
        $cookies = [
            'googleusercontent.com' => ['AUTH' => 'AUTH=abc', 'NID' => 'NID=1'],
            'www.googleapis.com' => ['OTHER' => 'OTHER=2'],
        ];

        $request = self::invoke('buildDownloadRequest', null, 'doc-0c.googleusercontent.com', '/download?id=1', 'secret-token', $cookies);

        $this->assertSame(
            "GET /download?id=1 HTTP/1.1\r\n".
            "Host: doc-0c.googleusercontent.com\r\n".
            "Authorization: Bearer secret-token\r\n".
            "Cookie: AUTH=abc; NID=1\r\n".
            "Connection: Close\r\n\r\n",
            $request
        );
    }

    public function testRequestToDifferentHostHasNoCredentials(): void
    {
        $cookies = ['googleusercontent.com' => ['AUTH' => 'AUTH=abc'], 'example.com' => ['X' => 'X=1']];

        $request = self::invoke('buildDownloadRequest', null, 'example.com', '/file', 'secret-token', $cookies);

        $this->assertSame("GET /file HTTP/1.1\r\nHost: example.com\r\nConnection: Close\r\n\r\n", $request);
        $this->assertStringNotContainsString('secret-token', $request);
        $this->assertStringNotContainsString('Cookie', $request);
    }

    public function testResponseCookieIsStoredForTheHost(): void
    {
        $cookies = self::invoke('addResponseCookie', null, [], 'www.googleapis.com', 'Set-Cookie: SID=abc123; Path=/; Secure; HttpOnly');

        $this->assertSame(['www.googleapis.com' => ['SID' => 'SID=abc123']], $cookies);
    }

    public function testResponseCookieWithDomainAttribute(): void
    {
        $cookies = self::invoke('addResponseCookie', null, [], 'doc-0c.googleusercontent.com', 'set-cookie: AUTH=abc; Domain=.GoogleUserContent.com; Path=/');

        $this->assertSame(['googleusercontent.com' => ['AUTH' => 'AUTH=abc']], $cookies);
    }

    public function testResponseCookieWithDomainOfDifferentSiteStaysWithTheHost(): void
    {
        $cookies = self::invoke('addResponseCookie', null, [], 'www.googleapis.com', 'Set-Cookie: SID=abc; Domain=google.com');

        $this->assertSame(['www.googleapis.com' => ['SID' => 'SID=abc']], $cookies);
    }

    public function testResponseCookieFromDifferentHostIsIgnored(): void
    {
        $cookies = self::invoke('addResponseCookie', null, [], 'example.com', 'Set-Cookie: SID=abc; Domain=google.com');

        $this->assertSame([], $cookies);
    }

    public function testLaterCookieWithSameNameReplacesTheValue(): void
    {
        $cookies = self::invoke('addResponseCookie', null, [], 'www.googleapis.com', 'Set-Cookie: SID=old');
        $cookies = self::invoke('addResponseCookie', null, $cookies, 'www.googleapis.com', 'Set-Cookie: SID=new');
        $cookies = self::invoke('addResponseCookie', null, $cookies, 'www.googleapis.com', 'Content-Type: text/plain');

        $this->assertSame(['www.googleapis.com' => ['SID' => 'SID=new']], $cookies);
    }

    public static function invalidUrls(): array
    {
        return [
            'http scheme' => ['http://www.googleapis.com/drive/v3/files/id'],
            'no scheme' => ['www.googleapis.com/drive/v3/files/id'],
            'relative path' => ['/drive/v3/files/id'],
            'no host' => ['https:///drive/v3/files/id'],
        ];
    }

    #[DataProvider('invalidUrls')]
    public function testStreamDownloadRejectsUrlBeforeConnection(string $url): void
    {
        $adapter = new GoogleDriveAdapter(new Drive(new Client()));

        $this->expectException(UnableToReadFile::class);
        self::invoke('streamDownload', $adapter, 'file.txt', $url, 'token');
    }

    public function testStreamDownloadStopsAfterTooManyRedirects(): void
    {
        $adapter = new GoogleDriveAdapter(new Drive(new Client()));

        $this->expectException(UnableToReadFile::class);
        self::invoke('streamDownload', $adapter, 'file.txt', 'https://www.googleapis.com/x', 'token', [], 6);
    }
}
