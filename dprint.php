<?php

function dprint($name,$value) {

  //  debug_print_backtrace();
  error_log("DPRINT:". $name. ":". print_r($value,true) . "\n<",0);
  

}
