<?php

namespace rpc_thrift;

// 默认Thrift之类的路径已经设置好
use Thrift\Exception\TApplicationException;
use Thrift\Protocol\TBinaryProtocol;
use Thrift\Transport\TFramedTransport;
use Thrift\Transport\TMemoryBuffer;
use Thrift\Transport\TSocket;
use Thrift\Type\TMessageType;

// 只负责RPC的基础逻辑, 不负责和Yii框架等集成
class SMThriftWorker {

  const MESSAGE_TYPE_HEART_BEAT = 20;
  const MESSAGE_TYPE_STOP = 21;
  const MESSAGE_TYPE_STOP_CONFIRM = 22;

  protected $processor;
  /**
   * @var string
   */
  protected $host;
  protected $port;

  protected $service;

  protected $socket;

  protected $reconnect_interval = 1;
  protected $alive;

  protected $last_hb_time = 0;

  /**
   * ThriftWorker constructor.
   * @param $processor
   * @param string $address
   * @param int $pool_size
   * @param string $service
   */
  public function __construct($processor, $host, $port, $service = null) {
    $this->processor = $processor;
    $this->host = $host;
    $this->port = $port;
    $this->service = $service;
    $this->alive = true;
  }


  public function run() {
    // 建立连接
    while ($this->alive) {
      $this->connectToLb();
    }

  }

  protected function connectToLb() {
    $socket = new TSocket($this->host, $this->port);
    $socket->setRecvTimeout(5000); // 5s没有消息就timeout

    try {
      $socket->open();
      // $socket->setRecvTimeout()
    } catch (\Exception $ex) {
      // 打开失败, 暂停
      sleep($this->reconnect_interval);
      if ($this->reconnect_interval <= 4) {
        $this->reconnect_interval *= 2;
      }
      return;
    }

    // 连接成功, 则正常工作
    $this->reconnect_interval = 1;
    $this->last_hb_time = time();

    $transport = new TFramedTransport($socket, true, true);
    $protocol = new TBinaryProtocol($transport);


    while (true) {
      // 太长时间没有收到消息, 则关闭
      if (time() - $this->last_hb_time > 10) {
        $socket->close();
        break;
      }

      try {
        // 总会及时收到消息?
        $name = "";
        $type = 0;
        $seqid = 0;

        // 开始新的Frame
        $transport->readFrame();

        // echo "Before Receive Message: {$name}, {$type}, {$seqid}\n";
        $protocol->skipReadMessage = false;
        $protocol->readMessageBegin($name, $type, $seqid);
        // echo "Receive Message: {$name}, {$type}, {$seqid}\n";

        // 暂时屏蔽ReadMessage操作
        $protocol->skipReadMessage = true;

        if ($type == self::MESSAGE_TYPE_HEART_BEAT) {
          // 如果是心跳, 则立马返回
          $protocol->readMessageEnd();

          $protocol->writeMessageBegin($name, $type, $seqid);
          $protocol->writeMessageEnd();
          $transport->flush();
          $this->last_hb_time = time();
          // echo "Received Hb Signal from LB\n";

        } else if ($type == self::MESSAGE_TYPE_STOP_CONFIRM) {
          $this->alive = false;
          echo "Received Stop Confirm Signal from LB\n";
          // 准备关闭
          break;
        } else {
          $start = microtime(true);
          // 临时的Buffer有助于处理数据序列化的异常, 保证异常发生时 $transport 中的数据是干净的
          $outputBuffer = new TMemoryBuffer();
          try {
            // 处理其他请求
            // $fname, $mtype, $rseqid
            $this->processor->process($protocol, new TBinaryProtocol($outputBuffer));
            // echo "Process complete....\n";
          } catch (\Exception $ex) {
            // 序列化异常, 代码本身没有问题
            echo "Exception: " . $ex->getTraceAsString() . "\n";
            $this->writeExceptionBack($ex, $name, $seqid, $protocol, $transport);
            continue;
          }

          // echo "Normal response: " . $outputBuffer->getBuffer() . "\n";
          // 正常请求的返回
          $transport->flush($outputBuffer->getBuffer());

          $start = microtime(true) - $start;
          echo "${name} elapsed " . sprintf("%.3fms\n", $start * 1000);
        }
      } catch (\Exception $ex) {
        echo "Exception and Reconnect: " . $ex->getTraceAsString() . "\n";
        // 这里出现异常, 就必须断开重连了
        $socket->close();
        sleep($this->reconnect_interval);
        if ($this->reconnect_interval <= 4) {
          $this->reconnect_interval *= 2;
        }
        break;
      } finally {
        // echo "reset skipReadMessage\n";
        $protocol->skipReadMessage = false;
      }
    }
  }

  /**
   * @param TBinaryProtocol $protocol
   * @param TFramedTransport $transport
   */
  protected function writeStopBack($protocol, $transport) {
    $protocol->writeMessageBegin("stop", self::MESSAGE_TYPE_STOP, 0);
    $protocol->writeMessageEnd();
    $transport->flush();
  }


  /**
   * @param \Exception $ex
   * @param string $name
   * @param $seqid
   * @param TBinaryProtocol $protocol
   * @param TFramedTransport $transport
   */
  protected function writeExceptionBack($ex, $name, $seqid, $protocol, $transport) {
    // TODO: 优化Trace
    $msg = $ex->getTraceAsString();

    $x = new TApplicationException(TApplicationException::INVALID_PROTOCOL, $msg);
    $protocol->writeMessageBegin($name, TMessageType::EXCEPTION, $seqid);
    $x->write($protocol);
    $protocol->writeMessageEnd();
    $transport->flush();
  }
}