<?php

Class FilterScale extends JIT\ImageFilter {

    public $mode = 4;

    public static function about() {
        return array(
            'name' => 'JIT Filter: Scale'
        );
    }

    public static function run(\Image $resource, $settings) {
        $percentage = floatval(max(1.0, floatval($percentage)) * 0.01);

        $width = round(Image::height($res) * $percentage);

        //return self::resize($res, $width, NULL);
    }

}