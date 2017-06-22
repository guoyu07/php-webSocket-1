<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-28
 * Time: 17:03
 */

namespace inhere\webSocket\traits;

use inhere\library\helpers\CliHelper;

/**
 * Trait ProcessLogTrait
 * @package inhere\webSocket\traits
 */
trait ProcessLogTrait
{
    /**
     * Logging levels
     * @var array $levels Logging levels
     */
    protected static $levels = [
        self::LOG_EMERG => 'EMERGENCY',
        self::LOG_ERROR => 'ERROR',
        self::LOG_WARN => 'WARNING',
        self::LOG_INFO => 'INFO',
        self::LOG_PROC_INFO => 'PROC_INFO',
        self::LOG_WORKER_INFO => 'WORKER_INFO',
        self::LOG_DEBUG => 'DEBUG',
        self::LOG_CRAZY => 'CRAZY',
    ];

    /**
     * @var int
     */
    protected $logLevel;

    /**
     * current log file
     * @var string
     */
    protected $logFile;

    /**
     * Holds the resource for the log file
     * @var resource
     */
    protected $logFileHandle;

    /**
     * debug log
     * @param  string $msg
     * @param  array $data
     */
    public function debug($msg, array $data = [])
    {
        $this->log($msg, self::LOG_DEBUG, $data);
    }

    /**
     * Logs data to disk or stdout
     * @param string $msg
     * @param int $level
     * @param array $data
     * @return bool
     */
    public function log($msg, $level = self::LOG_INFO, array $data = [])
    {
        if ($level > $this->logLevel) {
            return true;
        }

        $data = $data ? json_encode($data) : '';

        if ($this->get('log_syslog')) {
            return $this->sysLog($msg . ' ' . $data, $level);
        }

        $label = isset(self::$levels[$level]) ? self::$levels[$level] : self::LOG_INFO;

        list($ts, $ms) = explode('.', sprintf('%.4f', microtime(true)));
        $ds = date('Y/m/d H:i:s', $ts) . '.' . $ms;

        $logString = sprintf(
            '[%s] [%s:%d] [%s] %s %s' . PHP_EOL,
            $ds, $this->getPidRole(), $this->pid, $label, trim($msg), $data
        );

        // if not in daemon, print log to \STDOUT
        if (!$this->isDaemon()) {
            $this->stdout($logString, false);
        }

        if ($this->logFileHandle) {
            // updateLogFile
            $this->updateLogFile();

            fwrite($this->logFileHandle, $logString);
        }

        return true;
    }

    /**
     * update the log file name. If 'log_split' is not empty and manager running to long time.
     */
    protected function updateLogFile()
    {
        if (!$this->logFileHandle || !($file = $this->logFile)) {
            return false;
        }

        static $lastCheckTime;

        if (!$lastCheckTime) {
            $lastCheckTime = time();
        }

        if (time() - $lastCheckTime < self::LOG_CHECK_INTERVAL) {
            $lastCheckTime = time();
            return false;
        }

        $lastCheckTime = time();
        $logFile = $this->genLogFile(true);

        // update
        if ($file !== $logFile) {
            if ($this->logFileHandle) {
                fclose($this->logFileHandle);
            }

            $this->logFile = $logFile;
            $this->logFileHandle = @fopen($logFile, 'ab');

            if (!$this->logFileHandle) {
                $this->showHelp("Could not open the log file {$logFile}");
            }
        }

        return false;
    }

    /**
     * Opens the log file. If already open, closes it first.
     */
    protected function openLogFile()
    {
        if ($logFile = $this->genLogFile(true)) {
            if ($this->logFileHandle) {
                fclose($this->logFileHandle);
            }

            $this->logFile = $logFile;
            $this->logFileHandle = @fopen($logFile, 'ab');

            if (!$this->logFileHandle) {
                $this->showHelp("Could not open the log file {$logFile}");
            }
        }
    }

    /**
     * gen real LogFile
     * @param bool $createDir
     * @return string
     */
    public function genLogFile($createDir = false)
    {
        // log split type
        if (!($type = $this->config['log_split']) || !($file = $this->config['log_file'])) {
            return $this->config['log_file'];
        }

        if (!in_array($type, [self::LOG_SPLIT_DAY, self::LOG_SPLIT_HOUR])) {
            $type = self::LOG_SPLIT_DAY;
        }

        $info = pathinfo($file);
        $dir = $info['dirname'];
        $name = isset($info['filename']) ? $info['filename'] : 'gw_manager';
        $ext = isset($info['extension']) ? $info['extension'] : 'log';

        if ($type === self::LOG_SPLIT_DAY) {
            $str = date('Y-m-d');
        } else {
            $str = date('Y-m-d_H');
        }

        if ($createDir && !is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return "{$dir}/{$name}_{$str}.{$ext}";
    }

    /**
     * Logs data to stdout
     * @param string $text
     * @param bool $nl
     * @param bool|int $quit
     */
    protected function stdout($text, $nl = true, $quit = false)
    {
        CliHelper::stdout($text, $nl, $quit);
    }

    /**
     * Logs data to stderr
     * @param string $text
     * @param bool $nl
     * @param bool|int $quit
     */
    protected function stderr($text, $nl = true, $quit = -200)
    {
        CliHelper::stderr($text, $nl, $quit);
    }

    /**
     * Logs data to the syslog
     * @param string $msg
     * @param int $level
     * @return bool
     */
    protected function sysLog($msg, $level)
    {
        switch ($level) {
            case self::LOG_EMERG:
                $priority = LOG_EMERG;
                break;
            case self::LOG_ERROR:
                $priority = LOG_ERR;
                break;
            case self::LOG_WARN:
                $priority = LOG_WARNING;
                break;
            case self::LOG_DEBUG:
                $priority = LOG_DEBUG;
                break;
            case self::LOG_INFO:
            case self::LOG_PROC_INFO:
            case self::LOG_WORKER_INFO:
            default:
                $priority = LOG_INFO;
                break;
        }

        if (!$ret = syslog($priority, $msg)) {
            $this->stderr("Unable to write to syslog\n");
        }

        return $ret;
    }

    /**
     * getLogFile
     * @return string
     */
    public function getLogFile()
    {
        return $this->logFile;
    }
}
