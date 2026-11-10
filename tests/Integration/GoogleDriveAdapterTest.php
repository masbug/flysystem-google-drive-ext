<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Integration;

use Google\Service\Drive\DriveFile;
use Google\Service\Drive\DriveFileShortcutDetails;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\StorageAttributes;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;

/**
 * The Flysystem adapter tests with the default adapter options, and tests for Google Drive features.
 */
class GoogleDriveAdapterTest extends GoogleDriveTestCase
{
    /**
     * Parameters for direct calls of the Google Drive API. They are necessary for shared drives.
     */
    private const API_PARAMS = ['supportsAllDrives' => true];

    /**
     * Create a shortcut with the Google Drive API. The adapter cannot create shortcuts.
     *
     * @return string ID of the shortcut
     */
    private function createShortcut(string $name, string $folderId, string $targetId): string
    {
        $details = new DriveFileShortcutDetails();
        $details->setTargetId($targetId);
        $shortcut = new DriveFile();
        $shortcut->setName($name);
        $shortcut->setParents([$folderId]);
        $shortcut->setMimeType('application/vnd.google-apps.shortcut');
        $shortcut->setShortcutDetails($details);

        return $this->drive()->getService()->files->create($shortcut, self::API_PARAMS + ['fields' => 'id'])->getId();
    }

    /**
     * @return array<string, StorageAttributes> The items of a directory. The key is the path.
     */
    private function listing(string $directory): array
    {
        $items = [];
        /** @var StorageAttributes $item */
        foreach ($this->drive()->listContents($directory, false) as $item) {
            $items[$item->path()] = $item;
        }
        ksort($items);

        return $items;
    }

    /**
     * A file larger than GoogleDriveAdapter::MAX_CHUNK_SIZE (100 MB) uses the resumable upload in
     * StreamableUpload. Smaller files do not use that code.
     *
     * To skip this test: phpunit --exclude-group slow
     */
    #[Test]
    #[Group('slow')]
    public function writing_a_file_larger_than_the_chunk_size(): void
    {
        $megabytes = 150;
        $resource = tmpfile();
        $hash = hash_init('md5');
        for ($i = 0; $i < $megabytes; $i++) {
            // Each block has different content, so a block in an incorrect position changes the checksum.
            $block = str_repeat(pack('N', $i), 262144);
            fwrite($resource, $block);
            hash_update($hash, $block);
        }
        rewind($resource);

        $adapter = $this->drive();
        try {
            $adapter->writeStream('large/dummy.bin', $resource, new Config());
        } finally {
            // The adapter closes the resource after the upload.
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        $this->assertSame($megabytes * 1024 * 1024, $adapter->fileSize('large/dummy.bin')->fileSize());

        // Google Drive calculates the checksum of the stored content. A download is not necessary.
        $file = $adapter->getService()->files->get(
            $this->driveId('large/dummy.bin'),
            self::API_PARAMS + ['fields' => 'md5Checksum']
        );
        $this->assertSame(hash_final($hash), $file->getMd5Checksum());
    }

    /**
     * A Google Docs file has no binary content. readStream() must export it (to DOCX by default).
     */
    #[Test]
    public function reading_a_google_docs_file_as_a_stream(): void
    {
        $adapter = $this->drive();
        $adapter->createDirectory('docs', new Config());

        $document = new DriveFile();
        $document->setName('document');
        $document->setParents([$this->driveId('docs')]);
        $document->setMimeType('application/vnd.google-apps.document');
        $adapter->getService()->files->create($document, self::API_PARAMS + [
            'data' => 'Text for the export test',
            'mimeType' => 'text/plain',
            'uploadType' => 'multipart',
            'fields' => 'id',
        ]);
        $adapter->clearCache();

        $stream = $adapter->readStream('docs/document');
        $contents = stream_get_contents($stream);
        fclose($stream);

        // A DOCX file is a ZIP archive: it starts with a local file header and ends with the
        // end of central directory record (22 bytes when the archive has no comment).
        $this->assertSame("PK\x03\x04", substr($contents, 0, 4), 'The export does not start with a ZIP header');
        $this->assertSame("PK\x05\x06", substr($contents, -22, 4), 'The export does not end with a ZIP end record');
    }

    /**
     * The adapter replaces a shortcut with its target: the item has the ID, the name and the type
     * of the target, in the directory of the shortcut.
     */
    #[Test]
    public function listing_a_shortcut_to_a_file(): void
    {
        $adapter = $this->drive();
        $adapter->write('targets/target.txt', 'contents', new Config());
        $adapter->createDirectory('shortcuts', new Config());
        $targetId = $this->driveId('targets/target.txt');
        $this->createShortcut('link.txt', $this->driveId('shortcuts'), $targetId);
        $adapter->clearCache();

        $items = array_values($this->listing('shortcuts'));

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]->isFile());
        $this->assertSame($targetId, $items[0]->extraMetadata()['id']);
        $this->assertSame('text/plain', $items[0]->mimeType());
        $this->assertSame('contents', $adapter->read($items[0]->path()));
    }

    /**
     * A request for one file by its ID must include the shortcut details.
     */
    #[Test]
    public function fetching_metadata_of_a_shortcut_by_id(): void
    {
        $adapter = $this->drive();
        $adapter->write('targets/target.txt', 'contents', new Config());
        $adapter->createDirectory('shortcuts', new Config());
        $targetId = $this->driveId('targets/target.txt');
        $shortcutId = $this->createShortcut('link.txt', $this->driveId('shortcuts'), $targetId);

        $metadata = self::createAdapter(null, ['useDisplayPaths' => false])->getMetadata($shortcutId);

        $this->assertInstanceOf(FileAttributes::class, $metadata);
        $this->assertSame($targetId, $metadata->extraMetadata()['id']);
        $this->assertSame('text/plain', $metadata->mimeType());
    }

    #[Test]
    public function listing_skips_a_shortcut_without_target(): void
    {
        $adapter = $this->drive();
        $adapter->write('targets/target.txt', 'contents', new Config());
        $adapter->write('shortcuts/other.txt', 'contents', new Config());
        $targetId = $this->driveId('targets/target.txt');
        $this->createShortcut('link.txt', $this->driveId('shortcuts'), $targetId);
        $adapter->getService()->files->delete($targetId, self::API_PARAMS);
        $adapter->clearCache();

        $items = $this->listing('shortcuts');

        $this->assertSame(['shortcuts/other.txt'], array_keys($items));
    }
}
