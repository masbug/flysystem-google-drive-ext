<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Unit;

use Google\Client;
use Google\Service\Drive;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class AdapterLogicTest extends TestCase
{
    private function adapter(array $options = []): ExposedGoogleDriveAdapter
    {
        // A Drive service can be built offline; no network call happens until used.
        return new ExposedGoogleDriveAdapter(new Drive(new Client), null, $options);
    }

    #[Test]
    public function it_splits_paths_into_parent_and_leaf(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(['', 'root'], $adapter->callSplitPath(''));
        $this->assertSame(['b', 'c'], $adapter->callSplitPath('a/b/c'));
        $this->assertSame(['a/b', 'c'], $adapter->callSplitPath('a/b/c', false));
        $this->assertSame(['root', 'foo'], $adapter->callSplitPath('foo', false));
    }

    #[Test]
    public function it_splits_file_extensions(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(['filename' => 'file', 'extension' => 'txt'], $adapter->callSplitFileExtension('file.txt'));
        $this->assertSame(['filename' => 'archive.tar', 'extension' => 'gz'], $adapter->callSplitFileExtension('archive.tar.gz'));
        $this->assertSame(['filename' => 'noext', 'extension' => ''], $adapter->callSplitFileExtension('noext'));
    }

    #[Test]
    public function it_sanitizes_reserved_filename_characters(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('a_b', $adapter->callSanitizeFilename('a/b'));
        $this->assertSame('na_me_.txt', $adapter->callSanitizeFilename('na:me?.txt'));
        $this->assertSame('clean.txt', $adapter->callSanitizeFilename('clean.txt'));
    }

    #[Test]
    public function it_honors_a_custom_sanitize_replacement_char(): void
    {
        $adapter = $this->adapter(['sanitize_replacement_char' => '-']);

        $this->assertSame('a-b', $adapter->callSanitizeFilename('a/b'));
    }

    #[Test]
    public function it_indexes_a_character_within_a_string(): void
    {
        $adapter = $this->adapter();

        $this->assertSame([1, 3], $adapter->callIndexString('a/b/c'));
        $this->assertSame([], $adapter->callIndexString('abc'));
        $this->assertSame([0, 2], $adapter->callIndexString('/a/'));
    }

    #[Test]
    public function it_distributes_global_parameters_across_every_operation(): void
    {
        $adapter = $this->adapter();

        $clean = $adapter->callCleanOptParameters(['quotaUser' => 'u1']);

        $this->assertSame('u1', $clean['files.list']['quotaUser']);
        $this->assertSame('u1', $clean['files.get']['quotaUser']);
        $this->assertSame('u1', $clean['files.update']['quotaUser']);
    }

    #[Test]
    public function it_merges_per_operation_parameters_with_global_ones(): void
    {
        $adapter = $this->adapter();

        $clean = $adapter->callCleanOptParameters([
            'quotaUser' => 'u1',
            'files.list' => ['pageSize' => 10],
        ]);

        $this->assertSame('u1', $clean['files.list']['quotaUser']);
        $this->assertSame(10, $clean['files.list']['pageSize']);
        // Global-only operations still receive the global parameter.
        $this->assertSame('u1', $clean['files.get']['quotaUser']);
        $this->assertArrayNotHasKey('pageSize', $clean['files.get']);
    }
}
