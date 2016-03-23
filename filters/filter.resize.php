<?php

class FilterResize extends JIT\ImageFilter
{

    public $mode = 1;

    public static function about()
    {
        return array(
            'name' => 'JIT Filter: Resize'
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

        $tmp = imagecreatetruecolor($dst_w, $dst_h);
        self::__fill($resource, $tmp, $settings['settings']['background']);

        imagecopyresampled($tmp, $resource, 0, 0, 0, 0, $dst_w, $dst_h, Image::width($resource), Image::height($resource));

        if (is_resource($resource)) {
            imagedestroy($resource);
        }

        $res->setResource($tmp);

        return $res;
    }
}
