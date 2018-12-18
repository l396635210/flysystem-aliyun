## AliYun OSS(阿里云对象存储) Adapter For Flysystem.
[![Software License](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE)

Flysystem 适配器： [阿里云](https://help.aliyun.com/document_detail/32099.html)

## Installation
composer require liz/flysystem-aliyun

## Usage
```php
require 'vendor/autoload.php';


use League\Flysystem\Filesystem;
use Liz\Flysystem\AliYun\AliYunOssAdapter;
$endpoint = 'oss-cn-beijing.aliyuncs.com';
$bucket = 'bucket'; 
$accessKey = 'access-key';
$secretKey = 'secret-key';

// write file
$result = $flysystem->write('bucket/path/file.txt', 'contents');

// write stream
$stream = fopen('.env', 'r+');
$result = $flysystem->writeStream('bucket/path/filestream.txt', $stream);

// update file
$result = $flysystem->update('bucket/path/file.txt', 'new contents');

// has file
$result = $flysystem->has('bucket/path/file.txt');

// read file
$result = $flysystem->read('bucket/path/file.txt');

// delete file
$result = $flysystem->delete('bucket/path/file.txt');

// rename files
$result = $flysystem->rename('bucket/path/filename.txt', 'bucket/path/newname.txt');

// copy files
$result = $flysystem->copy('bucket/path/file.txt', 'bucket/path/file_copy.txt');

// list the contents
$result = $flysystem->listContents('path', false);
```

## Notice
由于阿里云没有文件夹的概念，建议顶级目录同bucket名
`getVisibility()`,`setVisibility()`阿里云没有提供相关操作