<?php

class FilterResizeAndFit extends FilterResize
{

    public $mode = 4;

    public static function about()
    {
        return array(
            'name' => 'JIT Filter: Resize and Fit'
        );
    }

    public static function parseParameters($parameter_string)
    {
        $param = array();

        if (preg_match_all('/^(1|4)\/([0-9]+)\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $parameter_string, $matches, PREG_SET_ORDER)) {
            $param['mode'] = (int)$matches[0][1];
            $param['settings']['width'] = (int)$matches[0][2];
            $param['settings']['height'] = (int)$matches[0][3];
            $param['settings']['external'] = (bool)$matches[0][4];
            $param['image_path'] = $matches[0][5];
        }

        return !empty($param) ? $param : false;
    }

    public static function run(\Image $res, $settings)
    {
        $src_w = $res->Meta()->width;
        $src_h = $res->Meta()->height;

        if ($settings['settings']['height'] == 0) {
            $ratio = ($src_h / $src_w);
            $dst_h = round($settings['meta']['width'] * $ratio);
        } elseif ($settings['settings']['width'] == 0) {
            $ratio = ($src_w / $src_h);
            $dst_w = round($settings['meta']['height'] * $ratio);
        }

        $src_r = ($src_w / $src_h);
        $dst_r = ($settings['meta']['width'] / $settings['meta']['height']);

        if ($src_h <= $dst_h && $src_w <= $dst_w) {
            $settings['settings']['width'] = $src_w;
            $settings['settings']['height'] = $src_h;

            return parent::run($res, $settings);
        }

        if ($src_h >= $dst_h && $src_r <= $dst_r) {
            $settings['settings']['height'] = $dst_h;
            $res = parent::run($res, $settings);
        }

        if ($src_w >= $dst_w && $src_r >= $dst_r) {
            $settings['settings']['width'] = $dst_w;
            $res = parent::run($res, $settings);
        }

        return $res;
    }
}
