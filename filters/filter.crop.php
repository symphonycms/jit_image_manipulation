<?php

class FilterCrop extends JIT\ImageFilter
{

    public $mode = 3;

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
            'name' => 'JIT Filter: Crop'
        );
    }

    public static function parseParameters($parameter_string)
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

    public static function run(\Image $res, $settings)
    {
        $resource = $res->Resource();
        $dst_w = Image::width($resource);
        $dst_h = Image::height($resource);

        $width = $settings['settings']['width'];
        $height = $settings['settings']['height'];

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
        self::__fill($resource, $tmp, $settings['settings']['background']);

        list($src_x, $src_y, $dst_x, $dst_y) = self::__calculateDestSrcXY($dst_w, $dst_h, $image_width, $image_height, $image_width, $image_height, $anchor);

        imagecopyresampled($tmp, $resource, $src_x, $src_y, $dst_x, $dst_y, $image_width, $image_height, $image_width, $image_height);

        if (is_resource($resource)) {
            imagedestroy($resource);
        }

        $res->setResource($tmp);

        return $res;
    }

    protected static function __calculateDestSrcXY($width, $height, $src_w, $src_h, $dst_w, $dst_h, $position = self::TOP_LEFT)
    {

        $dst_x = $dst_y = 0;
        $src_x = $src_y = 0;

        if ($width < $src_w) {
            $mx = array(
                0,
                ceil(($src_w * 0.5) - ($width * 0.5)),
                $src_x = $src_w - $width
            );
        } else {
            $mx = array(
                0,
                ceil(($width * 0.5) - ($src_w * 0.5)),
                $src_x = $width - $src_w
            );
        }

        if ($height < $src_h) {
            $my = array(
                0,
                ceil(($src_h * 0.5) - ($height * 0.5)),
                $src_y = $src_h - $height
            );
        } else {

            $my = array(
                0,
                ceil(($height * 0.5) - ($src_h * 0.5)),
                $src_y = $height - $src_h
            );
        }

        switch ($position) {

            case 1:
                break;

            case 2:
                $src_x = 1;
                break;

            case 3:
                $src_x = 2;
                break;

            case 4:
                $src_y = 1;
                break;

            case 5:
                $src_x = 1;
                $src_y = 1;
                break;

            case 6:
                $src_x = 2;
                $src_y = 1;
                break;

            case 7:
                $src_y = 2;
                break;

            case 8:
                $src_x = 1;
                $src_y = 2;
                break;

            case 9:
                $src_x = 2;
                $src_y = 2;
                break;

        }

        $a = ($width >= $dst_w ? $mx[$src_x] : 0);
        $b = ($height >= $dst_h ? $my[$src_y] : 0);
        $c = ($width < $dst_w ? $mx[$src_x] : 0);
        $d = ($height < $dst_h ? $my[$src_y] : 0);

        return array($a, $b, $c, $d);
    }
}
