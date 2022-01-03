<?php

namespace Masbug\Flysystem;

/*
 * Modified work Copyright (c) 2017 Mitja Spes
 *      Changes: Added support for streams.
 *
 * Original work Copyright 2012 Google Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

use Google\Client;
use Google\Exception as GoogleException;
use Google\Http\REST;
use GuzzleHttp\Psr7\LimitStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;

/**
 * Manage large file uploads, which may be media but can be any type
 * of sizable data.
 */
class StreamableUpload
{
    const UPLOAD_MEDIA_TYPE = 'media';
    const UPLOAD_MULTIPART_TYPE = 'multipart';
    const UPLOAD_RESUMABLE_TYPE = 'resumable';

    /** @var string */
    private $mimeType;

    /** @var null|StreamInterface */
    private $data;

    /** @var bool */
    private $resumable;

    /** @var int */
    private $chunkSize;

    /** @var int|string */
    private $size;

    /** @var string */
    private $resumeUri;

    /** @var int */
    private $progress;

    /** @var Client */
    private $client;

    /** @var \Psr\Http\Message\RequestInterface */
    private $request;

    /** @var string */
    private $boundary;

    /**
     * Result code from last HTTP call
     *
     * @var int
     */
    private $httpResultCode;

    /**
     * @param  Client  $client
     * @param  RequestInterface  $request
     * @param  string  $mimeType
     * @param  null|string|resource|StreamInterface  $data  Data you want to upload
     * @param  bool  $resumable
     * @param  bool|int  $chunkSize  File will be uploaded in chunks of this many bytes.
     *                               Only used if resumable=True.
     */
    public function __construct(
        $client,
        RequestInterface $request,
        $mimeType,
        $data,
        $resumable = false,
        $chunkSize = false
    ) {
        $this->client = $client;
        $this->request = $request;
        $this->mimeType = $mimeType;
        $this->data = $data !== null ? Utils::streamFor($data) : null;
        $this->resumable = $resumable;
        $this->chunkSize = is_bool($chunkSize) ? 0 : $chunkSize;
        $this->progress = 0;
        $this->size = '*';
        if ($this->data !== null) {
            $size = $this->data->getSize();
            if ($size !== null) {
                $this->size = $size;
            }
        }

        $this->process();
    }

    /**
     * Set the size of the file that is being uploaded.
     *
     * @param  int  $size  file size in bytes
     */
    public function setFileSize($size)
    {
        $this->size = $size;
    }

    /**
     * Return the progress on the upload
     *
     * @return int progress in bytes uploaded.
     */
    public function getProgress()
    {
        return $this->progress;
    }

    /**
     * Send the next part of the file to upload.
     *
     * @param  null|bool|string|StreamInterface  $chunk  The next set of bytes to send. If stream is provided then chunkSize is ignored.
     *                                                   If false it will use $this->data set at construct time.
     * @return false|mixed
     */
    public function nextChunk($chunk = false)
    {
        $resumeUri = $this->getResumeUri();

        if ($chunk === null || is_bool($chunk)) {
            if ($this->chunkSize < 1) {
                throw new \InvalidArgumentException('Invalid chunk size');
            }
            if (!$this->data instanceof StreamInterface) {
                throw new \InvalidArgumentException('Invalid data stream');
            }
            $this->data->seek($this->progress, SEEK_SET);
            if ($this->data->eof()) {
                return true; // finished
            }
            $chunk = new LimitStream($this->data, $this->chunkSize, $this->data->tell());
        } else {
            $chunk = Utils::streamFor($chunk);
        }
        $size = $chunk->getSize();

        if ($size === null) {
            throw new \InvalidArgumentException('Chunk doesn\'t support getSize');
        } else {
            if ($size < 1) {
                return true; // finished
            }

            $lastBytePos = $this->progress + $size - 1;
            $headers = [
                'content-range'  => 'bytes '.$this->progress.'-'.$lastBytePos.'/'.$this->size,
                'content-length' => $size,
                'expect'         => '',
            ];
        }

        $request = new Request(
            'PUT',
            $resumeUri,
            $headers,
            $chunk
        );

        return $this->makePutRequest($request);
    }

    /**
     * Return the HTTP result code from the last call made.
     *
     * @return int code
     */
    public function getHttpResultCode()
    {
        return $this->httpResultCode;
    }

    /**
     * Sends a PUT-Request to google drive and parses the response,
     * setting the appropriate variables from the response()
     *
     * @param  RequestInterface  $request  the request which will be sent
     * @return false|mixed false when the upload is unfinished or the decoded http response
     */
    private function makePutRequest(RequestInterface $request)
    {
        /** @var ResponseInterface $response */
        $response = $this->client->execute($request);
        $this->httpResultCode = $response->getStatusCode();

        if (308 == $this->httpResultCode) {
            // Track the amount uploaded.
            $range = $response->getHeaderLine('range');
            if ($range) {
                $range_array = explode('-', $range);
                $this->progress = $range_array[1] + 1;
            }

            // Allow for changing upload URLs.
            $location = $response->getHeaderLine('location');
            if ($location) {
                $this->resumeUri = $location;
            }

            // No problems, but upload not complete.
            return false;
        }

        return REST::decodeHttpResponse($response, $this->request);
    }

    /**
     * Resume a previously unfinished upload
     *
     * @param  string  $resumeUri  The resume-URI of the unfinished, resumable upload.
     * @return false|mixed
     */
    public function resume($resumeUri)
    {
        $this->resumeUri = $resumeUri;
        $headers = [
            'content-range'  => 'bytes */'.$this->size,
            'content-length' => 0,
        ];
        $httpRequest = new Request(
            'PUT',
            $this->resumeUri,
            $headers
        );

        return $this->makePutRequest($httpRequest);
    }

    /**
     * @return \Psr\Http\Message\RequestInterface $request
     * @visible for testing
     */
    private function process()
    {
        $this->transformToUploadUrl();
        $request = $this->request;

        $postBody = '';
        $contentType = false;

        $meta = (string)$request->getBody();
        $meta = is_string($meta) ? json_decode($meta, true) : $meta;

        $uploadType = $this->getUploadType($meta);
        $request = $request->withUri(
            Uri::withQueryValue($request->getUri(), 'uploadType', $uploadType)
        );

        $mimeType = $this->mimeType ?: $request->getHeaderLine('content-type');

        if (self::UPLOAD_RESUMABLE_TYPE == $uploadType) {
            $contentType = $mimeType;
            $postBody = is_string($meta) ? $meta : json_encode($meta);
        } else {
            if (self::UPLOAD_MEDIA_TYPE == $uploadType) {
                $contentType = $mimeType;
                $postBody = $this->data;
            } else {
                if (self::UPLOAD_MULTIPART_TYPE == $uploadType) {
                    // This is a multipart/related upload.
                    $boundary = $this->boundary ?: /* @scrutinizer ignore-call */ mt_rand();
                    $boundary = str_replace('"', '', $boundary);
                    $contentType = 'multipart/related; boundary='.$boundary;
                    $related = "--$boundary\r\n";
                    $related .= "Content-Type: application/json; charset=UTF-8\r\n";
                    $related .= "\r\n".json_encode($meta)."\r\n";
                    $related .= "--$boundary\r\n";
                    $related .= "Content-Type: $mimeType\r\n";
                    $related .= "Content-Transfer-Encoding: base64\r\n";
                    $related .= "\r\n".base64_encode(/** @scrutinizer ignore-type */ $this->data)."\r\n";
                    $related .= "--$boundary--";
                    $postBody = $related;
                }
            }
        }

        $request = $request->withBody(Utils::streamFor($postBody));

        if (isset($contentType) && $contentType) {
            $request = $request->withHeader('content-type', $contentType);
        }

        return $this->request = $request;
    }

    /**
     * Valid upload types:
     * - resumable (UPLOAD_RESUMABLE_TYPE)
     * - media (UPLOAD_MEDIA_TYPE)
     * - multipart (UPLOAD_MULTIPART_TYPE)
     *
     * @param $meta
     * @return string
     * @visible for testing
     */
    public function getUploadType($meta)
    {
        if ($this->resumable) {
            return self::UPLOAD_RESUMABLE_TYPE;
        }

        if (false == $meta && $this->data) {
            return self::UPLOAD_MEDIA_TYPE;
        }

        return self::UPLOAD_MULTIPART_TYPE;
    }

    public function getResumeUri()
    {
        if (null === $this->resumeUri) {
            $this->resumeUri = $this->fetchResumeUri();
        }

        return $this->resumeUri;
    }

    private function fetchResumeUri()
    {
        $body = $this->request->getBody();
        if ($body) {
            $headers = [
                'content-type'          => 'application/json; charset=UTF-8',
                'content-length'        => $body->getSize(),
                'x-upload-content-type' => $this->mimeType,
                'expect'                => '',
            ];
            if (is_int($this->size)) {
                $headers['x-upload-content-length'] = $this->size;
            }

            foreach ($headers as $key => $value) {
                $this->request = $this->request->withHeader($key, $value);
            }
        }

        $response = $this->client->execute($this->request, /** @scrutinizer ignore-type */ false);
        $location = $response->getHeaderLine('location');
        $code = $response->getStatusCode();

        if (200 == $code && true == $location) {
            return $location;
        }

        $message = $code;
        $body = json_decode((string)$this->request->getBody(), true);
        if (isset($body['error']['errors'])) {
            $message .= ': ';
            foreach ($body['error']['errors'] as $error) {
                $message .= $error['domain'].', '.$error['message'].';';
            }
            $message = rtrim($message, ';');
        }

        $error = "Failed to start the resumable upload (HTTP {$message})";
        $this->client->getLogger()->error($error);

        throw new GoogleException($error);
    }

    private function transformToUploadUrl()
    {
        $parts = parse_url((string)$this->request->getUri());
        if (!isset($parts['path'])) {
            $parts['path'] = '';
        }
        $parts['path'] = '/upload'.$parts['path'];
        $uri = Uri::fromParts($parts);
        $this->request = $this->request->withUri($uri);
    }

    public function setChunkSize($chunkSize)
    {
        $this->chunkSize = $chunkSize;
    }

    public function getRequest()
    {
        return $this->request;
    }
}
