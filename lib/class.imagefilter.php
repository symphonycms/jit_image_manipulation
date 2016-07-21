<?php

namespace JIT;

abstract class ImageFilter implements ImageFilterInterface
{

    const CHANNEL_RED = 0;
    const CHANNEL_GREEN = 1;
    const CHANNEL_BLUE = 2;

    const TOP_LEFT = 1;
    const TOP_MIDDLE = 2;
    const TOP_RIGHT = 3;
    const MIDDLE_LEFT = 4;
    const CENTER = 5;
    const MIDDLE_RIGHT = 6;
    const BOTTOM_LEFT = 7;
    const BOTTOM_MIDDLE = 8;
    const BOTTOM_RIGHT = 9;

    abstract public static function about();

    abstract public function parseParameters($parameter_string);

    abstract public function run(\Image $resource, $settings);

    public static function colourChannelHex2Dec($colour, $channel)
    {
        return hexdec(substr($colour, ($channel * 2), 2));
    }

    public static function expandColourString($colour)
    {
        return (strlen($colour) == 3 ? $colour{0}.$colour{0}.$colour{1}.$colour{1}.$colour{2}.$colour{2} : $colour);
    }

    public static function findAspectRatioValues($width, $height, $src_w, $src_h)
    {
        if (empty($height)) {
            $ratio = $src_h / $src_w;
            return array($width, round($width * $ratio));
        } else if (empty($width)) {
            $ratio = $src_w / $src_h;
            return array(round($height * $ratio), $height);
        }
        return array($width, $height);
    }

    public static function fill(&$res, &$dst, $colour = null)
    {
        if (!$colour || strlen(trim($colour)) === 0) {
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

    public static function calculateDestSrcXY($width, $height, $src_w, $src_h, $dst_w, $dst_h, $position = self::TOP_LEFT)
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

            case self::TOP_LEFT:
                break;

            case self::TOP_MIDDLE:
                $src_x = 1;
                break;

            case self::TOP_RIGHT:
                $src_x = 2;
                break;

            case self::MIDDLE_LEFT:
                $src_y = 1;
                break;

            case self::CENTER:
                $src_x = 1;
                $src_y = 1;
                break;

            case self::MIDDLE_RIGHT:
                $src_x = 2;
                $src_y = 1;
                break;

            case self::BOTTOM_LEFT:
                $src_y = 2;
                break;

            case self::BOTTOM_MIDDLE:
                $src_x = 1;
                $src_y = 2;
                break;

            case self::BOTTOM_RIGHT:
                $src_x = 2;
                $src_y = 2;
                break;

        }

        $a = (int)($width >= $dst_w ? $mx[$src_x] : 0);
        $b = (int)($height >= $dst_h ? $my[$src_y] : 0);
        $c = (int)($width < $dst_w ? $mx[$src_x] : 0);
        $d = (int)($height < $dst_h ? $my[$src_y] : 0);

        return array($a, $b, $c, $d);
    }
}
