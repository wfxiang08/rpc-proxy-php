<?php

// 引入Class Loader
define('THRIFT_ROOT', dirname(__DIR__));
require_once THRIFT_ROOT . '/RpcProxy/Thrift/ClassLoader/ThriftClassLoader.php';
use Thrift\ClassLoader\ThriftClassLoader;

$loader = new ThriftClassLoader();

// 设置搜索路径
$loader->registerNamespace('Thrift', THRIFT_ROOT . '/RpcProxy');
$loader->registerNamespace('rpc_thrift', THRIFT_ROOT . '/RpcProxy');
$loader->registerDefinition('Services', THRIFT_ROOT . '/samples/');

// 注册loader
$loader->register();

use Thrift\Exception\TException;
use Thrift\Protocol\TBinaryProtocol;
use Thrift\Protocol\TMultiplexedProtocol;
use Thrift\Transport\TSocket;
use Thrift\Transport\TFramedTransport;


class TestCode {
  function testDirectRPC() {
//    try {
//      $socket = new TSocket('localhost', 5563);
//      // $transport = new TBufferedTransport($socket, 1024, 1024);
//      $transport = new TFramedTransport($socket, true, true);
//      $protocol = new TBinaryProtocol($transport);
//      $client = new GeoIpServiceClient($protocol);
//
//      $transport->open();
//
//      $client->ping();
//      print "ping()\n";
//
//      $data = $client->IpToGeoData("120.52.139.7");
//      var_dump($data);
//
//      $transport->close();
//
//    } catch
//    (TException $tx) {
//      print 'TException: ' . $tx->getMessage() . "\n";
//    }
  }


  function testProxiedRPCHelloworld() {
    try {

      // 直接使用rpc proxy进行通信
      $socket = new TSocket('tcp://localhost', 5550);
      // $socket = new TSocket('/usr/local/rpc_proxy/proxy.sock');

      $transport = new TFramedTransport($socket, true, true);

      // 指定后端服务
      $service_name = "hello";
      $protocol = new TMultiplexedProtocol(new TBinaryProtocol($transport), $service_name);

      // 创建Client
      $client = new \Services\HelloWorld\HelloWorldClient($protocol);

      $transport->open();
      $data = $client->sayHello("wwww");
      var_dump($data);

      $transport->close();

    } catch (TException $tx) {
      print 'TException: ' . $tx->getMessage() . "\n";
    }
  }

  function testAsyncProxiedRPCHelloworld() {
    try {

      // 直接使用rpc proxy进行通信
      $socket = new TSocket('tcp://localhost', 5550);
      $socket->setRecvTimeout(15000);
      // $socket = new TSocket('/usr/local/rpc_proxy/proxy.sock');

      $transport = new TFramedTransport($socket, true, true);

      // 指定后端服务
      $service_name = "hello";
      $protocol = new TMultiplexedProtocol(new TBinaryProtocol($transport), $service_name);

      // 创建Client
      $client = new \Services\HelloWorld\HelloWorldClient($protocol);

      $transport->open();

      $start = microtime(true);
      $client->send_sayHello("R1");
      $client->send_sayHello("R2");
      $client->send_sayHello("R3");
      echo "Wait for some time\n";

      $result1 = $client->recv_sayHello();
      $result2 = $client->recv_sayHello();
      $result3 = $client->recv_sayHello();
      $elapse = microtime(true) - $start;
      echo "Elapsed: {$elapse}\n";
      var_dump($result1);
      var_dump($result2);
      var_dump($result3);

      $transport->close();

    } catch (TException $tx) {
      print 'TException: ' . $tx->getMessage() . "\n";
    }
  }
}

$test_code = new TestCode();
// $test_code->testProxiedRPCHelloworld();
$test_code->testAsyncProxiedRPCHelloworld();

