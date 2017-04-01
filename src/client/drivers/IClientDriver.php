<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-04-01
 * Time: 12:41
 */

namespace inhere\webSocket\client\drivers;

/**
 * Interface IClientDriver
 * @package inhere\webSocket\client\drivers
 */
interface IClientDriver
{
    const ON_CONNECT   = 'connect';
    const ON_OPEN      = 'open';
    const ON_MESSAGE   = 'message';
    const ON_CLOSE     = 'close';
    const ON_ERROR     = 'error';

    /**
     * @return bool
     */
    public static function isSupported();

    /**
     * @param array $options
     * @return mixed
     */
    public function setOptions(array $options);

    /**
     * @param string $event
     * @param callable $cb
     * @param bool $replace
     * @return mixed
     */
    public function on(string $event, callable $cb, bool $replace = false);

    /**
     * @param string $host `127.0.0.1`
     * @param int $port `9501`
     * @param float $timeout
     * @param int $flag
     * @return mixed
     */
    public function connect($host, $port, $timeout = 0.1, $flag = 0);

    /**
     * @return bool
     */
    public function isConnected();

    /**
     * @return resource
     */
    public function getSocket();

    /**
     * 用于获取客户端socket的本地host:port，必须在连接之后才可以使用
     * @return array
     */
    public function getSockName();

    /**
     * 函数必须在$client->receive() 之后调用
     * @return mixed
     */
    public function getPeerName();

    /**
     * 获取服务器端证书信息
     * @return mixed
     */
    public function getPeerCert();

    /**
     * @param string $message
     * @param null|int $flag
     * @return mixed
     */
    public function send($message, $flag = null);

    /**
     * @param string $ip
     * @param int $port
     * @param string $data
     * @return mixed
     */
    public function sendTo(string $ip, int $port, string $data);

    public function sendFile(string $filename);

    public function receive($size = null, $flag = null);

    public function close(bool $force = false);

    public function sleep();

    public function wakeUp();

    public function enableSSL();
}