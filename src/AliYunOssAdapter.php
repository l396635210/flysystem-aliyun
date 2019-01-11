<?php
/**
 * Created by PhpStorm.
 * User: dljy-technology
 * Date: 2018/12/17
 * Time: 下午2:04.
 */

namespace Liz\Flysystem\AliYun;

use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Config;
use OSS\OssClient;

class AliYunOssAdapter extends AbstractAdapter
{
    private $accessKey;

    private $secretKey;

    private $bucket;

    private $endpoint;

    private $client;

    /**
     * AliOssAdapter constructor.
     *
     * @param $accessKey
     * @param $secretKey
     * @param $bucket
     * @param $endpoint
     *
     * @throws \OSS\Core\OssException
     */
    public function __construct($accessKey, $secretKey, $bucket, $endpoint)
    {
        $this->accessKey = $accessKey;
        $this->secretKey = $secretKey;
        $this->bucket = $bucket;
        $this->endpoint = $endpoint;

        $this->client = new OssClient($this->accessKey, $this->secretKey, $endpoint);
    }

    /**
     * @param $path
     * @param $normalized
     *
     * @return array
     */
    protected function getFileMeta($path, $normalized)
    {
        $response = $this->client->getObjectMeta($this->bucket, $path);
        $meta = $response['info'];
        $normalized['mimetype'] = $meta['content_type'];
        $normalized['timestamp'] = $meta['filetime'];
        $normalized['size'] = $meta['download_content_length'];
        return $normalized;
    }

    /**
     * @param $path
     * @param bool  $requireMeta
     * @param array $options
     *
     * @return array
     *
     * @throws \OSS\Core\OssException
     */
    protected function mapFileInfo($path, $requireMeta = false, $options = [])
    {
        $this->client->signUrl($this->bucket, $path);
        $normalized = [
            'type' => 'file',
            'path' => $path,
        ];

        if ($requireMeta) {
            $normalized = $this->getFileMeta($path, $normalized);
        }
        $normalized = array_merge($normalized, $options);
        return $normalized;
    }

    /**
     * @param $dirname
     *
     * @return array
     */
    protected function mapDirInfo($dirname)
    {
        if ('/' === substr($dirname, -1)) {
            $dirname = substr($dirname, 0, -1);
        }
        return ['path' => $dirname, 'type' => 'dir'];
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|false|null
     */
    public function write($path, $contents, Config $config)
    {
        return $this->client->putObject($this->bucket, $path, $contents);
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @return array|false|null
     */
    public function writeStream($path, $resource, Config $config)
    {
        $result = $this->write($path, stream_get_contents($resource), $config);
        if (is_resource($resource)) {
            fclose($resource);
        }
        return $result;
    }

    /**
     * @param string $path
     * @param string $contents
     * @param Config $config
     *
     * @return array|false|null
     */
    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents, $config);
    }

    /**
     * @param string   $path
     * @param resource $resource
     * @param Config   $config
     *
     * @return array|false|null
     */
    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     */
    public function rename($path, $newpath)
    {
        return $this->copy($path, $newpath) && $this->delete($path);
    }

    /**
     * @param string $path
     * @param string $newpath
     *
     * @return bool
     *
     * @throws \OSS\Core\OssException
     */
    public function copy($path, $newpath)
    {
        $result = $this->client->copyObject($this->bucket, $path, $this->bucket, $newpath);
        return (bool) $result;
    }

    /**
     * @param string $path
     *
     * @return bool
     */
    public function delete($path)
    {
        $result = $this->client->deleteObject($this->bucket, $path);
        return (bool) $result;
    }

    /**
     * @param string $dirname
     *
     * @return bool
     */
    public function deleteDir($dirname)
    {
        $dir = $this->dirname($dirname);
        $result = $this->listContents($dir);
        if ($result) {
            $files = array_column($result, 'path');
            $this->client->deleteObjects($this->bucket, $files);
        }
        return $this->delete($dir);
    }

    /**
     * @param string $dirname
     * @param Config $config
     *
     * @return array|bool|false
     */
    public function createDir($dirname, Config $config)
    {
        $result = $this->client->createObjectDir($this->bucket, $dirname);
        return (bool) $result;
    }

    /**
     * @param string $path
     * @param string $visibility
     *
     * @return array|bool|false
     *
     * @throws \OSS\Core\OssException
     */
    public function setVisibility($path, $visibility)
    {
        $visibility = 'public' === $visibility ? 'public-read' : 'private';
        $this->client->putObjectAcl($this->bucket, $path, $visibility);
        return true;
    }

    /**
     * @param string $path
     *
     * @return array|bool|null
     */
    public function has($path)
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    /**
     * @param string $path
     *
     * @return array|false
     *
     * @throws \OSS\Core\OssException
     */
    public function read($path)
    {
        $object = $this->client->getObject($this->bucket, $path);
        $fileInfo = $this->mapFileInfo($path, false, [
            'contents' => $object,
        ]);
        return $fileInfo;
    }

    /**
     * @param string $path
     *
     * @return array|false
     *
     * @throws \OSS\Core\OssException
     */
    public function readStream($path)
    {
        $url = $this->client->signUrl($this->bucket, $path, 3600);
        $stream = fopen($url, 'rb');
        return $this->mapFileInfo($path, false, ['stream' => $stream]);
    }

    /**
     * @param string $directory
     * @param bool   $recursive
     *
     * @return array
     *
     * @throws \OSS\Core\OssException
     */
    public function listContents($directory = '', $recursive = false)
    {
        $options = [];
        $dirname = $this->dirname($directory);
        if ('/' !== $dirname) {
            $options = [
                'prefix' => $dirname,
            ];
        }
        $objects = $this->client->listObjects($this->bucket, $options);
        $files = $objects->getObjectList();
        $results = [];
        foreach ($files as $file) {
            if ($file->getKey() !== $dirname) {
                $results[] = $this->mapFileInfo($file->getKey(), false, [
                    'timestamp' => (new \DateTime($file->getLastModified()))->getTimestamp(),
                    'size' => $file->getSize(),
                ]);
            }
        }
        $dirs = $objects->getPrefixList();
        foreach ($dirs as $dir) {
            $results[] = $this->mapDirInfo($dir->getPrefix());
        }

        return $results;
    }

    /**
     * @param string $path
     *
     * @return array|false
     *
     * @throws \OSS\Core\OssException
     */
    public function getMetadata($path)
    {
        return $this->mapFileInfo($path, true);
    }

    /**
     * @param string $path
     *
     * @return array|false|mixed
     *
     * @throws \OSS\Core\OssException
     */
    public function getSize($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     *
     * @return array|false
     *
     * @throws \OSS\Core\OssException
     */
    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     *
     * @return array|false|mixed
     *
     * @throws \OSS\Core\OssException
     */
    public function getTimestamp($path)
    {
        return $this->getMetadata($path);
    }

    /**
     * @param string $path
     *
     * @return array|false
     *
     * @throws \OSS\Core\OssException
     */
    public function getVisibility($path)
    {
        $response = $this->client->getObjectAcl($this->bucket, $path);
        return [
            'visibility' => $response,
        ];
    }

    /**
     * @param $dirname
     *
     * @return string
     */
    protected function dirname($dirname): string
    {
        $dirname = '/' === substr(0, -1) ? $dirname : $dirname.'/';
        return $dirname;
    }
}
