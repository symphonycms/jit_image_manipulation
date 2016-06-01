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

    public function run(\Image $res, $settings)
    {
        $src_w = $res->Meta()->width;
        $src_h = $res->Meta()->height;

        if ($settings['settings']['height'] == 0) {
            $ratio = ($src_h / $src_w);
            $dst_h = round($settings['meta']['width'] * $ratio);
            $dst_w = $settings['meta']['width'];
        } elseif ($settings['settings']['width'] == 0) {
            $ratio = ($src_w / $src_h);
            $dst_w = round($settings['meta']['height'] * $ratio);
            $dst_h = $settings['meta']['height'];
        } else {
            $dst_h = $settings['meta']['height'];
            $dst_w = $settings['meta']['width'];
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
