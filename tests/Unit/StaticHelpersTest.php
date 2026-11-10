<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Unit;

use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class StaticHelpersTest extends TestCase
{
    public static function queryValues(): array
    {
        return [
            'plain name' => ['file.txt', 'file.txt'],
            'single quote' => ["John's file", "John\\'s file"],
            'backslash' => ['a\\b', 'a\\\\b'],
            'backslash before quote' => ["a\\'b", "a\\\\\\'b"],
            'attempt to end the literal' => ["x' or name contains '", "x\\' or name contains \\'"],
            'empty' => ['', ''],
        ];
    }

    #[DataProvider('queryValues')]
    public function testEscapeQueryValue(string $value, string $expected): void
    {
        $this->assertSame($expected, GoogleDriveAdapter::escapeQueryValue($value));
    }

    public function testNormalizeDirname(): void
    {
        $this->assertSame('', GoogleDriveAdapter::normalizeDirname('.'));
        $this->assertSame('a/b', GoogleDriveAdapter::normalizeDirname('a/b'));
    }

    public function testDirname(): void
    {
        $this->assertSame('a/b', GoogleDriveAdapter::dirname('a/b/c.txt'));
        $this->assertSame('', GoogleDriveAdapter::dirname('c.txt'));
    }

    public function testGuessMimeTypeFromContent(): void
    {
        $this->assertSame('application/pdf', GoogleDriveAdapter::guessMimeType('file.bin', "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n"));
    }

    public function testGuessMimeTypeFromPathWhenContentIsPlainText(): void
    {
        $this->assertSame('application/json', GoogleDriveAdapter::guessMimeType('data.json', '{"a": 1}'));
    }

    public function testGuessMimeTypeDefault(): void
    {
        $this->assertSame('text/plain', GoogleDriveAdapter::guessMimeType('no-extension', 'plain text'));
    }
}
