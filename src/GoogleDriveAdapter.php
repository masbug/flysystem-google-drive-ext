<?php

declare(strict_types=1);

namespace Masbug\Flysystem;

use Exception;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use Google_Service_Drive_FileList;
use Google_Service_Drive_Permission;
use GuzzleHttp\Psr7;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\PathPrefixer;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;
use Throwable;

define('DEBUG_ME', false);

class GoogleDriveAdapter implements FilesystemAdapter
{
    /**
     * Maximum size of upload content before switching from "one shot" mode to "chunked upload"
     *
     * @var int
     */
    const MAX_CHUNK_SIZE = 100 * 1024 * 1024;

    /**
     * Minimum time [s] before allowing already cached file object to be refreshed
     *
     * @var int
     */
    const FILE_OBJECT_MINIMUM_VALID_TIME = 10;

    /**
     * Fetch fields setting for list
     *
     * @var string
     */
    const FETCHFIELDS_LIST = 'files(id,mimeType,createdTime,modifiedTime,name,parents,permissions,size,webContentLink),nextPageToken';

    /**
     * Fetch fields setting for get
     *
     * @var string
     */
    const FETCHFIELDS_GET = 'id,name,mimeType,createdTime,modifiedTime,parents,permissions,size,webContentLink,webViewLink';

    /**
     * MIME tyoe of directory
     *
     * @var string
     */
    const DIRMIME = 'application/vnd.google-apps.folder';

    /**
     * Google_Service_Drive instance
     *
     * @var Google_Service_Drive
     */
    protected $service;

    /**
     * Default options
     *
     * @var array
     */
    protected static $defaultOptions = [
        'spaces'            => 'drive',
        'useHasDir'         => false,
        'useDisplayPaths'   => true,
        'publishPermission' => [
            'type'     => 'anyone',
            'role'     => 'reader',
            'withLink' => true
        ],
        'appsExportMap'     => [
            'application/vnd.google-apps.document'     => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.google-apps.spreadsheet'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.google-apps.drawing'      => 'application/pdf',
            'application/vnd.google-apps.presentation' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'application/vnd.google-apps.script'       => 'application/vnd.google-apps.script+json',
            'default'                                  => 'application/pdf'
        ],

        'parameters' => [],

        'teamDriveId' => null,

        'sanitize_chars' => [
            // sanitize filename
            // file system reserved https://en.wikipedia.org/wiki/Filename#Reserved_characters_and_words
            // control characters http://msdn.microsoft.com/en-us/library/windows/desktop/aa365247%28v=vs.85%29.aspx
            // non-printing characters DEL, NO-BREAK SPACE, SOFT HYPHEN
            // URI reserved https://tools.ietf.org/html/rfc3986#section-2.2
            // URL unsafe characters https://www.ietf.org/rfc/rfc1738.txt

            // must not allow
            '/', '\\', '?', '%', '*', ':', '|', '"', '<', '>',
            '\x00', '\x01', '\x02', '\x03', '\x04', '\x05', '\x06', '\x07', '\x08', '\x09', '\x0A', '\x0B', '\x0C', '\x0D', '\x0E', '\x0F',
            '\x10', '\x11', '\x12', '\x13', '\x14', '\x15', '\x16', '\x17', '\x18', '\x19', '\x1A', '\x1B', '\x1C', '\x1D', '\x1E', '\x1F',
            '\x7F', '\xA0', '\xAD',

            // optional
            '#', '@', '!', '$', '&', '\'', '+', ';', '=',
            '^', '~', '`',
        ],
        'sanitize_replacement_char' => '_'
    ];

    /**
     * A comma-separated list of spaces to query
     * Supported values are 'drive', 'appDataFolder' and 'photos'
     *
     * @var string
     */
    protected $spaces;

    /**
     * Root path
     *
     * @var string
     */
    protected $root;

    /**
     * Permission array as published item
     *
     * @var array
     */
    protected $publishPermission;

    /**
     * Cache of file objects
     *
     * @var array
     */
    private $cacheFileObjects = [];

    /**
     * Cache of hasDir
     *
     * @var array
     */
    private $cacheHasDirs = [];

    /**
     * Use hasDir function
     *
     * @var bool
     */
    private $useHasDir = false;

    /**
     * Options array
     *
     * @var array
     */
    private $options = [];

    /**
     * Using display paths instead of virtual IDs
     *
     * @var bool
     */
    private $useDisplayPaths = true;

    /**
     * Resolved root ID
     *
     * @var string
     */
    private $rootId = null;

    /**
     * Full path => virtual ID cache
     *
     * @var array
     */
    private $cachedPaths = [];

    /**
     * Recent virtual ID => file object requests cache
     *
     * @var array
     */
    private $requestedIds = [];

    /**
     * @var array Optional parameters sent with each request (see Google_Service_Resource var stackParameters and https://developers.google.com/analytics/devguides/reporting/core/v4/parameters)
     */
    private $optParams = [];

    /**
     * @var PathPrefixer
     */
    private $prefixer;

    /**
     * GoogleDriveAdapter constructor.
     * @param Google_Service_Drive $service
     * @param string|null          $root
     * @param array                $options
     */
    public function __construct(Google_Service_Drive $service, $root = null, $options = [])
    {
        $this->service = $service;

        $this->options = array_replace_recursive(static::$defaultOptions, $options);

        $this->spaces = $this->options['spaces'];
        $this->useHasDir = $this->options['useHasDir'];
        $this->publishPermission = $this->options['publishPermission'];
        $this->useDisplayPaths = $this->options['useDisplayPaths'];
        $this->optParams = $this->cleanOptParameters($this->options['parameters']);

        if ($root !== null) {
            $root = trim($root, '/');
            if ($root === '') {
                $root = null;
            }
        }

        if (isset($this->options['teamDriveId'])) {
            $this->root = null;
            $this->setTeamDriveId($this->options['teamDriveId']);
            if ($this->useDisplayPaths && $root !== null) {
                // get real root id
                $this->root = $this->toSingleVirtualPath($root, false, true, true, true);

                // reset cache
                $this->rootId = $this->root;
                $this->clearCache();
            }
        } else {
            if (!$this->useDisplayPaths || $root === null) {
                if ($root === null) {
                    $root = 'root';
                }
                $this->root = $root;
                $this->setPathPrefix('');
            } else {
                $this->root = 'root';
                $this->setPathPrefix('');

                // get real root id
                $this->root = $this->toSingleVirtualPath($root, false, true, true, true);

                // reset cache
                $this->rootId = $this->root;
                $this->clearCache();
            }
        }
    }

    /**
     * Allow to forcefully clear the cache to enable long running process
     *
     * @return void
     */
    public function clearCache()
    {
        $this->cachedPaths = [];
        $this->requestedIds = [];
        $this->cacheFileObjects = [];
        $this->cacheHasDirs = [];
    }

    protected function cleanOptParameters($parameters)
    {
        $operations = ['files.copy', 'files.create', 'files.delete',
            'files.trash', 'files.get', 'files.list', 'files.update',
            'files.watch'];
        $clean = [];

        foreach ($operations as $operation) {
            $clean[$operation] = [];
            if (isset($parameters[$operation])) {
                $clean[$operation] = $parameters[$operation];
            }
        }

        foreach ($parameters as $key => $value) {
            if (in_array($key, $operations)) {
                unset($parameters[$key]);
            }
        }

        foreach ($operations as $operation) {
            $clean[$operation] = array_merge_recursive($parameters, $clean[$operation]);
        }

        return $clean;
    }

    private function setPathPrefix($prefix)
    {
        $this->prefixer = new PathPrefixer($prefix);
    }

    /**
     * @throws FilesystemException
     */
    public function fileExists(string $path): bool
    {
        try {
            $location = $this->prefixer->prefixPath($path);
            $this->toVirtualPath($location, true, true);
            return true;
        } catch (UnableToReadFile $e) {
            return false;
        }
    }

    private function writeData(string $location, $contents, Config $config)
    {
        $updating = null;
        $path = $this->prefixer->prefixPath($location);
        if ($this->useDisplayPaths) {
            try {
                $virtual_path = $this->toVirtualPath($path, true, false);
                $updating = true; // destination exists
            } catch (UnableToReadFile $e) {
                $updating = false;
                [$parentDir, $fileName] = $this->splitPath($path, false);
                $virtual_path = $this->toSingleVirtualPath($parentDir, false, true, true, true);
                if ($virtual_path === '') {
                    $virtual_path = $fileName;
                } else {
                    $virtual_path .= '/'.$fileName;
                }
            }
            if ($updating && is_array($virtual_path)) {
                // multiple destinations with the same display path -> remove all but the first created & the first gets replaced
                if (count($virtual_path) > 1) {
                    // delete all but first
                    $this->delete_by_id(
                        array_map(
                            function ($p) {
                                return $this->splitPath($p, false)[1];
                            },
                            array_slice($virtual_path, 1)
                        )
                    );
                }
                $virtual_path = $virtual_path[0];
            }
        } else {
            $virtual_path = $path;
        }

        try {
            $result = $this->upload($virtual_path, $contents, $config, $updating);
        } catch (Throwable $e) {
        }
        if (!isset($result) || !$result) {
            throw UnableToWriteFile::atLocation($path, 'Not able to write the file');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function write(string $path, string $resource, Config $config): void
    {
        $this->writeData($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream(string $path, $resource, Config $config): void
    {
        $this->writeData($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function copy(string $location, string $destination, Config $config): void
    {
        $path = $this->prefixer->prefixPath($location);
        $newpath = $this->prefixer->prefixPath($destination);
        if ($this->useDisplayPaths) {
            $srcId = $this->toVirtualPath($path, false, true);
            $newpathDir = self::dirname($newpath);
            if ($this->fileExists($newpathDir)) {
                $this->delete($newpath);
            }
            $toPath = $this->toSingleVirtualPath($newpathDir, false, false, true, true);
            if ($toPath === false) {
                throw UnableToCopyFile::fromLocationTo($path, $newpath);
            }
            if ($toPath === '') {
                $toPath = $this->root;
            }
            $newParentId = $toPath;
            $fileName = basename($newpath);
        } else {
            [, $srcId] = $this->splitPath($path);
            [$newParentId, $fileName] = $this->splitPath($newpath);
        }

        $file = new Google_Service_Drive_DriveFile();
        $file->setName($fileName);
        $file->setParents([
            $newParentId
        ]);

        $newFile = $this->service->files->copy($srcId, $file, $this->applyDefaultParams([
            'fields' => self::FETCHFIELDS_GET
        ], 'files.copy'));

        if ($newFile instanceof Google_Service_Drive_DriveFile) {
            $id = $newFile->getId();
            $this->cacheFileObjects[$id] = $newFile;
            $this->cacheObjects([$id => $newFile]);
            if (isset($this->cacheHasDirs[$srcId])) {
                $this->cacheHasDirs[$id] = $this->cacheHasDirs[$srcId];
            }

            $srcFile = $this->cacheFileObjects[$srcId];
            $permissions = $srcFile->getPermissions();
            $visibility = Visibility::PRIVATE;
            try {
                foreach ($permissions as $permission) {
                    if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                        $visibility = Visibility::PUBLIC;
                        break;
                    }
                }
            } catch (Exception $e) {
                // unnecesary
            }
            if ($config->get('visibility') === Visibility::PUBLIC || $visibility === Visibility::PUBLIC) {
                $this->publish($id);
            } else {
                $this->unPublish($id);
            }
            $this->resetRequest([$id, $newParentId]);
            return;
        }

        throw UnableToCopyFile::fromLocationTo($path, $newpath, 'Unable To Copy File');
    }

    /**
     * @throws UnableToMoveFile
     * @throws FilesystemException
     */
    public function move(string $source, string $destination, Config $config): void
    {
        if (!$this->fileExists($source)) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, new \Exception("File {$source} not exist."));
        }
        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    /**
     * @param string[]|string $ids
     * @return bool
     */
    protected function delete_by_id($ids)
    {
        $deleted = false;
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        foreach ($ids as $id) {
            if ($id !== '' && ($file = $this->getFileObject($id))) {
                if ($file->getParents()) {
                    $file = new Google_Service_Drive_DriveFile();
                    $file->setTrashed(true);
                    if ($this->service->files->update($id, $file, $this->applyDefaultParams([], 'files.update'))) {
                        $this->uncacheId($id);
                        $deleted = true;
                    }
                }
            }
        }
        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $location): void
    {
        if ($location === '' || $location === '/') {
            throw UnableToDeleteDirectory::atLocation($location, 'Unable to delete root');
        } // do not allow deleting root...

        $path = $this->prefixer->prefixPath($location);
        $deleted = false;
        if ($this->useDisplayPaths) {
            try {
                $ids = $this->toVirtualPath($path, false);
                $deleted = $this->delete_by_id($ids);
            } catch (Throwable $exception) {
                $deleted = true;
            }
        } else {
            if (($file = $this->getFileObject($path))) {
                [$parentId, $id] = $this->splitPath($path);
                if (($parents = $file->getParents())) {
                    $file = new Google_Service_Drive_DriveFile();
                    $opts = [];
                    if (count($parents) > 1) {
                        $opts['removeParents'] = $parentId;
                    } else {
                        $file->setTrashed(true);
                    }
                    if ($this->service->files->update($id, $file, $this->applyDefaultParams($opts, 'files.update'))) {
                        $this->uncacheId($id);
                        $deleted = true;
                    }
                }
            }
        }

        if ($deleted) {
            $this->resetRequest('', true);
        } else {
            throw UnableToDeleteFile::atLocation($path, 'Unable to delete file');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDirectory(string $dirname): void
    {
        try {
            $this->delete($dirname);
        } catch (Throwable $e) {
            throw UnableToDeleteDirectory::atLocation($dirname, 'Unable to delete directory');
        }
    }

    /**
     * {@inheritdoc}
     */
    public function createDirectory(string $dirname, Config $config): void
    {
        try {
            $meta = $this->getMetadata($dirname);
        } catch (UnableToReadFile $e) {
            $meta = false;
        }

        if ($meta !== false) {
            return;
        }

        [$pdir, $name] = $this->splitPath($dirname, false);
        if ($this->useDisplayPaths) {
            if ($pdir !== $this->root) {
                $pdir = $this->toSingleVirtualPath($pdir, false, false, true, true); // recursion!
                if ($pdir === false) {
                    throw UnableToCreateDirectory::atLocation($dirname, 'Failed to create dir');
                }
            }
        }

        $folder = $this->createDir($name, $pdir !== '' ? basename($pdir) : $pdir);
        if ($folder !== null) {
            $itemId = $folder->getId();
            $this->cacheFileObjects[$itemId] = $folder;
            $this->cacheHasDirs[$itemId] = false;
            $this->cacheObjects([$itemId => $folder]);
            return;
        }

        throw UnableToCreateDirectory::atLocation($dirname, 'Failed to create dir');
    }

    /**
     * {@inheritdoc}
     */
    public function read(string $location): string
    {
        $path = $this->prefixer->prefixPath($location);
        if ($this->useDisplayPaths) {
            $fileId = $this->toVirtualPath($path, false, true);
        } else {
            [, $fileId] = $this->splitPath($path);
        }
        /** @var RequestInterface $response */
        if (($response = $this->service->files->get($fileId, $this->applyDefaultParams(['alt' => 'media'], 'files.get')))) {
            return (string)$response->getBody();
        }
        throw UnableToReadFile::fromLocation($path, 'Unable To Read File');
    }

    /**
     * {@inheritdoc}
     */
    public function readStream(string $location)
    {
        $path = $this->prefixer->prefixPath($location);
        if ($this->useDisplayPaths) {
            $path = $this->toVirtualPath($path, false, true);
        }

        $redirect = null;
        if (func_num_args() > 1) {
            $redirect = func_get_arg(1);
        }

        if (!$redirect) {
            $redirect = [
                'cnt' => 0,
                'url' => '',
                'token' => '',
                'cookies' => []
            ];
            if (($file = $this->getFileObject($path))) {
                if ($file->getMimeType() === self::DIRMIME) {
                    throw UnableToReadFile::fromLocation($location, 'Unable To Read File');
                }
                $dlurl = $this->getDownloadUrl($file);
                $client = $this->service->getClient();
                /** @var array|string|object $token */
                if ($client->isUsingApplicationDefaultCredentials()) {
                    $token = $client->fetchAccessTokenWithAssertion();
                } else {
                    $token = $client->getAccessToken();
                }
                $access_token = '';
                if (is_array($token)) {
                    if (empty($token['access_token']) && !empty($token['refresh_token'])) {
                        $token = $client->fetchAccessTokenWithRefreshToken();
                    }
                    $access_token = $token['access_token'];
                } else {
                    if (($token = @json_decode($token))) {
                        $access_token = $token->access_token;
                    }
                }
                $redirect = [
                    'cnt' => 0,
                    'url' => '',
                    'token' => $access_token,
                    'cookies' => []
                ];
            }
        } else {
            if ($redirect['cnt'] > 5) {
                throw UnableToReadFile::fromLocation($location, 'Unable To Read File');
            }
            $dlurl = $redirect['url'];
            $redirect['url'] = '';
            $access_token = $redirect['token'];
        }

        if (!empty($dlurl)) {
            $url = parse_url($dlurl);
            $cookies = [];
            if ($redirect['cookies']) {
                foreach ($redirect['cookies'] as $d => $c) {
                    if (strpos($url['host'], $d) !== false) {
                        $cookies[] = $c;
                    }
                }
            }
            if (!empty($access_token)) {
                $query = isset($url['query']) ? '?'.$url['query'] : '';
                $stream = stream_socket_client('ssl://'.$url['host'].':443');
                stream_set_timeout($stream, 300);
                fwrite($stream, "GET {$url['path']}{$query} HTTP/1.1\r\n");
                fwrite($stream, "Host: {$url['host']}\r\n");
                fwrite($stream, "Authorization: Bearer {$access_token}\r\n");
                fwrite($stream, "Connection: Close\r\n");
                if ($cookies) {
                    fwrite($stream, 'Cookie: '.implode('; ', $cookies)."\r\n");
                }
                fwrite($stream, "\r\n");
                while (($res = trim(fgets($stream))) !== '') {
                    // find redirect
                    if (preg_match('/^Location: (.+)$/', $res, $m)) {
                        $redirect['url'] = $m[1];
                    }
                    // fetch cookie
                    if (strpos($res, 'Set-Cookie:') === 0) {
                        $domain = $url['host'];
                        if (preg_match('/^Set-Cookie:(.+)(?:domain=\s*([^ ;]+))?/i', $res, $c1)) {
                            if (!empty($c1[2])) {
                                $domain = trim($c1[2]);
                            }
                            if (preg_match('/([^ ]+=[^;]+)/', $c1[1], $c2)) {
                                $redirect['cookies'][$domain] = $c2[1];
                            }
                        }
                    }
                }
                if ($redirect['url']) {
                    $redirect['cnt']++;
                    fclose($stream);
                    return $this->readStream($path, $redirect);
                }
                return $stream;
            }
        }
        throw UnableToReadFile::fromLocation($location, 'Downloaded object does not contain a file resource.');
    }

    /**
     * {@inheritdoc}
     */
    public function listContents(string $directory, bool $recursive): iterable
    {
        $path = $this->prefixer->prefixPath($directory);
        if ($this->useDisplayPaths) {
            $time = microtime(true);
            $vp = $this->toVirtualPath($path ?: '');
            $elapsed = (microtime(true) - $time) * 1000.0;
            if (!is_array($vp)) {
                $vp = [$vp];
            }

            foreach ($vp as $path) {
                if (DEBUG_ME) {
                    echo 'Converted display path to virtual path ['.number_format($elapsed, 1).'ms]: '.$path."\n";
                }
                foreach (array_values($this->getItems($path, $recursive)) as $item) {
                    yield $item;
                }
            }
        } else {
            foreach (array_values($this->getItems($path, $recursive)) as $item) {
                yield $item;
            }
        }
    }

    /**
     * Get metadata from file/dir
     *
     * @param string $path itemId path
     *
     * @return
     */
    public function getMetadata(string $path)
    {
        if ($this->useDisplayPaths) {
            $path = $this->toVirtualPath($path, true, true);
        }
        if (($obj = $this->getFileObject($path, true))) {
            if ($obj instanceof Google_Service_Drive_DriveFile) {
                return $this->normaliseObject($obj, self::dirname($path));
            }
        }
        return false;
    }

    private function fileAttributes(string $path, string $type = ''): FileAttributes
    {
        $exception = new \Exception('Unable to get metadata');
        $prefixedPath = $this->prefixer->prefixPath($path);

        try {
            $fileAttributes = $this->getMetadata($prefixedPath);
        } catch (Throwable $exception) {
        }

        if (!isset($fileAttributes) || !$fileAttributes instanceof FileAttributes) {
            if (!$type) {
                throw UnableToRetrieveMetadata::create($path, '', $exception);
            } else {
                throw UnableToRetrieveMetadata::$type($path, '', $exception);
            }
        }
        if ($type && $fileAttributes[$type] === null) {
            throw UnableToRetrieveMetadata::{$type}($path, '', $exception);
        }
        return $fileAttributes;
    }

    /**
     * {@inheritdoc}
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->fileAttributes($path, 'fileSize');
    }

    /**
     * {@inheritdoc}
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->fileAttributes($path, 'mimeType');
    }

    /**
     * {@inheritdoc}
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->fileAttributes($path, 'lastModified');
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility(string $path, string $visibility): void
    {
        try {
            if ($this->useDisplayPaths) {
                $path = $this->toVirtualPath($path, false, true);
            }
            $result = ($visibility === Visibility::PUBLIC) ? $this->publish($path) : $this->unPublish($path);
        } catch (Throwable $e) {
            throw UnableToSetVisibility::atLocation($path, 'Error setting visibility', $e);
        }
        if (!$result) {
            $className = Visibility::class;
            throw InvalidVisibilityProvided::withVisibility(
                $visibility,
                "either {$className}::PUBLIC or {$className}::PRIVATE"
            );
        }
    }

    /**
     * {@inheritdoc}
     */
    public function visibility(string $location): FileAttributes
    {
        $path = $this->prefixer->prefixPath($location);
        try {
            if ($this->useDisplayPaths) {
                $path = $this->toVirtualPath($path, false, true);
            }
            $file = $this->getFileObject($path);
        } catch (Throwable $e) {
        }
        if (!isset($file) || !$file) {
            throw UnableToRetrieveMetadata::visibility($location, '', new \Exception('Error finding the file'));
        }
        $permissions = $file->getPermissions();
        $visibility = Visibility::PRIVATE;
        foreach ($permissions as $permission) {
            if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                $visibility = Visibility::PUBLIC;
                break;
            }
        }
        return new FileAttributes($path, null, $visibility);
    }

    // /////////////////- ORIGINAL METHODS -///////////////////

    /**
     * Get contents parmanent URL
     *
     * @param string $path itemId path
     *
     * @return string|false
     */
    public function getUrl($path)
    {
        if ($this->useDisplayPaths) {
            $path = $this->toVirtualPath($path, false, true);
        }
        if ($this->publish($path)) {
            $obj = $this->getFileObject($path);
            if (($url = $obj->getWebContentLink())) {
                return str_replace('export=download', 'export=media', $url);
            }
            if (($url = $obj->getWebViewLink())) {
                return $url;
            }
        }
        return false;
    }

    /**
     * Has child directory
     *
     * @param string $path itemId path
     *
     * @return array
     */
    public function hasDir($path)
    {
        $meta = $this->getMetadata($path)->extraMetadata();
        return (is_array($meta) && isset($meta['hasdir'])) ? $meta : [
            'hasdir' => true
        ];
    }

    /**
     * Do cache cacheHasDirs with batch request
     *
     * @param array $targets [[path => id],...]
     * @param array $object
     * @return array
     */
    protected function setHasDir($targets, $object)
    {
        $service = $this->service;
        $client = $service->getClient();
        $gFiles = $service->files;

        $opts = [
            'pageSize' => 1,
            'orderBy' => 'folder,modifiedTime,name',
        ];

        $paths = [];
        $client->setUseBatch(true);
        $batch = $service->createBatch();
        $i = 0;
        foreach ($targets as $id) {
            $opts['q'] = sprintf('trashed = false and "%s" in parents and mimeType = "%s"', $id, self::DIRMIME);
            /** @var RequestInterface $request */
            $request = $gFiles->listFiles($this->applyDefaultParams($opts, 'files.list'));
            $key = ++$i;
            $batch->add($request, (string)$key);
            $paths['response-'.$key] = $id;
        }
        $results = $batch->execute();
        foreach ($results as $key => $result) {
            if ($result instanceof Google_Service_Drive_FileList) {
                $object[$paths[$key]]['hasdir'] = $this->cacheHasDirs[$paths[$key]] = (bool)$result->getFiles();
            }
        }
        $client->setUseBatch(false);
        return $object;
    }

    /**
     * Publish specified path item
     *
     * @param string $path itemId path
     *
     * @return bool
     */
    protected function publish($path)
    {
        if (($file = $this->getFileObject($path))) {
            $permissions = $file->getPermissions();
            try {
                foreach ($permissions as $permission) {
                    if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                        return true;
                    }
                }
            } catch (Exception $e) {
                // unnecesary
            }
            try {
                $new_permission = new Google_Service_Drive_Permission($this->publishPermission);
                if ($permission = $this->service->permissions->create($file->getId(), $new_permission, $this->applyDefaultParams([], 'files.create'))) {
                    $file->setPermissions([$permission]);
                    return true;
                }
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Un-publish specified path item
     *
     * @param string $path itemId path
     *
     * @return bool
     */
    protected function unPublish($path)
    {
        if (($file = $this->getFileObject($path))) {
            $permissions = $file->getPermissions();
            try {
                foreach ($permissions as $permission) {
                    if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role'] && !empty($file->getId())) {
                        $this->service->permissions->delete($file->getId(), $permission->getId(), $this->applyDefaultParams([], 'files.trash'));
                    }
                }
                $file->setPermissions([]);
                return true;
            } catch (Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Path splits to dirId, fileId or newName
     *
     * @param string $path
     * @param bool   $getParentId True => return only parent id, False => return full path (basically the same as dirname($path))
     * @return array [ $dirId , $fileId|newName ]
     */
    protected function splitPath($path, $getParentId = true)
    {
        if ($path === '' || $path === '/') {
            $fileName = $this->root;
            $dirName = '';
        } else {
            $paths = explode('/', $path);
            $fileName = array_pop($paths);
            if ($getParentId) {
                $dirName = $paths ? array_pop($paths) : '';
            } else {
                $dirName = implode('/', $paths);
            }
            if ($dirName === '') {
                $dirName = $this->root;
            }
        }
        return [
            $dirName,
            $fileName
        ];
    }

    /**
     * Item name splits to filename and extension
     * This function supported include '/' in item name
     *
     * @param string $name
     *
     * @return array [ 'filename' => $filename , 'extension' => $extension ]
     */
    protected function splitFileExtension($name)
    {
        $name_parts = explode('.', $name);
        $extension = isset($name_parts[1]) ? array_pop($name_parts) : '';
        $filename = implode('.', $name_parts);
        return compact('filename', 'extension');
    }

    /**
     * Get normalised files array from Google_Service_Drive_DriveFile
     *
     * @param Google_Service_Drive_DriveFile $object
     * @param string                         $dirname Parent directory itemId path
     *
     * @return \League\Flysystem\StorageAttributes Normalised files array
     */
    protected function normaliseObject(Google_Service_Drive_DriveFile $object, $dirname)
    {
        $id = $object->getId();
        $path_parts = $this->splitFileExtension($object->getName());
        $type = $object->mimeType === self::DIRMIME ? 'dir' : 'file';
        $result = ['id' => $id];
        $visibility = Visibility::PRIVATE;
        $permissions = $object->getPermissions();
        try {
            foreach ($permissions as $permission) {
                if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                    $visibility = Visibility::PUBLIC;
                    break;
                }
            }
        } catch (Exception $e) {
            // unnecesary
        }
        if ($this->useDisplayPaths) {
            $result['virtual_path'] = ($dirname ? ($dirname.'/') : '').$id;
            $result['display_path'] = $this->toDisplayPath($result['virtual_path']);
        } else {
            $result['virtual_path'] = ($dirname ? ($dirname.'/') : '').$id;
            $result['display_path'] = $this->toDisplayPath($result['virtual_path']);
        }

        if ($type === 'file') {
            $result['filename'] = $path_parts['filename'];
            $result['extension'] = $path_parts['extension'];
            return new FileAttributes($result['display_path'], (int)$object->getSize(), $visibility, strtotime($object->getModifiedTime()), $object->mimeType, $result);
        }
        if ($type === 'dir') {
            if ($this->useHasDir) {
                $result['hasdir'] = isset($this->cacheHasDirs[$id]) ? $this->cacheHasDirs[$id] : false;
            }
            return new DirectoryAttributes(rtrim($result['display_path'], '/'), $visibility, strtotime($object->getModifiedTime()), $result);
        }
    }

    /**
     * Get items array of target dirctory
     *
     * @param string $dirname itemId path
     * @param bool   $recursive
     * @param int    $maxResults
     * @param string $query
     *
     * @return array Items array
     */
    protected function getItems($dirname, $recursive = false, $maxResults = 0, $query = '')
    {
        [, $itemId] = $this->splitPath($dirname);

        $maxResults = min($maxResults, 1000);
        $results = [];
        $parameters = [
            'pageSize' => $maxResults ?: 1000,
            'fields' => self::FETCHFIELDS_LIST,
            'orderBy' => 'folder,modifiedTime,name',
            'spaces' => $this->spaces,
            'q' => sprintf('trashed = false and "%s" in parents', $itemId)
        ];
        if ($query) {
            $parameters['q'] .= ' and ('.$query.')';
        }
        $pageToken = null;
        $gFiles = $this->service->files;
        $this->cacheHasDirs[$itemId] = false;
        $setHasDir = [];

        do {
            try {
                if ($pageToken) {
                    $parameters['pageToken'] = $pageToken;
                }
                $fileObjs = $gFiles->listFiles($this->applyDefaultParams($parameters, 'files.list'));
                if ($fileObjs instanceof Google_Service_Drive_FileList) {
                    foreach ($fileObjs as $obj) {
                        $id = $obj->getId();
                        $this->cacheFileObjects[$id] = $obj;
                        $result = $this->normaliseObject($obj, $dirname);
                        $results[$id] = $result;
                        if ($result->isDir()) {
                            if ($this->useHasDir) {
                                $setHasDir[$id] = $id;
                            }
                            if ($this->cacheHasDirs[$itemId] === false) {
                                $this->cacheHasDirs[$itemId] = true;
                                unset($setHasDir[$itemId]);
                            }
                            if ($recursive) {
                                $results = array_merge($results, $this->getItems($result->extraMetadata()['virtual_path'], true, $maxResults, $query));
                            }
                        }
                    }
                    $pageToken = $fileObjs->getNextPageToken();
                } else {
                    $pageToken = null;
                }
            } catch (Exception $e) {
                $pageToken = null;
            }
        } while ($pageToken && $maxResults === 0);

        if ($setHasDir) {
            $results = $this->setHasDir($setHasDir, $results);
        }
        return array_values($results);
    }

    /**
     * Get file oblect Google_Service_Drive_DriveFile
     *
     * @param string $path     itemId path
     * @param bool   $checkDir do check hasdir
     *
     * @return Google_Service_Drive_DriveFile|null
     */
    public function getFileObject($path, $checkDir = false)
    {
        [, $itemId] = $this->splitPath($path);
        if (isset($this->cacheFileObjects[$itemId])) {
            return $this->cacheFileObjects[$itemId];
        }

        $service = $this->service;
        $client = $service->getClient();

        $client->setUseBatch(true);
        try {
            $batch = $service->createBatch();

            $opts = [
                'fields' => self::FETCHFIELDS_GET
            ];

            /** @var RequestInterface $request */
            $request = $this->service->files->get($itemId, $opts);
            $batch->add($request, 'obj');

            if ($checkDir && $this->useHasDir) {
                /** @var RequestInterface $request */
                $request = $service->files->listFiles($this->applyDefaultParams([
                    'pageSize' => 1,
                    'orderBy' => 'folder,modifiedTime,name',
                    'q' => sprintf('trashed = false and "%s" in parents and mimeType = "%s"', $itemId, self::DIRMIME)
                ], 'files.list'));

                $batch->add($request, 'hasdir');
            }
            $results = array_values($batch->execute() ?: []);

            [$fileObj, $hasdir] = array_pad($results, 2, null);
        } finally {
            $client->setUseBatch(false);
        }

        if ($fileObj instanceof Google_Service_Drive_DriveFile) {
            if ($hasdir && $fileObj->mimeType === self::DIRMIME) {
                if ($hasdir instanceof Google_Service_Drive_FileList) {
                    $this->cacheHasDirs[$fileObj->getId()] = (bool)$hasdir->getFiles();
                }
            }
        } else {
            $fileObj = null;
        }

        if ($fileObj !== null) {
            $this->cacheFileObjects[$itemId] = $fileObj;
            $this->cacheObjects([$itemId => $fileObj]);
        }

        return $fileObj;
    }

    /**
     * Get download url
     *
     * @param Google_Service_Drive_DriveFile $file
     *
     * @return string|false
     */
    protected function getDownloadUrl($file)
    {
        if (strpos($file->mimeType, 'application/vnd.google-apps') !== 0) {
            $params = $this->applyDefaultParams(['alt' => 'media'], 'files.get');
            return 'https://www.googleapis.com/drive/v3/files/'.$file->getId().'?'.http_build_query($params);
        }

        $mimeMap = $this->options['appsExportMap'];
        if (isset($mimeMap[$file->getMimeType()])) {
            $mime = $mimeMap[$file->getMimeType()];
        } else {
            $mime = $mimeMap['default'];
        }
        $mime = rawurlencode($mime);

        $params = $this->applyDefaultParams(['mimeType' => $mime], 'files.get');
        return 'https://www.googleapis.com/drive/v3/files/'.$file->getId().'/export?'.http_build_query($params);
    }

    /**
     * Create dirctory
     *
     * @param string $name
     * @param string $parentId
     *
     * @return Google_Service_Drive_DriveFile|null
     */
    protected function createDir($name, $parentId)
    {
        $file = new Google_Service_Drive_DriveFile();
        $file->setName($name);
        $file->setParents([
            $parentId
        ]);
        $file->setMimeType(self::DIRMIME);

        $obj = $this->service->files->create($file, $this->applyDefaultParams([
            'fields' => self::FETCHFIELDS_GET
        ], 'files.create'));
        $this->resetRequest($parentId);

        return ($obj instanceof Google_Service_Drive_DriveFile) ? $obj : null;
    }

    /**
     * Upload|Update item
     *
     * @param string          $path
     * @param string|resource $contents
     * @param Config          $config
     * @param bool|null       $updating If null then we check for existence of the file
     * @return \League\Flysystem\StorageAttributes|false item info
     */
    protected function upload($path, $contents, Config $config, $updating = null)
    {
        [$parentId, $fileName] = $this->splitPath($path);
        $mime = $config->get('mimetype');
        $file = new Google_Service_Drive_DriveFile();

        if ($updating === null || $updating === true) {
            $srcFile = $this->getFileObject($path);
            $updating = $srcFile !== null;
        } else {
            $srcFile = null;
        }
        if (!$updating) {
            $file->setName($fileName);
            $file->setParents([
                $parentId
            ]);
        }

        if (!$mime) {
            $mime = self::guessMimeType($fileName, is_string($contents) ? $contents : '');
            if (empty($mime)) {
                $mime = 'application/octet-stream';
            }
        }
        $file->setMimeType($mime);

        /** @var StreamInterface $stream */
        $stream = Psr7\stream_for($contents);
        $size = $stream->getSize();

        if ($size <= self::MAX_CHUNK_SIZE) {
            // one shot upload
            $params = [
                'data' => $stream,
                'uploadType' => 'media',
                'fields' => self::FETCHFIELDS_GET
            ];

            if (!$updating) {
                $obj = $this->service->files->create($file, $this->applyDefaultParams($params, 'files.create'));
            } else {
                $obj = $this->service->files->update($srcFile->getId(), $file, $this->applyDefaultParams($params, 'files.update'));
            }
        } else {
            // chunked upload
            $client = $this->service->getClient();

            $params = [
                'fields' => self::FETCHFIELDS_GET
            ];

            $client->setDefer(true);
            if (!$updating) {
                /** @var RequestInterface $request */
                $request = $this->service->files->create($file, $this->applyDefaultParams($params, 'files.create'));
            } else {
                /** @var RequestInterface $request */
                $request = $this->service->files->update($srcFile->getId(), $file, $this->applyDefaultParams($params, 'files.update'));
            }

            $media = new StreamableUpload($client, $request, $mime, $stream, true, self::MAX_CHUNK_SIZE);
            $media->setFileSize($size);
            do {
                if (DEBUG_ME) {
                    echo "* Uploading next chunk.\n";
                }
                $status = $media->nextChunk();
            } while ($status === false);

            // The final value of $status will be the data from the API for the object that has been uploaded.
            if ($status !== false) {
                $obj = $status;
            }

            $client->setDefer(false);
        }

        $this->resetRequest($parentId);

        if (isset($obj) && $obj instanceof Google_Service_Drive_DriveFile) {
            $this->cacheFileObjects[$obj->getId()] = $obj;
            $this->cacheObjects([$obj->getId() => $obj]);
            $result = $this->normaliseObject($obj, self::dirname($path));
            if ($config->get('visibility') === Visibility::PUBLIC) {
                $this->publish($obj->getId());
            } else {
                $this->unpublish($obj->getId());
            }
            return $result;
        }
        return false;
    }

    /**
     * @param array $ids
     * @param bool $checkDir
     * @return array
     */
    protected function getObjects($ids, $checkDir = false)
    {
        if ($checkDir && !$this->useHasDir) {
            $checkDir = false;
        }

        $fetch = [];
        foreach ($ids as $itemId) {
            if (!isset($this->cacheFileObjects[$itemId])) {
                $fetch[$itemId] = null;
            }
        }
        if (!empty($fetch) || $checkDir) {
            $service = $this->service;
            $client = $service->getClient();

            $client->setUseBatch(true);
            try {
                $batch = $service->createBatch();

                $opts = [
                    'fields' => self::FETCHFIELDS_GET
                ];

                $count = 0;
                if (!$this->rootId) {
                    /** @var RequestInterface $request */
                    $request = $this->service->files->get($this->root, $this->applyDefaultParams($opts, 'files.get'));
                    $batch->add($request, 'rootdir');
                    $count++;
                }

                $results = [];
                foreach ($fetch as $itemId => $value) {
                    if (DEBUG_ME) {
                        echo "*** FETCH *** $itemId\n";
                    }

                    /** @var RequestInterface $request */
                    $request = $this->service->files->get($itemId, $opts);
                    $batch->add($request, $itemId);
                    $count++;

                    if ($checkDir) {
                        /** @var RequestInterface $request */
                        $request = $service->files->listFiles($this->applyDefaultParams([
                            'pageSize' => 1,
                            'orderBy' => 'folder,modifiedTime,name',
                            'q' => sprintf('trashed = false and "%s" in parents and mimeType = "%s"', $itemId, self::DIRMIME)
                        ], 'files.list'));
                        $batch->add($request, 'hasdir-'.$itemId);
                        $count++;
                    }

                    if ($count > 90) {
                        // batch requests are limited to 100 calls in a single batch request
                        $results[] = $batch->execute();
                        $batch = $service->createBatch();
                        $count = 0;
                    }
                }
                if ($count > 0) {
                    $results[] = $batch->execute();
                }
                if (!empty($results)) {
                    $results = array_merge(...$results);
                }

                foreach ($results as $key => $value) {
                    if ($value instanceof Google_Service_Drive_DriveFile) {
                        $itemId = $value->getId();
                        $this->cacheFileObjects[$itemId] = $value;
                        if (!$this->rootId && strcmp($key, 'response-rootdir') === 0) {
                            $this->rootId = $itemId;
                        }
                    } else {
                        if ($checkDir && $value instanceof Google_Service_Drive_FileList) {
                            if (strncmp($key, 'response-hasdir-', 16) === 0) {
                                $key = substr($key, 16);
                                if (isset($this->cacheFileObjects[$key]) && $this->cacheFileObjects[$key]->mimeType === self::DIRMIME) {
                                    $this->cacheHasDirs[$key] = (bool)$value->getFiles();
                                }
                            }
                        }
                    }
                }

                $this->cacheObjects($results);
            } finally {
                $client->setUseBatch(false);
            }
        }

        $objects = [];
        foreach ($ids as $itemId) {
            $objects[$itemId] = isset($this->cacheFileObjects[$itemId]) ? $this->cacheFileObjects[$itemId] : null;
        }
        return $objects;
    }

    protected function buildPathFromCacheFileObjects($lastItemId)
    {
        $complete_paths = [];
        $itemIds = [$lastItemId];
        $paths = ['' => ''];
        $is_first = true;
        while (!empty($itemIds)) {
            $new_itemIds = [];
            $new_paths = [];
            foreach ($itemIds as $itemId) {
                if (empty($this->cacheFileObjects[$itemId])) {
                    continue;
                }

                /* @var Google_Service_Drive_DriveFile $obj */
                $obj = $this->cacheFileObjects[$itemId];
                $parents = $obj->getParents();

                foreach ($paths as $id => $path) {
                    if ($is_first) {
                        $is_first = false;
                        $new_path = $this->sanitizeFilename($obj->getName());
                        $id = $itemId;
                    } else {
                        $new_path = $this->sanitizeFilename($obj->getName()).'/'.$path;
                    }

                    if ($this->rootId === $itemId) {
                        if (!empty($path)) {
                            $complete_paths[$id] = $path;
                        } // this path is complete...don't include drive name
                    } else {
                        if (!empty($parents)) {
                            $new_paths[$id] = $new_path;
                        }
                    }
                }

                if (!empty($parents)) {
                    $new_itemIds[] = (array)($obj->getParents());
                }
            }
            $paths = $new_paths;
            $itemIds = !empty($new_itemIds) ? array_merge(...$new_itemIds) : [];
        }
        return $complete_paths;
    }

    protected function uncacheId($id)
    {
        $basePath = null;
        foreach ($this->cachedPaths as $path => $itemId) {
            if ($itemId === $id) {
                $basePath = (string)$path;
                break;
            }
        }
        if ($basePath) {
            foreach ($this->cachedPaths as $path => $itemId) {
                if (strlen((string)$path) >= strlen($basePath) && strncmp((string)$path, $basePath, strlen($basePath)) === 0) {
                    unset($this->cachedPaths[$path]);
                }
            }
        }

        unset($this->cacheFileObjects[$id], $this->cacheHasDirs[$id]);
    }

    protected function cacheObjects($objects)
    {
        foreach ($objects as $key => $value) {
            if ($value instanceof Google_Service_Drive_DriveFile) {
                $complete_paths = $this->buildPathFromCacheFileObjects($value->getId());
                foreach ($complete_paths as $itemId => $path) {
                    if (DEBUG_ME) {
                        echo 'Complete path: '.$path.' ['.$itemId."]\n";
                    }

                    if (!isset($this->cachedPaths[$path])) {
                        $this->cachedPaths[$path] = $itemId;
                    } else {
                        if (!is_array($this->cachedPaths[$path])) {
                            if ($itemId !== $this->cachedPaths[$path]) {
                                // convert to array
                                $this->cachedPaths[$path] = [
                                    $this->cachedPaths[$path],
                                    $itemId
                                ];

                                if (DEBUG_ME) {
                                    echo 'Caching [DUP]: '.$path.' => '.$itemId."\n";
                                }
                            }
                        } else {
                            if (!in_array($itemId, $this->cachedPaths[$path])) {
                                array_push($this->cachedPaths[$path], $itemId);
                                if (DEBUG_ME) {
                                    echo 'Caching [DUP]: '.$path.' => '.$itemId."\n";
                                }
                            }
                        }
                    }
                }
            }
        }
    }

    protected function indexString($str, $ch = '/')
    {
        $indices = [];
        for ($i = 0, $len = strlen($str); $i < $len; $i++) {
            if ($str[$i] === $ch) {
                $indices[] = $i;
            }
        }
        return $indices;
    }

    protected function getCachedPathId($path, $indices = null)
    {
        $pathLen = strlen($path);
        if ($indices === null) {
            $indices = $this->indexString($path, '/');
            $indices[] = $pathLen;
        }

        $maxLen = 0;
        $itemId = null;
        $pathMatch = null;

        foreach ($this->cachedPaths as $pathFrag => $id) {
            $pathFrag = (string)$pathFrag;
            $len = strlen($pathFrag);
            if ($len > $pathLen || $len < $maxLen || !in_array($len, $indices)) {
                continue;
            }

            if (strncmp($pathFrag, $path, $len) === 0) {
                if ($len === $pathLen) {
                    return [$id, $pathFrag];
                } // we found a perfect match

                $maxLen = $len;
                $itemId = $id;
                $pathMatch = $pathFrag;
            }
        }

        // we found a partial match or none at all
        return [$itemId, $pathMatch];
    }

    protected function getPathToIndex($path, $i, $indices)
    {
        if ($i < 0) {
            return '';
        }
        if (!isset($indices[$i]) || !isset($indices[$i + 1])) {
            return $path;
        }
        return substr($path, 0, $indices[$i]);
    }

    protected function getToken($path, $i, $indices)
    {
        if ($i < 0 || !isset($indices[$i])) {
            return '';
        }
        $start = $i > 0 ? $indices[$i - 1] + 1 : 0;
        return substr($path, $start, isset($indices[$i]) ? $indices[$i] - $start : null);
    }

    protected function cachePaths($displayPath, $i, $indices, $parentItemId)
    {
        $nextItemId = $parentItemId;
        for ($count = count($indices); $i < $indices; $i++) {
            $token = $this->getToken($displayPath, $i, $indices);
            if (empty($token) && $token !== '0') {
                return;
            }
            $basePath = $this->getPathToIndex($displayPath, $i - 2, $indices);
            if (!empty($basePath)) {
                $basePath .= '/';
            }

            if ($nextItemId === null) {
                return;
            }

            $is_last = $i === $count - 1;

            // search only for directories unless it's the last token
            if (!is_array($nextItemId)) {
                $nextItemId = [$nextItemId];
            }

            $items = [];
            foreach ($nextItemId as $id) {
                if (!$this->canRequest($id, $is_last)) {
                    continue;
                }
                $this->markRequest($id, $is_last);
                if (DEBUG_ME) {
                    echo 'New req: '.$id;
                }
                $items[] = $this->getItems($id, false, 0, $is_last ? '' : 'mimeType = "'.self::DIRMIME.'"');
                if (DEBUG_ME) {
                    echo " ...done\n";
                }
            }
            if (!empty($items)) {
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $items = array_merge(...$items);
            }

            $nextItemId = null;
            foreach ($items as $itemObj) {
                $item = $itemObj->extraMetadata();
                $itemId = basename($item['virtual_path']);
                $fullPath = $basePath.$item['display_path'];

                // update cache
                if (!isset($this->cachedPaths[$fullPath])) {
                    $this->cachedPaths[$fullPath] = $itemId;
                    if (DEBUG_ME) {
                        echo 'Caching: '.$fullPath.' => '.$itemId."\n";
                    }
                } else {
                    if (!is_array($this->cachedPaths[$fullPath])) {
                        if ($itemId !== $this->cachedPaths[$fullPath]) {
                            // convert to array
                            $this->cachedPaths[$fullPath] = [
                                $this->cachedPaths[$fullPath],
                                $itemId
                            ];

                            if (DEBUG_ME) {
                                echo 'Caching [DUP]: '.$fullPath.' => '.$itemId."\n";
                            }
                        }
                    } else {
                        if (!in_array($itemId, $this->cachedPaths[$fullPath])) {
                            $this->cachedPaths[$fullPath][] = $itemId;
                            if (DEBUG_ME) {
                                echo 'Caching [DUP]: '.$fullPath.' => '.$itemId."\n";
                            }
                        }
                    }
                }

                if (basename($item['display_path']) === $token) {
                    $nextItemId = $this->cachedPaths[$fullPath];
                } // found our token
            }
        }
    }

    /**
     * Create a full virtual path from cache
     * @param string $displayPath
     * @param bool   $returnFirstItem return first item only
     * @return string[]|string
     * @throws FileNotFoundException
     */
    protected function makeFullVirtualPath($displayPath, $returnFirstItem = false)
    {
        $paths = ['' => null];

        $tmp = '';
        $tokens = explode('/', trim($displayPath, '/'));
        foreach ($tokens as $token) {
            if (empty($tmp)) {
                $tmp .= $token;
            } else {
                $tmp .= '/'.$token;
            }

            if (empty($this->cachedPaths[$tmp])) {
                throw UnableToReadFile::fromLocation($displayPath, 'File not found');
            }
            if (is_array($this->cachedPaths[$tmp])) {
                $new_paths = [];
                foreach ($paths as $path => $obj) {
                    $parentId = $path === '' ? '' : basename($path);
                    foreach ($this->cachedPaths[$tmp] as $id) {
                        if ($parentId === '' || (!empty($this->cacheFileObjects[$id]->parents) && in_array($parentId, $this->cacheFileObjects[$id]->parents))) {
                            $new_paths[$path.'/'.$id] = $this->cacheFileObjects[$id];
                        }
                    }
                }
                $paths = $new_paths;
            } else {
                $id = $this->cachedPaths[$tmp];
                $new_paths = [];
                foreach ($paths as $path => $obj) {
                    $parentId = $path === '' ? '' : basename($path);
                    if ($parentId === '' || (!empty($this->cacheFileObjects[$id]->parents) && in_array($parentId, $this->cacheFileObjects[$id]->parents))) {
                        $new_paths[$path.'/'.$id] = $this->cacheFileObjects[$id];
                    }
                }
                $paths = $new_paths;
            }
        }

        $count = count($paths);
        if ($count === 0) {
            throw UnableToReadFile::fromLocation($displayPath, 'File not found');
        }

        if (count($paths) > 1) {
            // sort oldest to newest
            uasort($paths, function ($a, $b) {
                $t1 = strtotime($a->getCreatedTime());
                $t2 = strtotime($b->getCreatedTime());
                if ($t1 < $t2) {
                    return -1;
                }
                if ($t1 > $t2) {
                    return 1;
                }
                return 0;
            });

            if (!$returnFirstItem) {
                return array_keys($paths);
            }
        }
        return array_keys($paths)[0];
    }

    protected function returnSingle($item, $returnFirstItem)
    {
        if ($returnFirstItem && is_array($item)) {
            return $item[0];
        }
        return $item;
    }

    /**
     * Convert display path to virtual path or just id
     * @param string $displayPath
     * @param bool   $makeFullVirtualPath
     * @param bool   $returnFirstItem
     * @return string Single itemId/path
     * @throws FileNotFoundException
     */
    protected function toVirtualPath($displayPath, $makeFullVirtualPath = true, $returnFirstItem = false)
    {
        if ($displayPath === '' || $displayPath === '/' || $displayPath === $this->root) {
            return '';
        }

        $displayPath = trim($displayPath, '/'); // not needed

        $indices = $this->indexString($displayPath, '/');
        $indices[] = strlen($displayPath);

        [$itemId, $pathMatch] = $this->getCachedPathId($displayPath, $indices);
        $i = 0;
        if ($pathMatch !== null) {
            if (strcmp($pathMatch, $displayPath) === 0) {
                if ($makeFullVirtualPath) {
                    return $this->makeFullVirtualPath($displayPath, $returnFirstItem);
                }
                return $this->returnSingle($itemId, $returnFirstItem);
            }
            $i = array_search(strlen($pathMatch), $indices) + 1;
        }
        if ($itemId === null) {
            $itemId = '';
        }
        $this->cachePaths($displayPath, $i, $indices, $itemId);

        if ($makeFullVirtualPath) {
            return $this->makeFullVirtualPath($displayPath, $returnFirstItem);
        }

        if (empty($this->cachedPaths[$displayPath])) {
            throw UnableToReadFile::fromLocation($displayPath, 'File not found');
        }

        return $this->returnSingle($this->cachedPaths[$displayPath], $returnFirstItem);
    }

    /**
     * Convert virtual path to display path
     * @param string $virtualPath
     * @return string
     * @throws FileNotFoundException
     */
    protected function toDisplayPath($virtualPath)
    {
        if ($virtualPath === '' || $virtualPath === '/') {
            return '/';
        }

        $tokens = explode('/', trim($virtualPath, '/'));

        /** @var Google_Service_Drive_DriveFile[] $objects */
        $objects = $this->getObjects($tokens);
        $display = '';
        foreach ($tokens as $token) {
            if (!isset($objects[$token])) {
                throw UnableToReadFile::fromLocation($virtualPath, 'File not found');
            }
            if (!empty($display) || $display === '0') {
                $display .= '/';
            }
            $display .= $this->sanitizeFilename($objects[$token]->getName());
        }
        return $display;
    }

    protected function toSingleVirtualPath($displayPath, $makeFullVirtualPath = true, $can_throw = true, $createDirsIfNeeded = false, $is_dir = false)
    {
        try {
            $path = $this->toVirtualPath($displayPath, $makeFullVirtualPath, true);
        } catch (UnableToReadFile $e) {
            if (!$createDirsIfNeeded) {
                if ($can_throw) {
                    throw $e;
                }
                return false;
            }

            $subdir = $is_dir ? $displayPath : self::dirname($displayPath);
            if ($subdir === '') {
                if ($can_throw) {
                    throw $e;
                }
                return false;
            }

            $this->createDirectory($subdir, new Config());
            if(!$this->hasDir($subdir)){
                if ($can_throw) {
                    throw $e;
                }
                return false;
            }

            try {
                $path = $this->toVirtualPath($displayPath, $makeFullVirtualPath, true);
            } catch (UnableToReadFile $e) {
                if ($can_throw) {
                    throw $e;
                }
                return false;
            }
        }
        return $path;
    }

    protected function canRequest($id, $is_full_req)
    {
        if (!isset($this->requestedIds[$id])) {
            return true;
        }
        if ($is_full_req && $this->requestedIds[$id]['type'] === false) {
            return true;
        } // we're making a full dir request and previous request was dirs only...allow
        if (time() - $this->requestedIds[$id]['time'] > self::FILE_OBJECT_MINIMUM_VALID_TIME) {
            return true;
        }
        return false; // not yet
    }

    protected function markRequest($id, $is_full_req)
    {
        $this->requestedIds[$id] = [
            'type' => (bool)$is_full_req,
            'time' => time()
        ];
    }

    /**
     * @param string|string[] $id
     * @param bool            $reset_all
     */
    protected function resetRequest($id, $reset_all = false)
    {
        if ($reset_all) {
            $this->requestedIds = [];
        } else {
            if (is_array($id)) {
                foreach ($id as $i) {
                    if ($i === $this->root) {
                        unset($this->requestedIds['']);
                    }
                    unset($this->requestedIds[$i]);
                }
            } else {
                if ($id === $this->root) {
                    unset($this->requestedIds['']);
                }
                unset($this->requestedIds[$id]);
            }
        }
    }

    protected function sanitizeFilename($filename)
    {
        if (!empty($this->options['sanitize_chars'])) {
            $filename = str_replace(
                $this->options['sanitize_chars'],
                $this->options['sanitize_replacement_char'],
                $filename
            );
        }

        return $filename;
    }

    public static function dirname($path)
    {
        // fix for Flysystem bug on Windows
        $path = self::normalizeDirname(dirname($path));
        return str_replace('\\', '/', $path);
    }

    protected function applyDefaultParams($params, $cmdName)
    {
        if (isset($this->optParams[$cmdName]) && is_array($this->optParams[$cmdName])) {
            return array_replace($this->optParams[$cmdName], $params);
        } else {
            return $params;
        }
    }

    /**
     * Enables Team Drive support by changing default parameters
     *
     * @return void
     *
     * @see https://developers.google.com/drive/v3/reference/files
     * @see \Google_Service_Drive_Resource_Files
     */
    public function enableTeamDriveSupport()
    {
        $this->optParams = array_merge_recursive(
            array_fill_keys([
                'files.copy', 'files.create', 'files.delete',
                'files.trash', 'files.get', 'files.list', 'files.update',
                'files.watch'
            ], ['supportsTeamDrives' => true]),
            $this->optParams
        );
    }

    /**
     * Selects Team Drive to operate by changing default parameters
     *
     * @return void
     *
     * @param   string   $teamDriveId   Team Drive id
     * @param   string   $corpora       Corpora value for files.list
     *
     * @see https://developers.google.com/drive/v3/reference/files
     * @see https://developers.google.com/drive/v3/reference/files/list
     * @see \Google_Service_Drive_Resource_Files
     */
    public function setTeamDriveId($teamDriveId, $corpora = 'teamDrive')
    {
        $this->enableTeamDriveSupport();
        $this->optParams = array_merge_recursive($this->optParams, [
            'files.list' => [
                'corpora' => $corpora,
                'includeTeamDriveItems' => true,
                'teamDriveId' => $teamDriveId
            ]
        ]);

        if ($this->root === 'root' || $this->root === null) {
            $this->setPathPrefix($teamDriveId);
            $this->root = $teamDriveId;
        }
    }

    /**
     * Guess MIME Type based on the path of the file and it's content.
     *
     * @param string          $path
     * @param string|resource $content
     *
     * @return string|null MIME Type or NULL if no extension detected
     */
    public static function guessMimeType($path, $content)
    {
        $detector = new FinfoMimeTypeDetector();
        if (is_string($content)) {
            $mimeType = $detector->detectMimeTypeFromBuffer($content);
        }
        if (!(empty($mimeType) || in_array($mimeType, ['application/x-empty', 'text/plain', 'text/x-asm']))) {
            return $mimeType;
        }
        return $detector->detectMimeTypeFromPath($path) ?: 'text/plain';
    }

    /**
     * Normalize a dirname return value.
     *
     * @param string $dirname
     *
     * @return string normalized dirname
     */
    public static function normalizeDirname($dirname)
    {
        return $dirname === '.' ? '' : $dirname;
    }
}
