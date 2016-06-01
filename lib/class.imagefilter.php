<?php

namespace JIT;

abstract class ImageFilter implements ImageFilterInterface
{

    const CHANNEL_RED = 0;
    const CHANNEL_GREEN = 1;
    const CHANNEL_BLUE = 2;
    
    abstract public static function about();

    abstract public static function parseParameters($parameter_string);

    abstract public static function run(\Image $resource, $settings);

    public static function colourChannelHex2Dec($colour, $channel)
    {
        return hexdec(substr($colour, ($channel * 2), 2));
    }

    public static function expandColourString($colour)
    {
        return (strlen($colour) == 3 ? $colour{0}.$colour{0}.$colour{1}.$colour{1}.$colour{2}.$colour{2} : $colour);
    }

    protected static function __fill(&$res, &$dst, $colour = null)
    {

        if (!$colour || strlen(trim($colour)) == 0) {
            $tr_idx = imagecolortransparent($res);
            if ($tr_idx >= 0) {
                $tr_colour = imagecolorsforindex($res, $tr_idx);
                $tr_idx = imagecolorallocate($dst, $tr_colour['red'], $tr_colour['green'], $tr_colour['blue']);
                imagefill($dst, 0, 0, $tr_idx);
                imagecolortransparent($dst, $tr_idx);
            } else {
                imagealphablending($dst, false);
                $colour = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                imagefill($dst, 0, 0, $colour);
                imagesavealpha($dst, true);
            }

            if (function_exists('imageantialias')) {
                imageantialias($dst, true);
            }
        } else {
            $colour = self::expandColourString($colour);

            $col_a = array(
                'r' => self::colourChannelHex2Dec($colour, self::CHANNEL_RED),
                'g' => self::colourChannelHex2Dec($colour, self::CHANNEL_GREEN),
                'b' => self::colourChannelHex2Dec($colour, self::CHANNEL_BLUE),
            );

            imagefill($dst, 0, 0, imagecolorallocate($dst, $col_a['r'], $col_a['g'], $col_a['b']));
        }
    }

    protected static function __copy($src, &$dst, $resize = true)
    {
        $w = imagesx($src);
        $h = imagesy($src);

        if ($resize) {
            $dst = imagecreatetruecolor($w, $h);
        }
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

        return $dst;
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
