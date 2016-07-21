<?php

class FilterCrop extends JIT\ImageFilter
{

    public $mode = 3;

    public static function about()
    {
        return array(
            'name' => 'JIT Filter: Crop'
        );
    }

    public function parseParameters($parameter_string)
    {
        $param = array();

        if (preg_match_all('/^(' . $this->mode .')\/([0-9]+)\/([0-9]+)\/([1-9])\/([a-fA-F0-9]{3,6}\/)?(?:(0|1)\/)?(.+)$/i', $parameter_string, $matches, PREG_SET_ORDER)) {
            $param['mode'] = (int)$matches[0][1];
            $param['settings']['width'] = (int)$matches[0][2];
            $param['settings']['height'] = (int)$matches[0][3];
            $param['settings']['position'] = (int)$matches[0][4];
            $param['settings']['background'] = trim($matches[0][5], '/');
            $param['settings']['external'] = (bool)$matches[0][6];
            $param['image_path'] = $matches[0][7];

            if ($param['settings']['width'] === 0 && $param['settings']['height'] === 0) {
                return false;
            }
        }

        return !empty($param) ? $param : false;
    }

    public function run(\Image $res, $settings)
    {
        $resource = $res->Resource();
        $src_w = Image::width($resource);
        $src_h = Image::height($resource);

        $width = $settings['meta']['width'];
        $height = $settings['meta']['height'];

        list($dst_w, $dst_h) = static::findAspectRatioValues($width, $height, $src_w, $src_h);

        $tmp = imagecreatetruecolor($dst_w, $dst_h);
        static::fill($resource, $tmp, $settings['settings']['background']);

        list($src_x, $src_y, $dst_x, $dst_y) = static::calculateDestSrcXY($dst_w, $dst_h, $src_w, $src_h, $src_w, $src_h, $settings['settings']['position']);

        imagecopyresampled($tmp, $resource, $src_x, $src_y, $dst_x, $dst_y, $src_w, $src_h, $src_w, $src_h);

        if (is_resource($resource)) {
            imagedestroy($resource);
        }

        $res->setResource($tmp);

        return $res;
    }

}
