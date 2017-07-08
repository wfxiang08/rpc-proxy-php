<?php

// 先申明, 并不需要马上就能搜索到
use Thrift\ClassLoader\ThriftClassLoader;

/** @var ThriftClassLoader $thrift_loader */
$thrift_loader = null;

// 只创建一个ThriftClassLoader
if (!isset($GLOBALS["thrift_loader"])) {
  $thrift_loader = new ThriftClassLoader();
  $thrift_loader->register();
  $GLOBALS["thrift_loader"] = $thrift_loader;
} else {
  $thrift_loader = $GLOBALS["thrift_loader"];
}

$thrift_loader->registerDefinition('Services', [__DIR__]);