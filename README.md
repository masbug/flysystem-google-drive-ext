# Flysystem adapter for Google Drive with seamless virtual<=>display path translation

[![Latest Version on Packagist](https://img.shields.io/packagist/v/masbug/flysystem-google-drive-ext.svg?style=flat-square)](https://packagist.org/packages/masbug/flysystem-google-drive-ext)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg)](https://opensource.org/licenses/Apache-2.0)
[![Build Status](https://img.shields.io/travis/masbug/flysystem-google-drive-ext/master.svg?style=flat-square)](https://travis-ci.org/masbug/flysystem-google-drive-ext)
[![Total Downloads](https://img.shields.io/packagist/dt/masbug/flysystem-google-drive-ext.svg?style=flat-square)](https://packagist.org/packages/masbug/flysystem-google-drive-ext)

Google uses unique IDs for each folder and file. This makes it difficult to integrate with other storage services which use normal paths.

This [Flysystem adapter](https://github.com/thephpleague/flysystem) works around that problem by seamlessly translating paths from "display paths" to "virtual paths", and vice versa.

For example: virtual path `/Xa3X9GlR6EmbnY1RLVTk5VUtOVkk/0B3X9GlR6EmbnY1RLVTk5VUtOVkk` becomes `/My Nice Dir/myFile.ext` and all ID handling is hidden.

## Installation

```bash
composer require masbug/flysystem-google-drive-ext
```

## Usage
#### Please follow [Google Docs](https://developers.google.com/drive/v3/web/enable-sdk) to obtain your `client ID, client secret & refresh token`.

```php
$client = new \Google_Client();
$client->setClientId([client_id]);
$client->setClientSecret([client_secret]);
$client->refreshToken([refresh_token]);
$client->setApplicationName('My Google Drive App');

$service = new \Google_Service_Drive($client);

// variant 1
$adapter = new \Masbug\Flysystem\GoogleDriveAdapter($service, 'My_App_Root');

// variant 2: with extra options and query parameters
$adapter2 = new \Masbug\Flysystem\GoogleDriveAdapter(
    $service,
    'My_App_Root',
    [
        'useDisplayPaths' => true, /* this is the default */

        /* These are global parameters sent to server along with per API parameters. Please see https://developers.google.com/drive/api/v3/query-parameters for more info. */
        'parameters' => [
            /* This example tells the remote server to perform quota checks per unique user id. Otherwise the quota would be per client IP. */
            'quotaUser' => (string)$some_unique_per_user_id
        ]
    ]
);

// variant 3: connect to team drive
$adapter3 = new \Masbug\Flysystem\GoogleDriveAdapter(
    $service,
    'My_App_Root',
    [
        'teamDriveId' => '0GF9IioKDqJsRGk9PVA'
    ]
);

$fs = new \League\Flysystem\Filesystem($adapter, new \League\Flysystem\Config([\League\Flysystem\Config::OPTION_VISIBILITY => \League\Flysystem\Visibility::PRIVATE]));
```
```php
// List selected root folder contents
$contents = $fs->listContents('', true /* is_recursive */);

// List specific folder contents
$contents = $fs->listContents('MyFolder', true /* is_recursive */);
```

##### File upload
```php
// Upload a file
$local_filepath = '/home/user/downloads/file_to_upload.ext';
$remote_filepath = 'MyFolder/file.ext';

$localAdapter = new League\Flysystem\Local\LocalFilesystemAdapter();
$localfs = new \League\Flysystem\Filesystem($localAdapter, new \League\Flysystem\Config([\League\Flysystem\Config::OPTION_VISIBILITY => \League\Flysystem\Visibility::PRIVATE]));

try {
    $time = Carbon::now();
    $ret = $fs->writeStream($remote_filepath, $localfs->readStream($local_filepath));
    if($ret) {
        $speed = filesize($local_filepath) / (float)$time->diffInSeconds();
        echo 'Elapsed time: '.$time->diffForHumans(null, true);
        echo 'Speed: '. number_format($speed/1024,2) . ' KB/s';
    } else {
        echo 'Upload FAILED!';
    }
} catch(\League\Flysystem\UnableToReadFile $e) {
    echo 'Source doesn\'t exist!';
}

// NOTE: Remote folders are automatically created. 
```

##### File download
```php
// Download a file
$remote_filepath = 'MyFolder/file.ext';
$local_filepath = '/home/user/downloads/file.ext';

$localAdapter = new League\Flysystem\Local\LocalFilesystemAdapter();
$localfs = new \League\Flysystem\Filesystem($localAdapter, new \League\Flysystem\Config([\League\Flysystem\Config::OPTION_VISIBILITY => \League\Flysystem\Visibility::PRIVATE])));

try {
    $time = Carbon::now();
    $ret = $localfs->writeStream($local_filepath, $fs->readStream($remote_filepath));
    if($ret) {
        $speed = filesize($local_filepath) / (float)$time->diffInSeconds();
        echo 'Elapsed time: '.$time->diffForHumans(null, true);
        echo 'Speed: '. number_format($speed/1024,2) . ' KB/s';
    } else {
        echo 'Downloaded FAILED!';
    }
} catch(\League\Flysystem\UnableToReadFile $e) {
    echo 'Source doesn\'t exist!';
}
```

##### How to get TeamDrive list and IDs
```php
$client = new \Google_Client();
$client->setClientId([client_id]);
$client->setClientSecret([client_secret]);
$client->refreshToken([refresh_token]);
$client->setApplicationName('My Google Drive App');

$service = new \Google_Service_Drive($client);

$drives = $service->teamdrives->listTeamdrives()->getTeamDrives();
foreach ($drives as $drive) {
    echo 'TeamDrive: ' . $drive->name . PHP_EOL;
    echo 'ID: ' . $drive->id . PHP_EOL;
}

```
## Limitations

Using display paths as identifiers for folders and files requires them to be unique. Unfortunately Google Drive allows users to create files and folders with same (displayed) names. In such cases when unique path cannot be determined this adapter chooses the oldest (first) instance.
In case the newer duplicate is a folder and user puts a unique file or folder inside the adapter will be able to reach it properly (because full path is unique).

Concurrent use of same Google Drive might lead to unexpected problems due to heavy caching of file/folder identifiers and file objects.   

## Acknowledgements
This adapter is based on wonderful [flysystem-google-drive](https://github.com/nao-pon/flysystem-google-drive) by Naoki Sawada.

It also contains an adaptation of [Google_Http_MediaFileUpload](https://github.com/google/google-api-php-client/blob/master/src/Google/Http/MediaFileUpload.php) by Google. I've added support for resumable uploads directly from streams (avoiding copying data to memory). 

TeamDrive support was implemented by Maximilian Ruta - [Deltachaos](https://github.com/Deltachaos).
