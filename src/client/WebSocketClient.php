<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017-03-27
 * Time: 9:14
 */
namespace inhere\webSocket\client;

use inhere\exceptions\ConnectException;
use inhere\webSocket\BaseWebSocket;
use inhere\webSocket\http\Request;
use inhere\webSocket\http\Response;
use inhere\webSocket\parts\Uri;

/**
 * Class WebSocketClient
 * @package inhere\webSocket
 */
class WebSocketClient
{
    /**
     * version
     */
    const VERSION = '0.5.1';

    const TOKEN_LENGTH = 16;
    const MSG_CONNECTED = 1;
    const MSG_DISCONNECTED = 2;
    const MSG_LOST_CONNECTION = 3;

    const ON_TICK = 'tick';

    const OPCODE_CONTINUE = 0x0;
    const OPCODE_TEXT = 0x1;
    const OPCODE_BINARY = 0x2;
    const OPCODE_NON_CONTROL_RESERVED_1 = 0x3;
    const OPCODE_NON_CONTROL_RESERVED_2 = 0x4;
    const OPCODE_NON_CONTROL_RESERVED_3 = 0x5;
    const OPCODE_NON_CONTROL_RESERVED_4 = 0x6;
    const OPCODE_NON_CONTROL_RESERVED_5 = 0x7;
    const OPCODE_CLOSE = 0x8;
    const OPCODE_PING = 0x9;
    const OPCODE_PONG = 0xA;
    const OPCODE_CONTROL_RESERVED_1 = 0xB;
    const OPCODE_CONTROL_RESERVED_2 = 0xC;
    const OPCODE_CONTROL_RESERVED_3 = 0xD;
    const OPCODE_CONTROL_RESERVED_4 = 0xE;
    const OPCODE_CONTROL_RESERVED_5 = 0xF;

    const DEFAULT_HOST = '127.0.0.1';

    /**
     * eg `ws://127.0.0.1:9501/chat`
     * @var string
     */
    private $url;

    /**
     * `/chat`
     * @var string
     */
    private $path;

    /**
     * @var resource
     */
    private $socket;

    private $key;

    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    /**
     * @var bool
     */
    private $connected = false;

    /**
     * @var array
     */
    protected $options = [
        'debug' => false,

        'open_log' => true,
        'log_file' => '',

        'timeout' => 3,
        'protocol' => 'ws', // wss

        // stream context
        'context' => null,

        'auth' => [
            // 'username'=>"",
            // 'password'=>"",
            // 'type'=>"" // basic | digest
        ],

        // append headers
        'headers' => [
            'origin' => '',
        ],

        // append headers
        'cookies' => [],
    ];


    /**
     * WebSocketClient constructor.
     * @param string $host
     * @param int $port
     * @param string $path
     * @param array $options
     */
    public function __construct(string $host = '127.0.0.1', int $port = 8080, string $path = '/', array $options = [])
    {
        parent::__construct($host, $port, $options);

        $this->path = $path;
        $uri = Uri::createFromString($path); // todo ...
        $this->request = new Request('GET', $uri);

        // Default headers
        $headers = array(
            'Host' => $uri->getHost() . ':' . $uri->getPort(),
            'User-Agent' => 'php-webSocket-client',
            'Connection' => 'Upgrade',
            'Upgrade'   => 'websocket',
            'Sec-Websocket-Key' => $this->genKey(),
            'Sec-Websocket-Version' => self::WS_VERSION,
            'Sec-WebSocket-Protocol' => 'sws',
        );

        // Handle basic authentication.
        if ($user = $uri->getUserInfo()) {
            $headers['authorization'] = 'Basic ' . base64_encode($user);
        }

        $this->request->setHeaders($headers);

        if ($csHeaders = $this->getOption('headers')) {
            $this->request->setHeaders($csHeaders);
        }

        if ($csCookies = $this->getOption('cookies')) {
            $this->request->setCookies($csCookies);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function start()
    {
        $this->connect();

        $tickHandler = $this->callbacks[self::ON_TICK];

        while (true) {
            if ($tickHandler) {
                if (call_user_func($tickHandler, $this) === false) {
                    break;
                }
            }

            usleep(5000);
        }
    }

    public function connect()
    {
        $uri = $this->request->getUri();
        $scheme = $uri->getScheme();

        if (!in_array($scheme, [self::PROTOCOL_WS, self::PROTOCOL_WSS], true)) {
            throw new \InvalidArgumentException("Url should have scheme ws or wss, you setting is: $scheme");
        }

        // Set the stream context options if they're already set in the config
        if ($context = $this->getOption('context')) {
            // Suppress the error since we'll catch it below
            if ( is_resource($context) && get_resource_type($context) !== 'stream-context') {
                throw new \InvalidArgumentException("Stream context in options[context] isn't a valid context resource");
            }
        } else {
            $context = stream_context_create();
        }

        $host = $uri->getHost();
        $port = $uri->getPort();
        $schemeHost = ($scheme === self::PROTOCOL_WSS ? 'ssl' : 'tcp') . "://$host";
        $remote = $schemeHost . ($port ? ":$port" : '');
        $timeout = $this->getOption('timeout');

        // Open the socket.  @ is there to supress warning that we will catch in check below instead.
        $this->socket = stream_socket_client($remote, $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT, $context);

        // can also use: fsockopen — 打开一个网络连接或者一个Unix套接字连接
        // $this->socket = fsockopen($schemeHost, $port, $errNo, $errStr, $timeout);

        if ($this->socket === false) {
            throw new ConnectException("Could not connect socket to $host:$port, Error: $errStr ($errNo).");
        }

        $request = $this->request->toString();
        $this->write((string)$request);

        // Set timeout on the stream as well.
        stream_set_timeout($this->socket, $timeout);

        // Get server response header (terminated with double CR+LF).
        $response = stream_get_line($this->socket, 1024, "\r\n\r\n");

        // Validate response.
        if (!preg_match('#Sec-WebSocket-Accept:\s(.*)$#mUi', $response, $matches)) {
            $address = $scheme . '://' . $host . $uri->getPathAndQuery();
            throw new ConnectException("Connection to '{$address}' failed: Server sent invalid upgrade response:\n $response");
        }
    }

    protected function buildRequest()
    {
        // $parts = parse_url($this->url);

        $uri = Uri::createFromString($this->url);

        $this->request = new Request('GET', $uri);
    }

    protected function write($data)
    {
        $written = fwrite($this->socket, $data);

        if ($written < strlen($data)) {
            throw new ConnectException("Could only write $written out of " . strlen($data) . " bytes.");
        }
    }

    /**
     * http://php.net/manual/zh/stream.examples.php
     * @return array
     */
    protected function getResponseData()
    {
        $header = $body = '';

        // fgets() 从文件指针中读取一行。 从 handle 指向的文件中读取一行并返回长度最多为 length - 1 字节的字符串。
        // 碰到换行符（包括在返回值中）、EOF 或者已经读取了 length - 1 字节后停止（看先碰到那一种情况）。
        // 如果没有指定 length，则默认为 1K，或者说 1024 字节。
        while ($str = trim(fgets($this->socket, 4096))) {
            $header .= "$str\n";
        }

        // feof() — 测试文件指针是否到了文件结束的位置
        while (!feof($this->socket)) {
            $body .= fgets($this->socket, 4096);
        }

        return [$header, $body];
    }

    protected function read($length)
    {
        $data = '';

        while (strlen($data) < $length) {
            $buffer = fread($this->socket, $length - strlen($data));

            if ($buffer === false) {
                $metadata = stream_get_meta_data($this->socket);

                throw new ConnectException('Broken frame, read ' . strlen($data) . ' of stated '
                    . $length . ' bytes.  Stream state: ' . json_encode($metadata)
                );
            }

            if ($buffer === '') {
                $metadata = stream_get_meta_data($this->socket);
                throw new ConnectException('Empty read; connection dead?  Stream state: ' . json_encode($metadata));
            }
            $data .= $buffer;
        }

        return $data;
    }

    protected function close()
    {
        if ( $this->socket ) {
            if (get_resource_type($this->socket) === 'stream') {
                fclose($this->socket);
            }

            $this->socket = null;
        }
    }

    /**
     * Helper to convert a binary to a string of '0' and '1'.
     * @param string $binStr
     * @return string
     */
    protected static function bin2String($binStr)
    {
        $string = '';
        $len = strlen($binStr);

        for ($i = 0; $i < $len; $i++) {
            $string .= sprintf('%08b', ord($binStr[$i]));
        }

        return $string;
    }

    /**
     * Send a message to the server.
     * @param string $data
     * @param string $opCode
     */
    public function send(string $data, string $opCode = 'text')
    {

    }

    /**
     * Read data from a socket
     * @param resource $socket
     */
    public function receive($socket = null)
    {

    }

    public function onOpen(callable $callback)
    {

    }

    public function onMessage(callable $callback)
    {
        $this->callbacks[self::ON_MESSAGE] = $callback;
    }

    public function onClose(callable $callback)
    {

    }

    public function onError(callable $callback)
    {

    }


    /////////////////////////////////////////////////////////////////////////////////////////
    /// getter/setter method
    /////////////////////////////////////////////////////////////////////////////////////////

    /**
     * @return resource
     */
    public function getSocket(): resource
    {
        return $this->socket;
    }

    /**
     * @param resource $socket
     */
    public function setSocket(resource $socket)
    {
        $this->socket = $socket;
    }

    /**
     * @return mixed
     */
    public function getKey()
    {
        return $this->key;
    }

    /**
     * @param mixed $key
     */
    public function setKey($key)
    {
        $this->key = $key;
    }

    /**
     * @return mixed
     */
    public function getOrigin()
    {
        return $this->request->getOrigin();
    }

    /**
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->connected;
    }

    /**
     * @param bool $connected
     */
    public function setConnected(bool $connected)
    {
        $this->connected = $connected;
    }

    /**
     * @return string
     */
    public function getUrl(): string
    {
        return $this->url;
    }

    /**
     * @return Request
     */
    public function getRequest(): Request
    {
        return $this->request;
    }

    /**
     * @return Response
     */
    public function getResponse(): Response
    {
        return $this->response;
    }
}