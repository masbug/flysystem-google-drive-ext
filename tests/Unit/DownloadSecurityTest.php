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
