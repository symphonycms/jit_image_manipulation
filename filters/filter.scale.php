<?php

class FilterScale extends FilterResize
{

    public $mode = 5;

    public static function about()
    {
        return array(
            'name' => 'JIT Filter: Scale'
        );
    }
    
    public static function parseParameters($parameter_string)
    {
        $param = array();

        if (preg_match_all('/^(5)\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $parameter_string, $matches, PREG_SET_ORDER)) {
            $param['mode'] = (int)$matches[0][1];
            $param['settings']['percentage'] = (int)$matches[0][2];
            $param['settings']['external'] = (bool)$matches[0][3];
            $param['image_path'] = $matches[0][4];
        }

        return !empty($param) ? $param : false;
    }

    public static function run(\Image $res, $settings)
    {
        $resource = $res->Resource();

        $percentage = floatval(max(1.0, floatval($settings['settings']['percentage'])) * 0.01);

        $settings['settings']['width'] = round(Image::height($resource) * $percentage);

        return parent::run($res, $settings);
    }
}
