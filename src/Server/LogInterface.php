<?php
/**
 * Created by PhpStorm.
 * User: inhere
 * Date: 2017/5/13
 * Time: 上午10:55
 */

namespace Inhere\WebSocket\Server;

/**
 * Interface LogInterface
 * @package Inhere\WebSocket\Server
 */
interface LogInterface
{
    /**
     * Log levels can be enabled from the command line with -v, -vv, -vvv
     */
    const LOG_EMERG = -8;
    const LOG_ERROR = -6;
    const LOG_WARN = -4;
    const LOG_NOTICE = -2;
    const LOG_INFO = 0;
    const LOG_PROC_INFO = 2;
    const LOG_WORKER_INFO = 4;
    const LOG_DEBUG = 6;
    const LOG_CRAZY = 8;

    /**
     * Log file save type.
     */
    const LOG_SPLIT_NO = '';
    const LOG_SPLIT_DAY = 'day';
    const LOG_SPLIT_HOUR = 'hour';

    const LOG_CHECK_INTERVAL = 300;
}
