<?php
namespace Masbug\Flysystem;

use League\Flysystem\Config;
use PHPUnit\Framework\TestCase;
use Throwable;

class GoogleDriveAdapterTests extends TestCase
{
    /**
     * @var GoogleDriveAdapter
     */
    protected static $adapter;

    /**
     * @var string
     */
    private static $adapterPrefix = 'ci';

    public static function setUpBeforeClass(): void
    {
        static::$adapterPrefix = 'ci/'.bin2hex(random_bytes(10));
    }

    public static function clearFilesystemAdapterCache(): void
    {
        static::$adapter = null;
    }

    public function adapter()
    {
        if (!static::$adapter instanceof GoogleDriveAdapter) {
            static::$adapter = static::createFilesystemAdapter();
        }

        return static::$adapter;
    }

    public static function tearDownAfterClass(): void
    {
        self::clearFilesystemAdapterCache();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->adapter();
    }

    /**
     * @after
     */
    public function cleanupAdapter(): void
    {
        try {
            $adapter = $this->adapter();
        } catch (Throwable $exception) {
            /*
             * Setting up the filesystem adapter failed. This is OK at this stage.
             * The exception will have been shown to the user when trying to run
             * a test. We expect an exception to be thrown when tests are marked as
             * skipped when a filesystem adapter cannot be constructed.
             */
            return;
        }

        /** @var StorageAttributes $item */
        foreach ($adapter->listContents('', false) as $item) {
            if ($item['type'] == 'dir') {
                $adapter->deleteDir($item['display_path']);
            } else {
                $adapter->delete($item['display_path']);
            }
        }
    }

    protected static function createFilesystemAdapter()
    {
        $file = __DIR__.'/../google-drive-service-account.json';
        if (!file_exists($file)) {
            self::markTestSkipped("No google service account file {$file} found in project root.");
        }
        try {
            $config = json_decode(file_get_contents($file), true);
            if (!$config) {
                self::markTestSkipped("Format json error in {$file}.");
            }
            if (empty($config['GOOGLE_DRIVE_CLIENT_ID'] ?? null) ||
                empty($config['GOOGLE_DRIVE_CLIENT_SECRET'] ?? null) ||
                empty($config['GOOGLE_DRIVE_REFRESH_TOKEN'] ?? null)
            ) {
                self::markTestSkipped("No google service config found in {$file}.");
            }
            $options = [];
            if (!empty($config['teamDriveId'] ?? null)) {
                $options['teamDriveId'] = $config['teamDriveId'];
            }
            $client = new \Google_Client();
            $client->setClientId($config['GOOGLE_DRIVE_CLIENT_ID']);
            $client->setClientSecret($config['GOOGLE_DRIVE_CLIENT_SECRET']);
            $client->refreshToken($config['GOOGLE_DRIVE_REFRESH_TOKEN']);
            $service = new \Google_Service_Drive($client);
            return new GoogleDriveAdapter($service, 'tests/', $options);
        } catch (\Exception $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    /**
     * @test
     */
    public function testHasWithDirAndFile()
    {
        $adapter = $this->adapter();
        $adapter->createDir('0', new Config());
        $this->assertTrue($adapter->has('0'));
        $adapter->write('0/file.txt', 'content', new Config());
        $this->assertTrue($adapter->has('0/file.txt'));
    }

    /**
     * @test
     */
    public function testCopy()
    {
        $adapter = $this->adapter();
        $file = 'file.ext';
        $destination = 'test_file_copy.txt';
        // create
        $adapter->write($file, 'content', new Config(['visibility' => 'public']));
        $this->assertTrue($adapter->has($file));
        $object = $adapter->getVisibility($file);
        $this->assertIsArray($object);
        $this->assertArrayHasKey('visibility', $object);
        $this->assertEquals('public', $object['visibility']);
        // copy
        $this->assertTrue($adapter->copy($file, $destination));
        $this->assertTrue($adapter->has($destination));
        // copy of content
        $contents = $adapter->read($destination);
        $this->assertEquals('content', is_array($contents) && isset($contents['contents']) ? $contents['contents'] : '', "The content of file {$destination} is wrong");
        // copy of visibility
        $public = $adapter->getVisibility($destination);
        $this->assertIsArray($public);
        $this->assertArrayHasKey('visibility', $public);
        $this->assertEquals('public', $public['visibility']);
        // delete
        $adapter->delete($file);
        $this->assertFalse($adapter->has($file));
    }

    /**
     * @test
     */
    public function testRename(): void
    {
        $adapter = $this->adapter();
        $file = 'file1.txt';
        $destination = 'dir/file2.txt';
        $adapter->write($file, 'content', new Config(['visibility' => 'public']));
        $this->assertTrue($adapter->has($file));
        // rename
        $adapter->rename($file, $destination);
        $this->assertTrue($adapter->has($destination));
        $this->assertFalse($adapter->has($file));
        // visibility
        $object = $adapter->getVisibility($destination);
        $this->assertIsArray($object);
        $this->assertArrayHasKey('visibility', $object);
        $this->assertEquals('public', $object['visibility']);
        // content
        $contents = $adapter->read($destination);
        $this->assertEquals('content', is_array($contents) && isset($contents['contents']) ? $contents['contents'] : '', "The content of file {$destination} is wrong");
    }

    /**
     * @test
     */
    public function testUpdateSetsNewVisibility()
    {
        $adapter = $this->adapter();
        $file = 'file_update.txt';
        // create
        $adapter->write($file, 'old contents', new Config(['visibility' => 'public']));
        $this->assertEquals('old contents', $adapter->read($file)['contents']);
        $this->assertEquals('public', $adapter->getVisibility($file)['visibility']);
        // update and change visibility
        $adapter->update($file, 'new contents', new Config(['visibility' => 'private']));
        $this->assertEquals('new contents', $adapter->read($file)['contents']);
        $this->assertEquals('private', $adapter->getVisibility($file)['visibility']);
    }

    /**
     * @test
     */
    public function testListContents()
    {
        $adapter = $this->adapter();
        $adapter->write('dirname/file.txt', 'contents', new Config());
        $contents = $adapter->listContents('dirname', false);
        $this->assertIsArray($contents);
        $this->assertCount(1, $contents);
        $this->assertArrayHasKey('type', $contents[0]);
    }

    /**
     * @test
     */
    public function testReadWriteStream()
    {
        $adapter = $this->adapter();
        // read stream
        $file = 'dir/file_read.txt';
        $adapter->write($file, 'dummy', new Config());
        $read = $adapter->readStream($file);
        $this->assertIsArray($read);
        $this->assertArrayHasKey('stream', $read);
        $this->assertIsResource($read['stream']);
        // write stream
        $destination = 'dir/file_write.txt';
        $adapter->writeStream($destination, $read['stream'], new Config(['visibility' => 'public']));
        $this->assertTrue($adapter->has($destination));
        $write = $adapter->read($destination);
        $this->assertIsArray($write);
        $this->assertArrayHasKey('contents', $write);
        $this->assertEquals('dummy', $write['contents']);
        // visibilyty
        $public = $adapter->getVisibility($destination);
        $this->assertIsArray($public);
        $this->assertArrayHasKey('visibility', $public);
        $this->assertEquals('public', $public['visibility']);
    }

    /**
     * @test
     */
    public function testUpdateStream()
    {
        $adapter = $this->adapter();
        $file = 'file.txt';
        $adapter->write($file, 'initial', new Config());
        $source = 'dir/file_read.txt';
        $adapter->write($source, 'dummy', new Config());
        $this->assertTrue($adapter->has($source));
        $read = $adapter->readStream($source);
        $adapter->updateStream($file, $read['stream'], new Config());
        $this->assertTrue($adapter->has($file));
        $write = $adapter->read($file);
        $this->assertIsArray($write);
        $this->assertArrayHasKey('contents', $write);
        $this->assertEquals('dummy', $write['contents']);
    }

    /**
     * @test
     */
    public function testRenameToNonExistsingDirectory()
    {
        $adapter = $this->adapter();
        $adapter->write('file.txt', 'contents', new Config());
        $dirname = uniqid();
        $this->assertFalse($adapter->has($dirname));
        $this->assertTrue($adapter->rename('file.txt', $dirname.'/file.txt'));
    }

    /**
     * @test
     */
    public function testListingNonexistingDirectory()
    {
        $adapter = $this->adapter();
        $result = $adapter->listContents('nonexisting/directory');
        $this->assertEquals([], $result);
    }

    /**
     * @test
     */
    public function testListContentsRecursive()
    {
        $adapter = $this->adapter();
        $adapter->write('dirname/file.txt', 'contents', new Config());
        $adapter->write('dirname/other.txt', 'contents', new Config());
        $contents = $adapter->listContents('', true);
        $this->assertCount(3, $contents);
    }

    /**
     * @test
     */
    public function testGetSize()
    {
        $adapter = $this->adapter();
        $adapter->write('dummy.txt', '1234', new Config());
        $result = $adapter->getSize('dummy.txt');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('size', $result);
        $this->assertEquals(4, $result['size']);
    }

    /**
     * @test
     */
    public function testGetTimestamp()
    {
        $adapter = $this->adapter();
        $adapter->write('dummy.txt', '1234', new Config());
        $result = $adapter->getTimestamp('dummy.txt');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('timestamp', $result);
        $this->assertIsInt($result['timestamp']);
    }

    /**
     * @test
     */
    public function testGetMimetype()
    {
        $adapter = $this->adapter();
        $adapter->write('text.txt', 'contents', new Config());
        $result = $adapter->getMimetype('text.txt');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('mimetype', $result);
        $this->assertEquals('text/plain', $result['mimetype']);
    }

    /**
     * @test
     */
    public function testCreateDirAndVisibility()
    {
        $adapter = $this->adapter();
        $dir = 'test-dir';
        // create dir
        $adapter->createDir($dir, new Config());
        $this->assertTrue($adapter->has($dir));
        $ditectory = $adapter->getMetadata($dir);
        $this->assertIsArray($ditectory);
        $this->assertArrayHasKey('type', $ditectory);
        $this->assertEquals('dir', $ditectory['type']);
        // default visibility
        $output = $adapter->getVisibility($dir);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('visibility', $output);
        $this->assertEquals('private', $output['visibility']);
        // public
        $adapter->setVisibility($dir, 'public');
        $public = $adapter->getVisibility($dir);
        $this->assertIsArray($public);
        $this->assertArrayHasKey('visibility', $public);
        $this->assertEquals('public', $public['visibility']);
        // private again
        $adapter->setVisibility($dir, 'private');
        $private = $adapter->getVisibility($dir);
        $this->assertIsArray($private);
        $this->assertArrayHasKey('visibility', $private);
        $this->assertEquals('private', $private['visibility']);
    }

    /**
     * @test
     */
    public function testCreateDirWithPoint()
    {
        $adapter = $this->adapter();
        $dirname = 'fail.plz';
        $adapter->createDir($dirname, new Config());
        $this->assertTrue($adapter->has($dirname));
    }

    /**
     * @test
     */
    public function testDeleteDir()
    {
        $adapter = $this->adapter();
        $adapter->write('nested/dir/path.txt', 'contents', new Config());
        $this->assertTrue($adapter->has('nested/dir/path.txt'));
        $adapter->deleteDir('nested');
        $this->assertFalse($adapter->has('nested/dir/path.txt'));
    }

    /**
     * @test
     */
    public function testVisibilityFile()
    {
        $adapter = $this->adapter();
        $file = 'path.txt';
        // create file
        $adapter->write($file, 'contents', new Config());
        $this->assertTrue($adapter->has('path.txt'));
        // default visibility
        $output = $adapter->getVisibility($file);
        $this->assertIsArray($output);
        $this->assertArrayHasKey('visibility', $output);
        $this->assertEquals('private', $output['visibility']);
        // public
        $adapter->setVisibility($file, 'public');
        $public = $adapter->getVisibility($file);
        $this->assertIsArray($public);
        $this->assertArrayHasKey('visibility', $public);
        $this->assertEquals('public', $public['visibility']);
        // private again
        $adapter->setVisibility($file, 'private');
        $private = $adapter->getVisibility($file);
        $this->assertIsArray($private);
        $this->assertArrayHasKey('visibility', $private);
        $this->assertEquals('private', $private['visibility']);
    }

    /**
     * @test
     */
    public function testWritingReadingFilesWithSpecialPath()
    {
        $adapter = $this->adapter();
        foreach ([
            'a path with square brackets in filename 1' => 'some/file[name].txt',
            'a path with square brackets in filename 2' => 'some/file[0].txt',
            'a path with square brackets in filename 3' => 'some/file[10].txt',
            'a path with square brackets in dirname 1' => 'some[name]/file.txt',
            'a path with square brackets in dirname 2' => 'some[0]/file.txt',
            'a path with square brackets in dirname 3' => 'some[10]/file.txt',
            'a path with curly brackets in filename 1' => 'some/file{name}.txt',
            'a path with curly brackets in filename 2' => 'some/file{0}.txt',
            'a path with curly brackets in filename 3' => 'some/file{10}.txt',
            'a path with curly brackets in dirname 1' => 'some{name}/filename.txt',
            'a path with curly brackets in dirname 2' => 'some{0}/filename.txt',
            'a path with curly brackets in dirname 3' => 'some{10}/filename.txt',
            'a path with space in dirname' => 'some dir/filename.txt',
            'a path with space in filename' => 'somedir/file name.txt'
        ] as $msg => $path) {

            $adapter->write($path, 'contents', new Config());
            $contents = $adapter->read($path);

            $this->assertEquals('contents', isset($contents['contents']) ? $contents['contents'] : '', $msg);
        }
    }

    /**
     * @test
     */
    public function testVisibilityFail()
    {
        $adapter = $this->adapter();
        $this->assertFalse(
            $adapter->setVisibility('chmod.fail', 'public')
        );
    }

    /**
     * @test
     */
    public function testMimetypeFallbackOnExtension()
    {
        $adapter = $this->adapter();
        $file = 'test.xlsx';
        $adapter->write($file, '', new Config());
        $this->assertEquals('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $adapter->getMimetype($file)['mimetype']);
    }

    /**
     * @test
     */
    public function testDeleteFileShouldReturnTrue()
    {
        $adapter = $this->adapter();
        $file = 'delete.ext';
        $adapter->write('delete.ext', 'content', new Config());
        $this->assertTrue($adapter->delete($file));
    }

    /**
     * @test
     */
    public function testDeleteMissingFileShouldReturnFalse()
    {
        $adapter = $this->adapter();
        $this->assertFalse($adapter->delete('missing.txt'));
    }
}
