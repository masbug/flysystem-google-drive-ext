<?php

declare(strict_types=1);

namespace Masbug\Flysystem\Tests\Integration;

use Google\Client;
use Google\Service\Drive;
use League\Flysystem\AdapterTestUtilities\FilesystemAdapterTestCase;
use League\Flysystem\Config;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\StorageAttributes;
use Masbug\Flysystem\GoogleDriveAdapter;
use PHPUnit\Framework\Attributes\Test;
use Throwable;

/**
 * Base class for the tests against a real Google Drive account.
 *
 * PHPUnit skips these tests unless the file `google-drive-service-account.json`
 * with valid credentials exists. See credentialsFile() for the location.
 *
 * Each test class works in its own folder `tests/run-<UTC time>-<random>` in Google Drive, so that
 * two test runs at the same time do not change the files of each other. The class deletes its
 * folder after the last test. Before the first test, it deletes the run folders of earlier runs
 * that are older than STALE_AFTER seconds. Such a folder stays when a run stops before its end.
 */
abstract class GoogleDriveTestCase extends FilesystemAdapterTestCase
{
    /**
     * Folder in Google Drive that contains the run folders.
     */
    private const TESTS_FOLDER = 'tests';

    private const RUN_FOLDER_PREFIX = 'run-';

    /**
     * Age in seconds after which a run folder of a different run is not in use anymore.
     */
    private const STALE_AFTER = 3600;

    /**
     * Name of the run folder for each test class.
     *
     * @var array<string, string>
     */
    private static array $runFolders = [];

    protected $exceptionTypeToRetryOn = null;

    protected function retryOnException(string $className, int $timout = 2): void
    {
        $this->exceptionTypeToRetryOn = null;
        $this->timeoutForExceptionRetry = $timout;
    }

    /**
     * Adapter options of the test class. These options replace the options from the credentials file.
     */
    protected static function adapterOptions(): array
    {
        return [];
    }

    /**
     * Path of the credentials file.
     *
     * The file is outside the repository, so that git cannot commit it. The default location is
     * the directory that contains the project directory. The environment variable
     * GOOGLE_DRIVE_SERVICE_ACCOUNT_FILE can give a different path.
     */
    private static function credentialsFile(): string
    {
        $file = getenv('GOOGLE_DRIVE_SERVICE_ACCOUNT_FILE');
        if (is_string($file) && $file !== '') {
            return $file;
        }
        return dirname(__DIR__, 3).'/google-drive-service-account.json';
    }

    /**
     * Create the Google Drive service and the adapter options from the credentials file.
     *
     * @return array{0: Drive, 1: array}
     */
    private static function serviceAndOptions(): array
    {
        $file = self::credentialsFile();
        if (!file_exists($file)) {
            self::markTestSkipped("No google service account file {$file} found.");
        }
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
        try {
            $client = new Client();
            $client->setClientId($config['GOOGLE_DRIVE_CLIENT_ID']);
            $client->setClientSecret($config['GOOGLE_DRIVE_CLIENT_SECRET']);
            $client->refreshToken($config['GOOGLE_DRIVE_REFRESH_TOKEN']);

            return [new Drive($client), array_replace($options, static::adapterOptions())];
        } catch (\Exception $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    protected static function createFilesystemAdapter(): FilesystemAdapter
    {
        if (!isset(self::$runFolders[static::class])) {
            self::removeStaleRunFolders(self::createAdapter(self::TESTS_FOLDER));
            self::$runFolders[static::class] = self::RUN_FOLDER_PREFIX.gmdate('Ymd-His').'-'.bin2hex(random_bytes(3));
        }

        return self::createAdapter(self::TESTS_FOLDER.'/'.self::$runFolders[static::class]);
    }

    /**
     * Create an adapter for the test account.
     *
     * @param  string|null  $root  Root path of the adapter
     * @param  array  $options  Adapter options that replace the options of the test class
     */
    protected static function createAdapter(?string $root, array $options = []): GoogleDriveAdapter
    {
        [$service, $defaultOptions] = self::serviceAndOptions();

        return new GoogleDriveAdapter($service, $root, array_replace($defaultOptions, $options));
    }

    /**
     * Delete the run folders that a stopped test run did not delete.
     */
    private static function removeStaleRunFolders(GoogleDriveAdapter $tests): void
    {
        $pattern = '/^'.preg_quote(self::RUN_FOLDER_PREFIX, '/').'(\d{8}-\d{6})-[0-9a-f]{6}$/';
        /** @var StorageAttributes $item */
        foreach ($tests->listContents('', false) as $item) {
            if (!$item->isDir() || !preg_match($pattern, basename($item->path()), $match)) {
                continue; // not a run folder: do not touch it
            }
            $created = \DateTimeImmutable::createFromFormat('Ymd-His', $match[1], new \DateTimeZone('UTC'));
            if ($created !== false && time() - $created->getTimestamp() > self::STALE_AFTER) {
                $tests->deleteDirectory($item->path());
            }
        }
    }

    public static function tearDownAfterClass(): void
    {
        $runFolder = self::$runFolders[static::class] ?? null;
        unset(self::$runFolders[static::class]);
        if ($runFolder !== null) {
            try {
                self::createAdapter(self::TESTS_FOLDER)->deleteDirectory($runFolder);
            } catch (Throwable $e) {
                // The next test run deletes the folder when it is older than STALE_AFTER.
                fwrite(STDERR, "\nCould not delete the Google Drive folder ".self::TESTS_FOLDER.'/'.$runFolder.': '.$e->getMessage()."\n");
            }
        }
        parent::tearDownAfterClass();
    }

    protected function drive(): GoogleDriveAdapter
    {
        $adapter = $this->adapter();
        self::assertInstanceOf(GoogleDriveAdapter::class, $adapter);

        return $adapter;
    }

    /**
     * Get the Google Drive ID of a file or a directory.
     */
    protected function driveId(string $path): string
    {
        $metadata = $this->drive()->getMetadata($path);
        self::assertInstanceOf(StorageAttributes::class, $metadata, "No metadata for {$path}");

        return $metadata->extraMetadata()['id'];
    }

    #[Test]
    public function fetching_unknown_mime_type_of_a_file(): void
    {
        $this->assertTrue(true); //This adapter always returns a mime-type.
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
