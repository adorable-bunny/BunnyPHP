<?php
/**
 * Created by PhpStorm.
 * User: IvanLu
 * Date: 2018/10/31
 * Time: 14:09
 */

class FileCache implements Cache
{
    protected $dir;
    protected $cacheDir;

    public function __construct($config)
    {
        $this->dir = isset($config['dir']) ? $config['dir'] : 'cache';
        $this->cacheDir = APP_PATH . $this->dir . '/';
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0555, true);
        }
    }

    public function get($key, $expire = 0)
    {
        $filename = $this->cacheDir . md5($key);
        if (file_exists($filename)) {
            if (filemtime($filename) + $expire > time()) {
                return file_get_contents($this->cacheDir . md5($key));
            } else {
                unlink($filename);
                return null;
            }
        } else {
            return null;
        }
    }

    public function has($key, $expire = 0)
    {
        $filename = $this->cacheDir . md5($key);
        return file_exists($filename) && (filemtime($filename) + $expire > time());
    }

    public function set($key, $value, $expire = 0)
    {
        file_put_contents($this->cacheDir . md5($key), $value);
    }

    public function del($key)
    {
        unlink($this->cacheDir . md5($key));
    }
}