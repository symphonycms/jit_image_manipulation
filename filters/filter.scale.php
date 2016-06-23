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
    
    public function parseParameters($parameter_string)
    {
        $param = array();

        if (preg_match_all('/^(' . $this->mode .')\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $parameter_string, $matches, PREG_SET_ORDER)) {
            $param['mode'] = (int)$matches[0][1];
            $param['settings']['percentage'] = (int)$matches[0][2];
            $param['settings']['external'] = (bool)$matches[0][3];
            $param['image_path'] = $matches[0][4];

            if ($param['settings']['percentage'] === 0) {
                return false;
            }
        }

        return !empty($param) ? $param : false;
    }

    public function run(\Image $res, $settings)
    {
        $resource = $res->Resource();

        $percentage = floatval($settings['settings']['percentage']) * 0.01;

        if ($settings['meta']['disable_upscaling']) {
            $percentage = floatval(min(1.0, $percentage));
        }

        $settings['meta']['height'] = round(Image::height($resource) * $percentage);
        $settings['meta']['width'] = round(Image::width($resource) * $percentage);
        $settings['settings']['height'] = $settings['meta']['height'];
        $settings['settings']['width'] = $settings['meta']['width'];

        return parent::run($res, $settings);
    }
}
