# Flysystem adapter for Google Drive with seamless virtual<=>display path translation

[![Flysystem API version](https://img.shields.io/badge/Flysystem%20API-V2-blue?style=flat-square)](https://github.com/thephpleague/flysystem/)
[![Latest Version on Packagist](https://img.shields.io/packagist/v/masbug/flysystem-google-drive-ext.svg?style=flat-square)](https://packagist.org/packages/masbug/flysystem-google-drive-ext)
[![License](https://img.shields.io/badge/License-Apache%202.0-blue.svg?style=flat-square)](https://opensource.org/licenses/Apache-2.0)
[![Build Status](https://img.shields.io/travis/com/masbug/flysystem-google-drive-ext/2.x.svg?style=flat-square)](https://travis-ci.com/masbug/flysystem-google-drive-ext)
[![StyleCI](https://styleci.io/repos/113434522/shield?branch=2.x)](https://styleci.io/repos/113434522)
[![Total Downloads](https://img.shields.io/packagist/dt/masbug/flysystem-google-drive-ext.svg?style=flat-square)](https://packagist.org/packages/masbug/flysystem-google-drive-ext)

Google uses unique IDs for each folder and file. This makes it difficult to integrate with other storage services which use normal paths.

This [Flysystem adapter](https://github.com/thephpleague/flysystem) works around that problem by seamlessly translating paths from "display paths" to "virtual paths", and vice versa.

For example: virtual path `/Xa3X9GlR6EmbnY1RLVTk5VUtOVkk/0B3X9GlR6EmbnY1RLVTk5VUtOVkk` becomes `/My Nice Dir/myFile.ext` and all ID handling is hidden.

## Installation

- For **Flysystem V2/V3** or **Laravel >= 9.x.x**

```bash
composer require masbug/flysystem-google-drive-ext
```

- For **Flysystem V1** or **Laravel <= 8.x.x** use 1.x.x version of the package

```bash
composer require masbug/flysystem-google-drive-ext:"^1.0.0"
```

## Getting Google Keys

#### Please follow [Google Docs](https://developers.google.com/drive/v3/web/enable-sdk) to obtain your `client ID, client secret & refresh token`.

#### In addition you can also check these easy-to-follow tutorial by [@ivanvermeyen](https://github.com/ivanvermeyen/laravel-google-drive-demo)

- [Getting your Client ID and Secret](https://github.com/ivanvermeyen/laravel-google-drive-demo/blob/master/README/1-getting-your-dlient-id-and-secret.md)
- [Getting your Refresh Token](https://github.com/ivanvermeyen/laravel-google-drive-demo/blob/master/README/2-getting-your-refresh-token.md)

## Usage

```php
$client = new \Google\Client();
$client->setClientId([client_id]);
$client->setClientSecret([client_secret]);
$client->refreshToken([refresh_token]);
$client->setApplicationName('My Google Drive App');

$service = new \Google\Service\Drive($client);

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

$localAdapter = new \League\Flysystem\Local\LocalFilesystemAdapter('/');
$localfs = new \League\Flysystem\Filesystem($localAdapter, [\League\Flysystem\Config::OPTION_VISIBILITY => \League\Flysystem\Visibility::PRIVATE]);

try {
    $time = Carbon::now();
    $fs->writeStream($remote_filepath, $localfs->readStream($local_filepath), new \League\Flysystem\Config());

    $speed = !(float)$time->diffInSeconds() ? 0 :filesize($local_filepath) / (float)$time->diffInSeconds();
    echo 'Elapsed time: '.$time->diffForHumans(null, true).PHP_EOL;
    echo 'Speed: '. number_format($speed/1024,2) . ' KB/s'.PHP_EOL;
} catch(\League\Flysystem\UnableToWriteFile $e) {
    echo 'UnableToWriteFile!'.PHP_EOL.$e->getMessage();
}

// NOTE: Remote folders are automatically created.
```

##### File download

```php
// Download a file
$remote_filepath = 'MyFolder/file.ext';
$local_filepath = '/home/user/downloads/file.ext';

$localAdapter = new \League\Flysystem\Local\LocalFilesystemAdapter('/');
$localfs = new \League\Flysystem\Filesystem($localAdapter, [\League\Flysystem\Config::OPTION_VISIBILITY => \League\Flysystem\Visibility::PRIVATE]);

try {
    $time = Carbon::now();
    $localfs->writeStream($local_filepath, $fs->readStream($remote_filepath), new \League\Flysystem\Config());

    $speed = !(float)$time->diffInSeconds() ? 0 :filesize($local_filepath) / (float)$time->diffInSeconds();
    echo 'Elapsed time: '.$time->diffForHumans(null, true).PHP_EOL;
    echo 'Speed: '. number_format($speed/1024,2) . ' KB/s'.PHP_EOL;
} catch(\League\Flysystem\UnableToWriteFile $e) {
    echo 'UnableToWriteFile!'.PHP_EOL.$e->getMessage();
}
```

##### How to get TeamDrive list and IDs

```php
$drives = $fs->getAdapter()->getService()->teamdrives->listTeamdrives()->getTeamDrives();
foreach ($drives as $drive) {
    echo 'TeamDrive: ' . $drive->name . PHP_EOL;
    echo 'ID: ' . $drive->id . PHP_EOL. PHP_EOL;
}
```

##### How permanently deletes all of the user's trashed files
```php
$fs->getAdapter()->emptyTrash([]);
```

## Using with Laravel Framework

##### Update `.env` file with google keys

Add the keys you created to your `.env` file and set `google` as your default cloud storage. You can copy the `.env.example` file and fill in the blanks.

```
FILESYSTEM_CLOUD=google
GOOGLE_DRIVE_CLIENT_ID=xxx.apps.googleusercontent.com
GOOGLE_DRIVE_CLIENT_SECRET=xxx
GOOGLE_DRIVE_REFRESH_TOKEN=xxx
GOOGLE_DRIVE_FOLDER=
#GOOGLE_DRIVE_TEAM_DRIVE_ID=xxx

# you can use more accounts, only add more configs
#SECOND_GOOGLE_DRIVE_CLIENT_ID=xxx.apps.googleusercontent.com
#SECOND_GOOGLE_DRIVE_CLIENT_SECRET=xxx
#SECOND_GOOGLE_DRIVE_REFRESH_TOKEN=xxx
#SECOND_GOOGLE_DRIVE_FOLDER=backups
#SECOND_DRIVE_TEAM_DRIVE_ID=xxx
```

##### Add disks on `config/filesystems.php`

```php
'disks' => [
    // ...
    'google' => [
        'driver' => 'google',
        'clientId' => env('GOOGLE_DRIVE_CLIENT_ID'),
        'clientSecret' => env('GOOGLE_DRIVE_CLIENT_SECRET'),
        'refreshToken' => env('GOOGLE_DRIVE_REFRESH_TOKEN'),
        'folder' => env('GOOGLE_DRIVE_FOLDER'), // without folder is root of drive or team drive
        //'teamDriveId' => env('GOOGLE_DRIVE_TEAM_DRIVE_ID'),
    ],
    // you can use more accounts, only add more disks and configs on .env
    // also you can use the same account and point to a diferent folders for each disk
    /*'second_google' => [
        'driver' => 'google',
        'clientId' => env('SECOND_GOOGLE_DRIVE_CLIENT_ID'),
        'clientSecret' => env('SECOND_GOOGLE_DRIVE_CLIENT_SECRET'),
        'refreshToken' => env('SECOND_GOOGLE_DRIVE_REFRESH_TOKEN'),
        'folder' => env('SECOND_GOOGLE_DRIVE_FOLDER'),
    ],*/
    // ...
],
```

##### Add driver storage in a `ServiceProvider` on path `app/Providers/`

Example:

```php
namespace App\Providers;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider { // can be a custom ServiceProvider
    // ...
    public function boot(){
        // ...
        try {
            \Storage::extend('google', function($app, $config) {
                $options = [];

                if (!empty($config['teamDriveId'] ?? null)) {
                    $options['teamDriveId'] = $config['teamDriveId'];
                }

                $client = new \Google\Client();
                $client->setClientId($config['clientId']);
                $client->setClientSecret($config['clientSecret']);
                $client->refreshToken($config['refreshToken']);
                
                $service = new \Google\Service\Drive($client);
                $adapter = new \Masbug\Flysystem\GoogleDriveAdapter($service, $config['folder'] ?? '/', $options);
                $driver = new \League\Flysystem\Filesystem($adapter);

                return new \Illuminate\Filesystem\FilesystemAdapter($driver, $adapter);
            });
        } catch(\Exception $e) {
            // your exception handling logic
        }
        // ...
    }
    // ...
}
```

Now you can access the drives like so:

```php
$googleDisk = Storage::disk('google');
//$secondDisk = Storage::disk('second_google'); //others disks
```

Keep in mind that there can only be one default cloud storage drive, defined by `FILESYSTEM_CLOUD` in your `.env` (or config) file. If you set it to `google`, that will be the cloud drive:

```php
Storage::cloud(); // refers to Storage::disk('google')
```

## Limitations

Using display paths as identifiers for folders and files requires them to be unique. Unfortunately Google Drive allows users to create files and folders with same (displayed) names. In such cases when unique path cannot be determined this adapter chooses the oldest (first) instance.
In case the newer duplicate is a folder and user puts a unique file or folder inside the adapter will be able to reach it properly (because full path is unique).

Concurrent use of same Google Drive might lead to unexpected problems due to heavy caching of file/folder identifiers and file objects.

## Acknowledgements

This adapter is based on wonderful [flysystem-google-drive](https://github.com/nao-pon/flysystem-google-drive) by Naoki Sawada.

It also contains an adaptation of [Google_Http_MediaFileUpload](https://github.com/googleapis/google-api-php-client/blob/master/src/Http/MediaFileUpload.php) by Google. I've added support for resumable uploads directly from streams (avoiding copying data to memory).

TeamDrive support was implemented by Maximilian Ruta - [Deltachaos](https://github.com/Deltachaos).

Adapter rewrite for Flysystem V2 and various fixes were implemented by Erik Niebla - [erikn69](https://github.com/erikn69).
