<?php
namespace Services\HelloWorld;

class HelloWorldHandler implements HelloWorldIf {
  // 简单实现
  public function sayHello($name) {
    return "Hello $name";
  }
}
