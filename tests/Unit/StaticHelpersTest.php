<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Unit;

use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class StaticHelpersTest extends TestCase
{
    #[Test]
    #[DataProvider('queryValues')]
    public function it_escapes_google_drive_query_literals(string $input, string $expected): void
    {
        $this->assertSame($expected, GoogleDriveAdapter::escapeQueryValue($input));
    }

    public static function queryValues(): array
    {
        return [
            'plain' => ['report', 'report'],
            'single quote' => ["O'Brien", "O\\'Brien"],
            'backslash' => ['a\\b', 'a\\\\b'],
            'injection break' => ["x' or name = '", "x\\' or name = \\'"],
            'unicode' => ['résumé', 'résumé'],
        ];
    }

    #[Test]
    #[DataProvider('dirnames')]
    public function it_normalizes_dirnames(string $input, string $expected): void
    {
        $this->assertSame($expected, GoogleDriveAdapter::normalizeDirname($input));
    }

    public static function dirnames(): array
    {
        return [
            'dot becomes empty' => ['.', ''],
            'keeps real dir' => ['foo', 'foo'],
            'keeps nested' => ['foo/bar', 'foo/bar'],
        ];
    }

    #[Test]
    public function dirname_uses_forward_slashes(): void
    {
        $this->assertSame('foo/bar', GoogleDriveAdapter::dirname('foo/bar/baz.txt'));
        $this->assertSame('', GoogleDriveAdapter::dirname('baz.txt'));
    }

    #[Test]
    #[DataProvider('mimeTypes')]
    public function it_guesses_mime_type_from_extension(string $path, string $content, string $expected): void
    {
        $this->assertSame($expected, GoogleDriveAdapter::guessMimeType($path, $content));
    }

    public static function mimeTypes(): array
    {
        return [
            'json' => ['data.json', '', 'application/json'],
            'png' => ['photo.png', '', 'image/png'],
            'pdf' => ['doc.pdf', '', 'application/pdf'],
            'no extension falls back to text/plain' => ['README', '', 'text/plain'],
        ];
    }
}
