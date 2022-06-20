<?php

namespace Masbug\Flysystem;

use Google\Service\Drive;
use Google\Service\Drive\DriveFile;
use Google\Service\Drive\FileList;
use Google\Service\Drive\Permission;
use GuzzleHttp\Psr7\Utils;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\AdapterInterface;
use League\Flysystem\Config;
use League\Flysystem\FileNotFoundException;
use League\Flysystem\Util;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamInterface;

define('DEBUG_ME', false);

class GoogleDriveAdapter extends AbstractAdapter
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
     * \Google\Service\Drive instance
     *
     * @var Drive
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
        'usePermanentDelete'   => false,
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

        'driveId' => null,

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
     * Permanent delete files and directories, avoid setTrashed
     *
     * @var bool
     */
    private $usePermanentDelete = false;

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
     * GoogleDriveAdapter constructor.
     *
     * @param  Drive  $service
     * @param  string|null  $root
     * @param  array  $options
     */
    public function __construct($service, $root = null, $options = [])
    {
        $this->service = $service;

        $this->options = array_replace_recursive(static::$defaultOptions, $options);

        $this->spaces = $this->options['spaces'];
        $this->useHasDir = $this->options['useHasDir'];
        $this->usePermanentDelete = $this->options['usePermanentDelete'];
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
                    $root = $this->spaces === 'appDataFolder' ? 'appDataFolder' : 'root';
                }
                $this->root = $root;
                $this->setPathPrefix('');
            } else {
                $this->root = $this->spaces === 'appDataFolder' ? 'appDataFolder' : 'root';
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
     * Gets the service
     *
     * @return \Google\Service\Drive
     */
    public function getService()
    {
        $this->refreshToken();
        return $this->service;
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

    /**
     * Allow to refresh tokens to enable long running process
     *
     * @return void
     */
    public function refreshToken()
    {
        $client = $this->service->getClient();
        if ($client->isAccessTokenExpired()) {
            if ($client->isUsingApplicationDefaultCredentials()) {
                $client->fetchAccessTokenWithAssertion();
            } else {
                $refreshToken = $client->getRefreshToken();
                if ($refreshToken) {
                    $client->fetchAccessTokenWithRefreshToken($refreshToken);
                }
            }
        }
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

    /**
     * {@inheritdoc}
     */
    public function write($path, $contents, Config $config)
    {
        $updating = null;

        if ($this->useDisplayPaths) {
            try {
                $virtual_path = $this->toVirtualPath($path, true, false);
                $updating = true; // destination exists
            } catch (FileNotFoundException $e) {
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

        return $this->upload($virtual_path, $contents, $config, $updating);
    }

    /**
     * {@inheritdoc}
     */
    public function writeStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->write($path, $resource, $config);
    }

    /**
     * {@inheritdoc}
     */
    public function rename($path, $newpath)
    {
        $this->refreshToken();
        if ($this->useDisplayPaths) {
            $path = $this->toVirtualPath($path, true, true);
            $newpathDir = self::dirname($newpath);
            try {
                $toPath = $this->toVirtualPath($newpathDir, false, true);
            } catch (FileNotFoundException $e) {
                if ($this->createDir($newpathDir, new Config(), true) === false) {
                    return false;
                }
                $toPath = $this->toVirtualPath($newpathDir, false, true);
            }
            if ($toPath === '') {
                $toPath = $this->root;
            }

            [$oldParent, $fileId] = $this->splitPath($path);
            $newParent = $toPath;
            $newName = basename($newpath);
        } else {
            [$oldParent, $fileId] = $this->splitPath($path);
            [$newParent, $newName] = $this->splitPath($newpath);
        }

        $file = new DriveFile();
        $file->setName($newName);
        $opts = [
            'fields' => self::FETCHFIELDS_GET
        ];
        if ($newParent !== $oldParent) {
            $opts['addParents'] = $newParent;
            if ($oldParent !== '') {
                $opts['removeParents'] = $oldParent;
            }
        }

        try {
            $updatedFile = $this->service->files->update($fileId, $file, $this->applyDefaultParams($opts, 'files.update'));

            $id = $updatedFile->getId();
            if (isset($this->cacheHasDirs[$fileId])) {
                $this->cacheHasDirs[$id] = $this->cacheHasDirs[$fileId];
            }
            $this->uncacheId($fileId);
            $this->cacheFileObjects[$id] = $updatedFile;
            $this->cacheObjects([$id => $updatedFile]);
            $this->resetRequest([$oldParent, $newParent, $fileId, $id]);

            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function copy($path, $newpath)
    {
        $this->refreshToken();
        if ($this->useDisplayPaths) {
            $srcId = $this->toVirtualPath($path, false, true);
            $newpathDir = self::dirname($newpath);
            $toPath = $this->toSingleVirtualPath($newpathDir, false, false, true, true);
            if ($toPath === false) {
                return false;
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

        $file = new DriveFile();
        $file->setName($fileName);
        $file->setParents([
            $newParentId
        ]);

        $newFile = $this->service->files->copy($srcId, $file, $this->applyDefaultParams([
            'fields' => self::FETCHFIELDS_GET
        ], 'files.copy'));

        if ($newFile instanceof DriveFile) {
            $id = $newFile->getId();
            $this->cacheFileObjects[$id] = $newFile;
            $this->cacheObjects([$id => $newFile]);
            if (isset($this->cacheHasDirs[$srcId])) {
                $this->cacheHasDirs[$id] = $this->cacheHasDirs[$srcId];
            }
            if ($this->useDisplayPaths) {
                $this->cachedPaths[trim($newpathDir.'/'.$fileName, '/')] = $id;
            }

            if ($this->getRawVisibility($srcId) === AdapterInterface::VISIBILITY_PUBLIC) {
                $this->publish($id);
            } else {
                $this->unPublish($id);
            }
            $this->resetRequest([$id, $newParentId]);
            return true;
        }

        return false;
    }

    /**
     * Delete an array of google file ids
     *
     * @param  string[]|string  $ids
     * @return bool
     */
    protected function delete_by_id($ids)
    {
        $this->refreshToken();
        $deleted = false;
        if (!is_array($ids)) {
            $ids = [$ids];
        }
        foreach ($ids as $id) {
            if ($id !== '' && ($file = $this->getFileObject($id))) {
                if ($file->getParents()) {
                    if ($this->usePermanentDelete && $this->service->files->delete($id, $this->applyDefaultParams([], 'files.delete'))) {
                        $this->uncacheId($id);
                        $deleted = true;
                    } else {
                        if (!$this->usePermanentDelete) {
                            $file = new DriveFile();
                            $file->setTrashed(true);
                            if ($this->service->files->update($id, $file, $this->applyDefaultParams([], 'files.update'))) {
                                $this->uncacheId($id);
                                $deleted = true;
                            }
                        }
                    }
                }
            }
        }
        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function delete($path)
    {
        if ($path === '' || $path === '/') {
            return false;
        } // do not allow deleting root...

        $deleted = false;
        if ($this->useDisplayPaths) {
            try {
                $ids = $this->toVirtualPath($path, false);
                $deleted = $this->delete_by_id($ids);
            } catch (\Exception $e) {
                //Unnecesary
            }
        } else {
            if ($file = $this->getFileObject($path)) {
                $deleted = $this->delete_by_id($file->getId());
            }
        }

        if ($deleted) {
            $this->resetRequest('', true);
        }

        return $deleted;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteDir($dirname)
    {
        return $this->delete($dirname);
    }

    /**
     * {@inheritdoc}
     */
    public function createDir($dirname, Config $config, $internalCall = false)
    {
        try {
            $meta = $this->getMetadata($dirname);
        } catch (FileNotFoundException $e) {
            $meta = false;
        }

        if ($meta !== false) {
            return [
                'path' => $meta['path'],
                'filename' => $meta['filename'],
                'extension' => $meta['extension']
            ];
        }

        [$pdir, $name] = $this->splitPath($dirname, false);
        if ($this->useDisplayPaths) {
            if ($pdir !== $this->root) {
                $pdir = $this->toSingleVirtualPath($pdir, false, false, true, true); // recursion!
                if ($pdir === false) {
                    return false;
                } // failed to create dirs
            }
        }

        $folder = $this->createDirectory($name, $pdir !== '' ? basename($pdir) : $pdir);
        if ($folder !== null) {
            $itemId = $folder->getId();
            $this->cacheFileObjects[$itemId] = $folder;
            $this->cacheHasDirs[$itemId] = false;
            $this->cacheObjects([$itemId => $folder]);
            $path_parts = $this->splitFileExtension($name);
            $result = [
                'path' => Util::normalizeDirname($pdir).'/'.($this->useDisplayPaths ? $name : $itemId),
                'filename' => $path_parts['filename'],
                'extension' => $path_parts['extension']
            ];
            return $result;
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function has($path)
    {
        if ($this->useDisplayPaths) {
            try {
                $this->toVirtualPath($path, false);
                return true;
            } catch (FileNotFoundException $e) {
                return false;
            }
        }
        return ($this->getFileObject($path, true) instanceof DriveFile);
    }

    /**
     * {@inheritdoc}
     */
    public function read($path)
    {
        $this->refreshToken();
        if ($this->useDisplayPaths) {
            $fileId = $this->toVirtualPath($path, false, true);
        } else {
            [, $fileId] = $this->splitPath($path);
        }
        /** @var RequestInterface $response */
        if (($response = $this->service->files->get(/** @scrutinizer ignore-type */ $fileId, $this->applyDefaultParams(['alt' => 'media'], 'files.get')))) {
            return [
                'contents' => (string)$response->getBody()
            ];
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function readStream($path)
    {
        $this->refreshToken();
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
                    throw new FileNotFoundException($path);
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
                return false;
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
                return compact('stream');
            }
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function listContents($directory = '', $recursive = false)
    {
        $this->refreshToken();
        if ($this->useDisplayPaths) {
            $time = microtime(true);

            try {
                $vp = $this->toVirtualPath($directory);
            } catch (\Exception $e) {
                $vp = [];
            }
            $elapsed = (microtime(true) - $time) * 1000.0;
            if (!is_array($vp)) {
                $vp = [$vp];
            }

            $items = [];
            foreach ($vp as $path) {
                if (DEBUG_ME) {
                    echo 'Converted display path to virtual path ['.number_format($elapsed, 1).'ms]: '.$path."\n";
                }
                $items = array_merge($items, array_values($this->getItems($path, $recursive)));
            }
        } else {
            $items = array_values($this->getItems($directory, $recursive));
        }
        return $items;
    }

    /**
     * {@inheritdoc}
     */
    public function getMetadata($path)
    {
        if ($this->useDisplayPaths) {
            $path = $this->toVirtualPath($path, true, true);
        }
        if (($obj = $this->getFileObject($path, true))) {
            if ($obj instanceof DriveFile) {
                return $this->normaliseObject($obj, self::dirname($path));
            }
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getSize($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['size'])) ? $meta : false;
    }

    /**
     * {@inheritdoc}
     */
    public function getMimetype($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['mimetype'])) ? $meta : false;
    }

    /**
     * {@inheritdoc}
     */
    public function getTimestamp($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['timestamp'])) ? $meta : false;
    }

    /**
     * {@inheritdoc}
     */
    public function setVisibility($path, $visibility, $internalCall = false)
    {
        if ($this->useDisplayPaths && !$internalCall) {
            try {
                $path = $this->toVirtualPath($path, false, true);
            } catch (\Exception $e) {
                return false;
            }
        }
        $result = ($visibility === AdapterInterface::VISIBILITY_PUBLIC) ? $this->publish($path) : $this->unPublish($path);

        if ($result) {
            return compact('path', 'visibility');
        }

        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function getVisibility($path)
    {
        if ($this->useDisplayPaths) {
            $path = $this->toVirtualPath($path, false, true);
        }
        return [
            'visibility' => $this->getRawVisibility($path)
        ];
    }

    // /////////////////- ORIGINAL METHODS -///////////////////

    /**
     * Get contents parmanent URL
     *
     * @param  string  $path  itemId path
     * @param  string  $path  itemId path
     */
    public function getUrl($path)
    {
        if ($this->useDisplayPaths) {
            $path = $this->toVirtualPath($path, false, true);
        }
        if ($this->publish(/** @scrutinizer ignore-type */ $path)) {
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
     * @param  string  $path  itemId path
     * @return array
     */
    public function hasDir($path)
    {
        $meta = $this->getMetadata($path);
        return ($meta && isset($meta['hasdir'])) ? $meta : [
            'hasdir' => true
        ];
    }

    /**
     * Do cache cacheHasDirs with batch request
     *
     * @param  array  $targets  [[path => id],...]
     * @param  array  $object
     * @return array
     */
    protected function setHasDir($targets, $object)
    {
        $this->refreshToken();
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
            if ($result instanceof FileList) {
                $object[$paths[$key]]['hasdir'] = $this->cacheHasDirs[$paths[$key]] = (bool)$result->getFiles();
            }
        }
        $client->setUseBatch(false);
        return $object;
    }

    /**
     * Get the object permissions presented as a visibility.
     *
     * @param  string  $path  itemId path
     * @return string
     */
    protected function getRawVisibility($path)
    {
        $file = $this->getFileObject($path);
        $permissions = $file->getPermissions();
        $visibility = AdapterInterface::VISIBILITY_PRIVATE;

        if (! count($permissions)) {
            $permissions = $this->service->permissions->listPermissions($file->getId(), $this->applyDefaultParams([], 'permissions.list'));
            $file->setPermissions($permissions);
        }

        foreach ($permissions as $permission) {
            if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                $visibility = AdapterInterface::VISIBILITY_PUBLIC;
                break;
            }
        }
        return $visibility;
    }

    /**
     * Publish specified path item
     *
     * @param  string  $path  itemId path
     * @return bool
     */
    protected function publish($path)
    {
        $this->refreshToken();
        if (($file = $this->getFileObject($path))) {
            if ($this->getRawVisibility($path) === AdapterInterface::VISIBILITY_PUBLIC) {
                return true;
            }
            try {
                $new_permission = new Permission($this->publishPermission);
                if ($permission = $this->service->permissions->create($file->getId(), $new_permission, $this->applyDefaultParams([], 'files.create'))) {
                    $file->setPermissions([$permission]);
                    return true;
                }
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Un-publish specified path item
     *
     * @param  string  $path  itemId path
     * @return bool
     */
    protected function unPublish($path)
    {
        $this->refreshToken();
        if (($file = $this->getFileObject($path))) {
            if ($this->getRawVisibility($path) !== AdapterInterface::VISIBILITY_PUBLIC) {
                return true;
            }
            $permissions = $file->getPermissions();
            try {
                foreach ($permissions as $permission) {
                    if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role'] && !empty($file->getId())) {
                        $this->service->permissions->delete($file->getId(), $permission->getId(), $this->applyDefaultParams([], 'files.trash'));
                    }
                }
                $file->setPermissions([]);
                return true;
            } catch (\Exception $e) {
                return false;
            }
        }

        return false;
    }

    /**
     * Path splits to dirId, fileId or newName
     *
     * @param  string  $path
     * @param  bool  $getParentId  True => return only parent id, False => return full path (basically the same as dirname($path))
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
     * @param  string  $name
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
     * Get normalised files array from DriveFile
     *
     * @param  DriveFile  $object
     * @param  string  $dirname  Parent directory itemId path
     * @return array Normalised files array
     */
    protected function normaliseObject(DriveFile $object, $dirname)
    {
        $id = $object->getId();
        $path_parts = $this->splitFileExtension($object->getName());
        $result = ['id' => $id, 'visibility' => AdapterInterface::VISIBILITY_PRIVATE];
        $result['type'] = $object->mimeType === self::DIRMIME ? 'dir' : 'file';
        $permissions = $object->getPermissions();
        try {
            foreach ($permissions as $permission) {
                if ($permission->type === $this->publishPermission['type'] && $permission->role === $this->publishPermission['role']) {
                    $result['visibility'] = AdapterInterface::VISIBILITY_PUBLIC;
                    break;
                }
            }
        } catch (\Exception $e) {
            // Unnecesary
        }
        if ($this->useDisplayPaths) {
            $result['virtual_path'] = ($dirname ? ($dirname.'/') : '').$id;
            $result['display_path'] = $this->toDisplayPath($result['virtual_path']);

            $result['path'] = $result['display_path'];
        } else {
            $result['virtual_path'] = ($dirname ? ($dirname.'/') : '').$id;
            $result['display_path'] = $result['virtual_path'];

            $result['path'] = $result['virtual_path'];
        }

        $result['filename'] = $path_parts['filename'];
        $result['extension'] = $path_parts['extension'];
        $result['timestamp'] = strtotime($object->getModifiedTime());
        if ($result['type'] === 'file') {
            $result['mimetype'] = $object->mimeType;
            $result['size'] = (int)$object->getSize();
        }
        if ($result['type'] === 'dir') {
            $result['size'] = 0;
            if ($this->useHasDir) {
                $result['hasdir'] = isset($this->cacheHasDirs[$id]) ? $this->cacheHasDirs[$id] : false;
            }
        }
        return $result;
    }

    /**
     * Get items array of target dirctory
     *
     * @param  string  $dirname  itemId path
     * @param  bool  $recursive
     * @param  int  $maxResults
     * @param  string  $query
     * @return array Items array
     */
    protected function getItems($dirname, $recursive = false, $maxResults = 0, $query = '')
    {
        $this->refreshToken();
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
                if ($fileObjs instanceof FileList) {
                    foreach ($fileObjs as $obj) {
                        $id = $obj->getId();
                        $this->cacheFileObjects[$id] = $obj;
                        $result = $this->normaliseObject($obj, $dirname);
                        $results[$id] = $result;
                        if ($result['type'] === 'dir') {
                            if ($this->useHasDir) {
                                $setHasDir[$id] = $id;
                            }
                            if ($this->cacheHasDirs[$itemId] === false) {
                                $this->cacheHasDirs[$itemId] = true;
                                unset($setHasDir[$itemId]);
                            }
                            if ($recursive) {
                                $results = array_merge($results, $this->getItems($result['virtual_path'], true, $maxResults, $query));
                            }
                        }
                    }
                    $pageToken = $fileObjs->getNextPageToken();
                } else {
                    $pageToken = null;
                }
            } catch (\Exception $e) {
                $pageToken = null;
            }
        } while ($pageToken && $maxResults === 0);

        if ($setHasDir) {
            $results = $this->setHasDir($setHasDir, $results);
        }
        return array_values($results);
    }

    /**
     * Get file object DriveFile
     *
     * @param  string  $path  itemId path
     * @param  bool  $checkDir  do check hasdir
     * @return DriveFile|null
     */
    public function getFileObject($path, $checkDir = false)
    {
        [, $itemId] = $this->splitPath($path);
        if (isset($this->cacheFileObjects[$itemId])) {
            return $this->cacheFileObjects[$itemId];
        }
        $this->refreshToken();
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
            $results = array_values($batch->execute());

            [$fileObj, $hasdir] = array_pad($results, 2, null);
        } finally {
            $client->setUseBatch(false);
        }

        if ($fileObj instanceof DriveFile) {
            if ($hasdir && $fileObj->mimeType === self::DIRMIME) {
                if ($hasdir instanceof FileList) {
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
     * @param  DriveFile  $file
     * @return string|false
     */
    protected function getDownloadUrl($file)
    {
        if (strpos($file->mimeType, 'application/vnd.google-apps') !== 0) {
            $params = $this->applyDefaultParams(['alt' => 'media'], 'files.get');
            foreach ($params as $key => $value) {
                if (is_bool($value)) {
                    $params[$key] = $value ? 'true' : 'false';
                }
            }
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
     * Create directory
     *
     * @param  string  $name
     * @param  string  $parentId
     * @return DriveFile|null
     */
    protected function createDirectory($name, $parentId)
    {
        $this->refreshToken();
        $file = new DriveFile();
        $file->setName($name);
        $file->setParents([
            $parentId
        ]);
        $file->setMimeType(self::DIRMIME);

        $obj = $this->service->files->create($file, $this->applyDefaultParams([
            'fields' => self::FETCHFIELDS_GET
        ], 'files.create'));
        $this->resetRequest($parentId);

        return ($obj instanceof DriveFile) ? $obj : null;
    }

    /**
     * Upload|Update item
     *
     * @param  string  $path
     * @param  string|resource  $contents
     * @param  Config  $config
     * @param  bool|null  $updating  If null then we check for existence of the file
     * @return array|false item info array
     */
    protected function upload($path, $contents, Config $config, $updating = null)
    {
        $this->refreshToken();
        [$parentId, $fileName] = $this->splitPath($path);
        $mime = $config->get('mimetype');
        $file = new DriveFile();

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
            $mime = Util::guessMimeType($fileName, is_string($contents) ? $contents : '');
            if (empty($mime)) {
                $mime = 'application/octet-stream';
            }
        }
        $file->setMimeType($mime);

        /** @var StreamInterface $stream */
        $stream = Utils::streamFor($contents);
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

        if (isset($obj) && $obj instanceof DriveFile) {
            $this->cacheFileObjects[$obj->getId()] = $obj;
            $this->cacheObjects([$obj->getId() => $obj]);
            $result = $this->normaliseObject($obj, self::dirname($path));
            if ($this->useDisplayPaths) {
                $this->cachedPaths[$result['display_path']] = $obj->getId();
            }

            if (($visibility = $config->get('visibility'))) {
                if ($this->setVisibility($result['virtual_path'], $visibility, true)) {
                    $result['visibility'] = $visibility;
                }
            }

            return $result;
        }
        return false;
    }

    /**
     * @param  array  $ids
     * @param  bool  $checkDir
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
            $this->refreshToken();
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
                    if ($value instanceof DriveFile) {
                        $itemId = $value->getId();
                        $this->cacheFileObjects[$itemId] = $value;
                        if (!$this->rootId && strcmp($key, 'response-rootdir') === 0) {
                            $this->rootId = $itemId;
                        }
                    } else {
                        if ($checkDir && $value instanceof FileList) {
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

                /* @var DriveFile $obj */
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

    public function uncacheFolder($path)
    {
        if ($this->useDisplayPaths) {
            try {
                $path_id = $this->getCachedPathId($path);
                if (is_array($path_id) && !empty($path_id[0] ?? null)) {
                    $this->uncacheId($path_id[0]);
                }
            } catch (FileNotFoundException $e) {
                // unnecesary
            }
        } else {
            $this->uncacheId($path);
        }
    }

    protected function uncacheId($id)
    {
        if (empty($id)) {
            return;
        }
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
            if ($value instanceof DriveFile) {
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

                $query = $is_last ? [] : ['mimeType = "'.self::DIRMIME.'"'];
                $query[] = "name = '{$token}'";
                $items[] = $this->getItems($id, false, 0, implode(' and ', $query));
                if (DEBUG_ME) {
                    echo " ...done\n";
                }
            }
            if (!empty($items)) {
                /** @noinspection SlowArrayOperationsInLoopInspection */
                $items = array_merge(...$items);
            }

            $nextItemId = null;
            foreach ($items as $item) {
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
     *
     * @param  string  $displayPath
     * @param  bool  $returnFirstItem  return first item only
     * @return string[]|string
     *
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
                throw new FileNotFoundException($displayPath);
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
            throw new FileNotFoundException($displayPath);
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
     *
     * @param  string  $displayPath
     * @param  bool  $makeFullVirtualPath
     * @param  bool  $returnFirstItem
     * @return string[]|string Single itemId/path or array of them
     *
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
            throw new FileNotFoundException($displayPath);
        }

        return $this->returnSingle($this->cachedPaths[$displayPath], $returnFirstItem);
    }

    /**
     * Convert virtual path to display path
     *
     * @param  string  $virtualPath
     * @return string
     *
     * @throws FileNotFoundException
     */
    protected function toDisplayPath($virtualPath)
    {
        if ($virtualPath === '' || $virtualPath === '/') {
            return '/';
        }

        $tokens = explode('/', trim($virtualPath, '/'));

        /** @var DriveFile[] $objects */
        $objects = $this->getObjects($tokens);
        $display = '';
        foreach ($tokens as $token) {
            if (!isset($objects[$token])) {
                throw new FileNotFoundException($virtualPath);
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
        } catch (FileNotFoundException $e) {
            if (!$createDirsIfNeeded) {
                if ($can_throw) {
                    throw $e;
                }
                return false;
            }

            $subdir = $is_dir ? $displayPath : self::dirname($displayPath);
            if ($subdir === '' || $this->createDir($subdir, new Config(), true) === false) {
                if ($can_throw) {
                    throw $e;
                }
                return false;
            }

            try {
                $path = $this->toVirtualPath($displayPath, $makeFullVirtualPath, true);
            } catch (FileNotFoundException $e) {
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
     * @param  string|string[]  $id
     * @param  bool  $reset_all
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
        $path = Util::normalizeDirname(dirname($path));
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
     * Enables empty google drive trash
     *
     * @return void
     *
     * @see https://developers.google.com/drive/v3/reference/files emptyTrash
     * @see \Google_Service_Drive_Resource_Files
     */
    public function emptyTrash(array $params = [])
    {
        $this->refreshToken();
        $this->service->files->emptyTrash($this->applyDefaultParams($params, 'files.emptyTrash'));
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
                'files.watch', 'permissions.list'
            ], ['supportsAllDrives' => true]),
            $this->optParams
        );
    }

    /**
     * Selects Team Drive to operate by changing default parameters
     *
     * @param  string  $teamDriveId  Team Drive id
     * @param  string  $corpora  Corpora value for files.list
     * @return void
     *
     * @see https://developers.google.com/drive/v3/reference/files
     * @see https://developers.google.com/drive/v3/reference/files/list
     * @see \Google_Service_Drive_Resource_Files
     */
    public function setTeamDriveId($teamDriveId, $corpora = 'drive')
    {
        $this->enableTeamDriveSupport();
        $this->optParams = array_merge_recursive($this->optParams, [
            'files.list' => [
                'corpora' => $corpora,
                'includeItemsFromAllDrives' => true,
                'driveId' => $teamDriveId
            ]
        ]);

        if ($this->root === 'root' || $this->root === null) {
            $this->setPathPrefix('');
            $this->root = $teamDriveId;
        }
    }
}
