<?php
namespace Masbug\Flysystem;

use PHPUnit\Framework\TestCase;
use League\Flysystem\Config;

class GoogleDriveAdapterTests extends TestCase
{
    /**
     * @var GoogleDriveAdapter
     */
    protected $adapter;

    public function setup(): void
    {
        $this->adapter = self::createFilesystemAdapter();
    }

    protected static function createFilesystemAdapter()
    {
        $file=__DIR__ .'/../google-drive-service-account.json';
        if ( ! file_exists($file)) {
            self::markTestSkipped("No google service account file {$file} found in project root.");
        }
        try{
            $config=json_decode(file_get_contents($file),true);
            if(!$config)
                self::markTestSkipped("Format json error in {$file}.");
            if( (!isset($config['GOOGLE_DRIVE_CLIENT_ID'])||empty($config['GOOGLE_DRIVE_CLIENT_ID']))
                ||(!isset($config['GOOGLE_DRIVE_CLIENT_SECRET'])||empty($config['GOOGLE_DRIVE_CLIENT_SECRET']))
                ||(!isset($config['GOOGLE_DRIVE_REFRESH_TOKEN'])||empty($config['GOOGLE_DRIVE_REFRESH_TOKEN']))
            )
                self::markTestSkipped("No google service config found in {$file}.");
            $options = [];
            if(isset($config['teamDriveId'])&&!empty($config['teamDriveId'])) $options['teamDriveId']=$config['teamDriveId'];
            $client = new \Google_Client();
            $client->setClientId($config['GOOGLE_DRIVE_CLIENT_ID']);
            $client->setClientSecret($config['GOOGLE_DRIVE_CLIENT_SECRET']);
            $client->refreshToken($config['GOOGLE_DRIVE_REFRESH_TOKEN']);
            $service = new \Google_Service_Drive($client);
            return new GoogleDriveAdapter($service,'/', $options);
        }catch(\Exception $e){
            self::markTestSkipped($e->getMessage());
        }
    }

    private function getFiles($name='/'){
        $files = [];
        $contents = $this->adapter->listContents('',false);
        foreach ($contents as $value) {
            if($value['path']==$name)
                $files[] = $value;
        }
        return $files;
    }

    /**
     * @test
     */
    public function test_listing(): void
    {
        try{
            $folders=$this->getFiles();
            $this->assertTrue(is_array($folders));
        }catch(\Exception $e){
            self::markTestSkipped($e->getMessage());
        }
    }

    /**
     * @test
     */
    public function test_creating_deleting_a_directory(): void
    {
        $dir = 'test_directory';
        // Creating
        $this->adapter->createDir($dir, new Config());
        // Creating a directory should be idempotent.
        $this->adapter->createDir($dir, new Config());
        $folders = $this->getFiles($dir);
        $this->assertTrue(isset($folders[0])&&$folders[0]['path']==$dir,"The directory {$dir} was not created");
        $this->assertCount(1, $folders, "Two directorys with the name {$dir} or not exists");
        $this->assertEquals('dir', isset($folders[0])?$folders[0]['type']:'',"The type of {$dir} was not directory");
        // Deleting
        $this->adapter->deleteDir($dir);
        $folder = $this->getFiles($dir);
        $this->assertCount(0, $folder, "The directory {$dir} is not deleted");

    }

    /**
     * @test
     */
    public function test_writing_copying_reading_deleting_file_with_string(): void
    {
        $file='test_file.txt';
        $destination='test_file_copy.txt';
        // Writing
        $this->adapter->write($file, 'contents', new Config());
        $files = $this->getFiles($file);
        $this->assertTrue(isset($files[0])&&$files[0]['path']==$file,"The file {$file} was not created");
        // Coping
        $this->adapter->copy($file, $destination, new Config());
        $copy = $this->getFiles($destination);
        $this->assertTrue(isset($copy[0])&&$copy[0]['path']==$destination,"The copy {$destination} was not created");
        // Reading
        $contents = $this->adapter->read($destination);
        $this->assertEquals('contents', is_array($contents)&&isset($contents['contents'])?$contents['contents']:'',"The content of file {$destination} is wrong");
        // Deleting
        $this->adapter->delete($destination);
        $this->adapter->delete($file);
        $delete = $this->getFiles($file);
        $this->assertCount(0, $delete, "The file {$file} was not deleted");
    }

}
