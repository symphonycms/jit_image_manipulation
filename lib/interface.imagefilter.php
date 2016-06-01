<?php
    
namespace JIT;

interface ImageFilterInterface
{

    public static function about();

    public function parseParameters($parameter_string);

    public function run(\Image $resource, $settings);
}
