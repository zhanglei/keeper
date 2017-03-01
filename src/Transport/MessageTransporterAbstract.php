<?php
/**
 * MessageTransporterAbstract.php
 *
 * Creator:    chongyi
 * Created at: 2016/12/23 11:47
 */

namespace Keeper\Transport;

use Closure;

/**
 * Class MessageTransporter
 *
 * 消息传递器
 *
 * @package Keeper\Transport
 */
interface MessageTransporterAbstract
{
    /**
     * 发送消息
     *
     * @param Message $message
     *
     * @return mixed
     */
    public function send(Message $message);
}