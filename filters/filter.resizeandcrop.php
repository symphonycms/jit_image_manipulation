<?php

class FilterResizeAndCrop extends JIT\ImageFilter
{

    public $mode = 2;

    public static function about()
    {
        return array(
            'name' => 'JIT Filter: Resize and Crop'
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

        // We must always preserve aspect ratio.
        // Resize accordingly
        if ($width == 0 || $height == 0) {
            list($dst_w, $dst_h) = static::findAspectRatioValues($width, $height, $src_w, $src_h);
            // Overrides parameters, since one is null
            // no cropping will occur
            $width = $dst_w;
            $height = $dst_h;
        } else {
            $src_r = ($src_w / $src_h);
            $dst_r = ($width / $height);

            if ($src_r < $dst_r) {
                list($dst_w, $dst_h) = static::findAspectRatioValues($width, null, $src_w, $src_h);
            } else {
                list($dst_w, $dst_h) = static::findAspectRatioValues(null, $height, $src_w, $src_h);
            }
        }

        $tmp = imagecreatetruecolor($width, $height);
        static::fill($resource, $tmp, $settings['settings']['background']);

        // Find crop according to resize (dst) size
        list($src_x, $src_y, $dst_x, $dst_y) = static::calculateDestSrcXY($width, $height, $dst_w, $dst_h, $dst_w, $dst_h, $settings['settings']['position']);

        // Project dst values onto src
        $dst_x = (int)round($src_w / $dst_w * $dst_x);
        $dst_y = (int)round($src_h / $dst_h * $dst_y);

        // Copy image
        imagecopyresampled($tmp, $resource, $src_x, $src_y, $dst_x, $dst_y, $dst_w, $dst_h, $src_w, $src_h);

        if (is_resource($resource)) {
            imagedestroy($resource);
        }

        $res->setResource($tmp);

        return $res;
    }

}
