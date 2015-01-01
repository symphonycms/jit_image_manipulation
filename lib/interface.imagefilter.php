<?php
    
namespace JIT;

Interface ImageFilterInterface {

    public static function about();

    public static function parseParameters($parameter_string);

    public static function run(\Image $resource, $settings);

}