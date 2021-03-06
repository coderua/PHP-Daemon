<?php

namespace Theintz\PhpDaemon\Lock;

use Theintz\PhpDaemon\Daemon;
use Theintz\PhpDaemon\Exception;
use Theintz\PhpDaemon\IPlugin;

/**
 * Use a lock file. The PID will be set as the file contents, and the filemtime will be used to determine
 * expiration.
 *
 * @author Shane Harter
 * @since 2011-07-29
 */
class File extends Lock implements IPlugin
{
    /**
     * The directory where the lockfile will be written. The filename will be whatever you set the $daemon_name to be.
     * To use the current directory, define and use a BASE_PATH constant: Using ./ will fail when the script is
     * run from crontab.
     *
     * @var string  A filesystem path
     */
    public $path = '';

    protected $filename;

    public function __construct(Daemon $daemon, array $args = array())
    {
        parent::__construct($daemon, $args);
        if (isset($args['path']))
            $this->path = $args['path'];
        else
            $this->path = dirname($daemon->get('filename'));
    }

    public function setup()
    {
        if (substr($this->path, -1, 1) != '/')
            $this->path .= '/';

        $this->filename = $this->path . str_replace('\\', '_', $this->daemon_name) . '.' . Lock::$LOCK_UNIQUE_ID;
    }

    public function teardown()
    {
        // If the lockfile was set by this process, remove it. If filename is empty, this is being called before setup()
        if (!empty($this->filename) && file_exists($this->filename) && getmypid() == @file_get_contents($this->filename))
            @unlink($this->filename);
    }

    public function check_environment(array $errors = array())
    {
        if (is_writable($this->path) == false)
            $errors[] = 'Lock File Path ' . $this->path . ' Not Writable.';

        return $errors;
    }

    public function set()
    {
        $lock = $this->check();

        if ($lock)
            throw new Exception('File::set Failed. Additional Lock Detected. PID: ' . $lock);

        // The lock value will contain the process PID
        file_put_contents($this->filename, $this->pid);

        touch($this->filename);
    }

    protected function get()
    {
        if (file_exists($this->filename) == false)
            return false;

        $lock = file_get_contents($this->filename);

        // If we're seeing our own lock..
        if ($lock == $this->pid)
            return false;

        // If the process that wrote the lock is no longer running
        $cmd_output = `ps -p $lock`;
        if (strpos($cmd_output, $lock) === false)
            return false;

        // If the lock is expired
        clearstatcache();
        if ((filemtime($this->filename) + $this->ttl + Lock::$LOCK_TTL_PADDING_SECONDS) < time())
            return false;

        return $lock;
    }
}
