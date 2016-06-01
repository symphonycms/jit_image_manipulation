<?php

class FilterResizeAndCrop extends JIT\ImageFilter
{

    public $mode = 2;

    const TOP_LEFT = 1;
    const TOP_MIDDLE = 2;
    const TOP_RIGHT = 3;
    const MIDDLE_LEFT = 4;
    const CENTER = 5;
    const MIDDLE_RIGHT = 6;
    const BOTTOM_LEFT = 7;
    const BOTTOM_MIDDLE = 8;
    const BOTTOM_RIGHT = 9;

    public static function about()
    {
        return array(
            'name' => 'JIT Filter: Resize and Crop'
        );
    }

    public function parseParameters($parameter_string)
    {
        $param = array();

        if (preg_match_all('/^(2|3)\/([0-9]+)\/([0-9]+)\/([1-9])\/([a-fA-F0-9]{3,6}\/)?(?:(0|1)\/)?(.+)$/i', $parameter_string, $matches, PREG_SET_ORDER)) {
            $param['mode'] = (int)$matches[0][1];
            $param['settings']['width'] = (int)$matches[0][2];
            $param['settings']['height'] = (int)$matches[0][3];
            $param['settings']['position'] = (int)$matches[0][4];
            $param['settings']['background'] = trim($matches[0][5], '/');
            $param['settings']['external'] = (bool)$matches[0][6];
            $param['image_path'] = $matches[0][7];
        }

        return !empty($param) ? $param : false;
    }

    public function run(\Image $res, $settings)
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

        if ($src_r < $dst_r) {
            $width = $settings['meta']['width'];
            $height = null;
        } else {
            $width = null;
            $height = $settings['meta']['height'];
        }

        $resource = $res->Resource();

        $dst_w = Image::width($resource);
        $dst_h = Image::height($resource);

        if (!empty($width) && !empty($height)) {
            $dst_w = $width;
            $dst_h = $height;
        } elseif (empty($height)) {
            $ratio = ($dst_h / $dst_w);
            $dst_w = $width;
            $dst_h = round($dst_w * $ratio);
        } elseif (empty($width)) {
            $ratio = ($dst_w / $dst_h);
            $dst_h = $height;
            $dst_w = round($dst_h * $ratio);
        }

        $image_width = Image::width($resource);
        $image_height = Image::height($resource);

        $tmp = imagecreatetruecolor($dst_w, $dst_h);
        static::__fill($resource, $tmp, $settings['settings']['background']);

        list($src_x, $src_y, $dst_x, $dst_y) = static::__calculateDestSrcXY($dst_w, $dst_h, $src_w, $src_h, $width, $height, $settings['settings']['position']);

        imagecopyresampled($tmp, $resource, $src_x, $src_y, $dst_x, $dst_y, $image_width, $image_height, $image_width, $image_height);

        if (is_resource($resource)) {
            imagedestroy($resource);
        }

        $res->setResource($tmp);

        return $res;
    }

}
