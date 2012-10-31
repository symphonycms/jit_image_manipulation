<?php

	require_once(realpath(dirname(__FILE__).'/../') . '/class.imagefilter.php');

	Class FilterFlip extends ImageFilter {

		public static function run($res) {

			$res_w = Image::width($res);
			$res_h = Image::height($res);

			$tmp = imagecreatetruecolor(1, $res_h);

			for ($i = (int)floor(($res_w - 1) / 2); $i >= 0; $i--) {

				// backup right stripe

				imagecopy($tmp, $res, 0, 0, $res_w - 1 - $i, 0, 1, $res_h);

				// copy left stripe to the right

				imagecopy($res, $res, $res_w - 1 - $i, 0, $i, 0, 1, $res_h);

				// copy backuped right stripe to the left

				imagecopy($res, $tmp, $i, 0, 0, 0, 1, $res_h);
			}

			if (is_resource($tmp)) {

				imagedestroy($tmp);
			}

			return $res;
		}
	}