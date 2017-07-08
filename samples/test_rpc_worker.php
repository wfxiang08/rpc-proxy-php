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

use rpc_thrift\SMThriftWorker;
use Services\HelloWorld\HelloWorldHandler;
use Services\HelloWorld\HelloWorldProcessor;

class TestCode {
  function testWorker() {
    // echo "dir: " . __DIR__ . "\n";
    foreach (glob(__DIR__ . '/Services/HelloWorld/*.php') as $start_file) {
      // echo $start_file;
      require_once $start_file;
    }


    // 创建Handler
    $handler = new HelloWorldHandler();

    // 创建Processor
    $processor = new HelloWorldProcessor($handler);

    // echo "Name: " . $handler->sayHello("wangfei") . "\n";
    // $client = new SMThriftWorker($processor, 'tcp://localhost', 5556);
    $client = new SMThriftWorker($processor, '/usr/local/proxy/hello_backend.sock', 0);

    $client->run();
  }


}

$test_code = new TestCode();
$test_code->testWorker();
