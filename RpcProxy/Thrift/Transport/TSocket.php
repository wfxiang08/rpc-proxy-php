<?php
/*
 * Licensed to the Apache Software Foundation (ASF) under one
 * or more contributor license agreements. See the NOTICE file
 * distributed with this work for additional information
 * regarding copyright ownership. The ASF licenses this file
 * to you under the Apache License, Version 2.0 (the
 * "License"); you may not use this file except in compliance
 * with the License. You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing,
 * software distributed under the License is distributed on an
 * "AS IS" BASIS, WITHOUT WARRANTIES OR CONDITIONS OF ANY
 * KIND, either express or implied. See the License for the
 * specific language governing permissions and limitations
 * under the License.
 *
 * @package thrift.transport
 */

namespace Thrift\Transport;

use Thrift\Exception\TException;
use Thrift\Exception\TTransportException;
use Thrift\Factory\TStringFuncFactory;

// 参考: https://raw.githubusercontent.com/hoan/phpcassa/master/thrift/transport/TSocket.php
//      https://issues.apache.org/jira/browse/THRIFT-347
/**
 * Sockets implementation of the TTransport interface.
 *
 * @package thrift.transport
 */
class TSocket extends TTransport {
  /**
   * Handle to PHP socket
   *
   * @var resource
   */
  protected $handle_ = null;

  /**
   * Remote hostname
   *
   * @var string
   */
  protected $host_ = 'localhost';

  // 端口
  protected $port_ = '9090';

  /**
   * Send timeout in milliseconds
   *
   * @var int
   */
  private $sendTimeout_ = 100;

  /**
   * Recv timeout in milliseconds
   *
   * @var int
   */
  private $recvTimeout_ = 750;

  /**
   * Is send timeout set?
   *
   * @var bool
   */
  private $sendTimeoutSet_ = FALSE;

  /**
   * Persistent socket or plain?
   *
   * @var bool
   */
  private $persist_ = false;

  /**
   * Debugging on?
   *
   * @var bool
   */
  protected $debug_ = false;

  /**
   * Debug handler
   *
   * @var mixed
   */
  protected $debugHandler_ = null;

  /**
   * Socket constructor
   *
   * @param string $host Remote hostname
   * @param int $port Remote port
   * @param bool $persist Whether to use a persistent socket
   * @param string $debugHandler Function to call for error logging
   */
  public function __construct($host = 'localhost',
                              $port = 9090,
                              $persist = false,
                              $debugHandler = null) {
    $this->host_ = $host;
    $this->port_ = $port;
    $this->persist_ = $persist;
    $this->debugHandler_ = $debugHandler ? $debugHandler : 'error_log';
  }

  /**
   * Sets the send timeout.
   *
   * @param int $timeout Timeout in milliseconds.
   */
  public function setSendTimeout($timeout) {
    $this->sendTimeout_ = $timeout;
  }

  /**
   * Sets the receive timeout.
   *
   * @param int $timeout Timeout in milliseconds.
   */
  public function setRecvTimeout($timeout) {
    $this->recvTimeout_ = $timeout;
  }

  /**
   * Sets debugging output on or off
   *
   * @param bool $debug
   */
  public function setDebug($debug) {
    $this->debug_ = $debug;
  }

  /**
   * Get the host that this socket is connected to
   *
   * @return string host
   */
  public function getHost() {
    return $this->host_;
  }

  /**
   * Get the remote port that this socket is connected to
   *
   * @return int port
   */
  public function getPort() {
    return $this->port_;
  }

  /**
   * Tests whether this is open
   *
   * @return bool true if the socket is open
   */
  public function isOpen() {
    return is_resource($this->handle_);
  }

  /**
   * Connects the socket.
   */
  public function open() {

    if ($this->isOpen()) {
      throw new TTransportException('Socket already connected', TTransportException::ALREADY_OPEN);
    }

    if (empty($this->host_)) {
      throw new TTransportException('Cannot open null host', TTransportException::NOT_OPEN);
    }

    $host = $this->host_;


    if (strpos($this->host_, ":") === false) {
      $host = "unix://".$this->host_;
      // echo "Host: ${host}\n";
      // Unix Domain Socket直接忽略 port, 强制设置为null
      $this->port_ = null;
      // 如果使用rpc_proxy可以直接忽略 persist_
      $this->persist_ = false;
    } else {
      $this->port_ = (int)$this->port_;
      if ($this->port_ <= 0) {
        throw new TTransportException('Cannot open without port', TTransportException::NOT_OPEN);
      }
    }

    if ($this->persist_) {
      $this->handle_ = @pfsockopen($host,
        $this->port_,
        $errno,
        $errstr,
        $this->sendTimeout_ / 1000.0);
    } else {
      $this->handle_ = @fsockopen($host,
        $this->port_,
        $errno,
        $errstr,
        $this->sendTimeout_ / 1000.0);
    }

    // Connect failed?
    // null或者false 都应该算是失败
    if ($this->handle_ == false) {
      $error = 'TSocket: Could not connect to '.$host.':'.$this->port_.' ('.$errstr.' ['.$errno.'])';
      if ($this->debug_) {
        call_user_func($this->debugHandler_, $error);
      }
      throw new TException($error);
    }

    stream_set_timeout($this->handle_, 0, $this->sendTimeout_ * 1000);
    $this->sendTimeoutSet_ = true;

    if (function_exists('socket_import_stream')) {
      // socket_import_stream 为什么不存在呢?
      $socket = @socket_import_stream($this->handle_);
      if ($this->port_ !== null) {
        socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
      }
    }
  }

  /**
   * Closes the socket.
   */
  public function close() {
    @fclose($this->handle_);
    $this->handle_ = null;
  }


  public function readAll($len) {
    return $this->read($len);
  }

  /**
   * Uses stream get contents to do the reading
   *
   * @param int $len How many bytes
   * @return string Binary data
   */
  public function read($len) {
    if ($this->sendTimeoutSet_) {
      stream_set_timeout($this->handle_, 0, $this->recvTimeout_ * 1000);
      $this->sendTimeoutSet_ = false;
    }
    // This call does not obey stream_set_timeout values!
    // $buf = @stream_get_contents($this->handle_, $len);

    $pre = null;
    while (true) {
      $buf = @fread($this->handle_, $len);
      if ($buf === false) {
        $md = stream_get_meta_data($this->handle_);
        // 根据网络状态返回数据
        // timed_out 或者 就是其他原因失败
        if ($md['timed_out']) {
          throw new TException('TSocket: timed out reading '.$len.' bytes from '.
            $this->host_.':'.$this->port_);
        } else {
          throw new TException('TSocket: Could not read '.$len.' bytes from '.
            $this->host_.':'.$this->port_);
        }
      } else if (($sz = strlen($buf)) < $len) {
        // 如果没有读完
        $md = stream_get_meta_data($this->handle_);
        // 如果是timeout, 则放弃
        if (true === $md['timed_out'] && false === $md['blocked']) {
          throw new TException('TSocket: timed out reading '.$len.' bytes from '.
            $this->host_.':'.$this->port_);
        } else {
          // 否则继续等待
          $pre .= $buf;
          $len -= $sz;
        }
      } else {
        return $pre.$buf;
      }
    }
  }


  /**
   * Write to the socket.
   *
   * @param string $buf The data to write
   */
  public function write($buf) {
    if (!$this->sendTimeoutSet_) {
      stream_set_timeout($this->handle_, 0, $this->sendTimeout_ * 1000);
      $this->sendTimeoutSet_ = false;
    }
    while (strlen($buf) > 0) {
      $got = @fwrite($this->handle_, $buf);
      if ($got === 0 || $got === false) {
        $md = stream_get_meta_data($this->handle_);
        if ($md['timed_out']) {
          throw new TException('TSocket: timed out writing '.strlen($buf).' bytes from '.
            $this->host_.':'.$this->port_);
        } else {
          throw new TException('TSocket: Could not write '.strlen($buf).' bytes '.
            $this->host_.':'.$this->port_);
        }
      }
      // 写了一部分, 继续写数据
      $buf = substr($buf, $got);
    }
  }

  /**
   * Flush output to the socket.
   */
  public function flush() {
    $ret = fflush($this->handle_);
    if ($ret === false) {
      throw new TException('TSocket: Could not flush: '.
        $this->host_.':'.$this->port_);
    }
  }
}
