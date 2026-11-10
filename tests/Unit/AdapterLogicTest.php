<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Unit;

use Google\Client;
use Google\Service\Drive;
use PHPUnit\Framework\TestCase;

/**
 * Tests for adapter logic that does not call Google Drive.
 */
class AdapterLogicTest extends TestCase
{
    private function adapter(array $options = []): ExposedGoogleDriveAdapter
    {
        // With no root and no team drive, the constructor sends no request.
        return new ExposedGoogleDriveAdapter(new Drive(new Client()), null, $options);
    }

    public function testConstructorSetsTheRetriesOfTheGoogleClient(): void
    {
        $client = new Client();
        new ExposedGoogleDriveAdapter(new Drive($client));

        $this->assertSame(['retries' => 3], $client->getConfig('retry'));
    }

    public function testConstructorKeepsTheRetryConfigurationOfTheApplication(): void
    {
        $client = new Client(['retry' => ['retries' => 0]]);
        new ExposedGoogleDriveAdapter(new Drive($client));

        $this->assertSame(['retries' => 0], $client->getConfig('retry'));
    }

    public function testSplitPathReturnsParentIdAndFileName(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(['parentId', 'fileId'], $adapter->callSplitPath('grandParentId/parentId/fileId'));
        $this->assertSame(['root', 'fileId'], $adapter->callSplitPath('fileId'));
        $this->assertSame(['', 'root'], $adapter->callSplitPath(''));
        $this->assertSame(['', 'root'], $adapter->callSplitPath('/'));
    }

    public function testSplitPathReturnsFullParentPath(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(['a/b', 'c.txt'], $adapter->callSplitPath('a/b/c.txt', false));
        $this->assertSame(['root', 'c.txt'], $adapter->callSplitPath('c.txt', false));
    }

    public function testSplitFileExtension(): void
    {
        $adapter = $this->adapter();

        $this->assertSame(['filename' => 'file', 'extension' => 'txt'], $adapter->callSplitFileExtension('file.txt'));
        $this->assertSame(['filename' => 'archive.tar', 'extension' => 'gz'], $adapter->callSplitFileExtension('archive.tar.gz'));
        $this->assertSame(['filename' => 'README', 'extension' => ''], $adapter->callSplitFileExtension('README'));
    }

    public function testSanitizeFilenameReplacesReservedCharacters(): void
    {
        $adapter = $this->adapter();

        $this->assertSame('a_b_c', $adapter->callSanitizeFilename('a/b:c'));
        $this->assertSame('John_s file', $adapter->callSanitizeFilename("John's file"));
        $this->assertSame('plain name.txt', $adapter->callSanitizeFilename('plain name.txt'));
    }

    public function testSanitizeFilenameUsesReplacementCharacterOption(): void
    {
        $adapter = $this->adapter(['sanitize_replacement_char' => '-']);

        $this->assertSame('a-b-c', $adapter->callSanitizeFilename('a/b:c'));
    }

    public function testIndexString(): void
    {
        $adapter = $this->adapter();

        $this->assertSame([1, 3], $adapter->callIndexString('a/b/c'));
        $this->assertSame([], $adapter->callIndexString('abc'));
        $this->assertSame([0, 4], $adapter->callIndexString('.abc.', '.'));
    }

    public function testCleanOptParametersMergesGlobalAndOperationParameters(): void
    {
        $clean = $this->adapter()->callCleanOptParameters([
            'quotaUser' => 'user-1',
            'files.list' => ['pageSize' => 10],
        ]);

        $this->assertSame(['quotaUser' => 'user-1', 'pageSize' => 10], $clean['files.list']);
        $this->assertSame(['quotaUser' => 'user-1'], $clean['files.get']);
        $this->assertArrayNotHasKey('quotaUser', $clean);
        $this->assertCount(8, $clean);
    }
}
