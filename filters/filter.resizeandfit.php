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
        $resource = $res->Resource();
        $src_w = Image::width($resource);
        $src_h = Image::height($resource);

        $width = $settings['meta']['width'];
        $height = $settings['meta']['height'];

        // We must always preserve aspect ratio.
        // Resize accordingly
        if ($width == 0 || $height == 0) {
            list($dst_w, $dst_h) = static::findAspectRatioValues($width, $height, $src_w, $src_h);
        } else {
            $src_r = ($src_w / $src_h);
            $dst_r = ($width / $height);

            if ($src_r > $dst_r) {
                list($dst_w, $dst_h) = static::findAspectRatioValues($width, null, $src_w, $src_h);
            } else {
                list($dst_w, $dst_h) = static::findAspectRatioValues(null, $height, $src_w, $src_h);
            }
        }

        $settings['meta']['width'] = $dst_w;
        $settings['meta']['height'] = $dst_h;

        return parent::run($res, $settings);
    }
}
