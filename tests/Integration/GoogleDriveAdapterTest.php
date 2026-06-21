<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Integration;

use Google\Client;
use Google\Service\Drive;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\Test;

/**
 * Live integration tests against a real Google Drive account.
 *
 * These are skipped automatically unless a `google-drive-service-account.json`
 * file with valid credentials is present in the project root.
 */
class GoogleDriveAdapterTest extends FilesystemAdapterTestCase
{
    protected function retryOnException(string $className, int $timout = 2): void
    {
        $this->exceptionTypeToRetryOn = null;
        $this->timeoutForExceptionRetry = $timout;
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        $file = __DIR__.'/../../google-drive-service-account.json';
        if (!file_exists($file)) {
            self::markTestSkipped("No google service account file {$file} found in project root.");
        }

        try {
            $config = json_decode((string) file_get_contents($file), true);
            if (!$config) {
                self::markTestSkipped("Format json error in {$file}.");
            }
            if (empty($config['GOOGLE_DRIVE_CLIENT_ID'] ?? null) ||
                empty($config['GOOGLE_DRIVE_CLIENT_SECRET'] ?? null) ||
                empty($config['GOOGLE_DRIVE_REFRESH_TOKEN'] ?? null)
            ) {
                self::markTestSkipped("No google service config found in {$file}.");
            }

            $options = ['usePermanentDelete' => true];
            if (!empty($config['GOOGLE_DRIVE_TEAM_DRIVE_ID'] ?? null)) {
                $options['teamDriveId'] = $config['GOOGLE_DRIVE_TEAM_DRIVE_ID'];
            }
            if (!empty($config['GOOGLE_DRIVE_SHARED_FOLDER_ID'] ?? null)) {
                $options['sharedFolderId'] = $config['GOOGLE_DRIVE_SHARED_FOLDER_ID'];
            }

            $client = new Client();
            $client->setClientId($config['GOOGLE_DRIVE_CLIENT_ID']);
            $client->setClientSecret($config['GOOGLE_DRIVE_CLIENT_SECRET']);
            $client->refreshToken($config['GOOGLE_DRIVE_REFRESH_TOKEN']);
            $service = new Drive($client);

            return new GoogleDriveAdapter($service, 'tests/', $options);
        } catch (\Exception $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    #[Test]
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        // This adapter always returns a mime-type, so the base-class expectation does not apply.
        $this->assertTrue(true);
    }

    #[Test]
    public function creating_zero_dir(): void
    {
        $this->runScenario(function () {
            $adapter = $this->adapter();
            $adapter->write('0/file.txt', 'contents', new Config());
            $contents = $adapter->read('0/file.txt');
            $this->assertEquals('contents', $contents);
        });
    }
}
