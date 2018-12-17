<?php
/**
 * Created by PhpStorm.
 * User: dljy-technology
 * Date: 2018/12/17
 * Time: 下午2:04
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
     * @param $accessKey
     * @param $secretKey
     * @param $bucket
     * @param $endpoint
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

    protected function mapFileInfo($path, $requireMeta = false, $options = []){
        $this->client->signUrl($this->bucket, $path);
        $normalized = [
            'type' => 'file',
            'path' => $path,
        ];

        if ($requireMeta){
            $normalized = $this->getFileMeta($path, $normalized);
        }
        $normalized = array_merge($normalized, $options);
        return $normalized;
    }

    protected function mapDirInfo($dirname)
    {
        if (substr($dirname, -1) == '/'){
            $dirname = substr($dirname, 0, -1);
        }
        $normalized = ['path' => $dirname, 'type' => 'dir'];
        return $normalized;
    }

    public function write($path, $contents, Config $config)
    {
        return $this->client->putObject($this->bucket, $path, $contents);
    }

    public function writeStream($path, $resource, Config $config)
    {
        $result = $this->write($path, stream_get_contents($resource), $config);
        if (is_resource($resource)) {
            fclose($resource);
        }
        return $result;
    }

    public function update($path, $contents, Config $config)
    {
        return $this->write($path, $contents,  $config);
    }

    public function updateStream($path, $resource, Config $config)
    {
        return $this->writeStream($path, $resource, $config);
    }

    public function rename($path, $newpath)
    {
        return $this->copy($path, $newpath) && $this->delete($path);
    }

    public function copy($path, $newpath)
    {
        $result = $this->client->copyObject($this->bucket, $path, $this->bucket,  $newpath);
        return !!$result;
    }

    public function delete($path)
    {
        $result = $this->client->deleteObject($this->bucket, $path);
        return !!$result;
    }

    public function deleteDir($dirname)
    {
        $dir = $this->dirname($dirname);
        $result = $this->listContents($dir);
        if ($result){
            $files = array_column($result, 'path');
            $this->client->deleteObjects($this->bucket, $files);
        }
        return $this->delete($dir);
    }

    public function createDir($dirname, Config $config)
    {
        $result = $this->client->createObjectDir($this->bucket, $dirname);
        return !!$result;
    }

    public function setVisibility($path, $visibility)
    {
        $visibility = $visibility == 'public' ? 'public-read' : 'private';
        $this->client->putObjectAcl($this->bucket, $path, $visibility);
        return true;
    }

    public function has($path)
    {
        return $this->client->doesObjectExist($this->bucket, $path);
    }

    public function read($path)
    {
        $object = $this->client->getObject($this->bucket, $path);
        $fileInfo = $this->mapFileInfo($path, false, [
            'contents' => $object,
        ]);
        return $fileInfo;
    }

    public function readStream($path)
    {
        $url = $this->client->signUrl($this->bucket, $path, 3600);
        $stream = fopen($url, 'rb');
        $fileInfo = $this->mapFileInfo($path, false, ['stream'=>$stream]);
        return $fileInfo;
    }

    public function listContents($directory = '', $recursive = false)
    {
        $options = [];
        $dirname = $this->dirname($directory);
        if ($dirname != '/'){
            $options = [
                'prefix' => $dirname,
            ];
        }
        $objects = $this->client->listObjects($this->bucket, $options);
        $files = $objects->getObjectList();
        $results = [];
        foreach ($files as $file){
            $results[] = $this->mapFileInfo($file->getKey(), false, [
                'timestamp' => (new \DateTime($file->getLastModified()))->getTimestamp(),
                'size'      => $file->getSize(),
            ]);
        }
        $dirs = $objects->getPrefixList();
        foreach ($dirs as $dir){
            $results[] = $this->mapDirInfo($dir->getPrefix());
        }
        return $results;
    }

    public function getMetadata($path)
    {
        $metaData = $this->mapFileInfo($path, true);
        return $metaData;
    }

    public function getSize($path)
    {
        $metaData = $this->getMetadata($path);
        return $metaData['size'];
    }

    public function getMimetype($path)
    {
        return $this->getMetadata($path);
    }

    public function getTimestamp($path)
    {
        $meta = $this->getMetadata($path);
        return $meta['timestamp'];
    }

    public function getVisibility($path)
    {
        $response = $this->client->getObjectAcl($this->bucket, $path);
        return [
            'visibility' => $response,
        ];
    }

    /**
     * @param $dirname
     * @return string
     */
    protected function dirname($dirname): string
    {
        $dirname = substr(0, -1) == '/' ? $dirname : $dirname . '/';
        return $dirname;
    }


}