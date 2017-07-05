<?php
namespace Services\HelloWorld;

class HelloWorldHandler implements HelloWorldIf {
  // 简单实现
  public function sayHello($name) {
    echo "MyPid: " . getmypid() . "\n";

    sleep(5);
    return "Hello $name";
  }
}
