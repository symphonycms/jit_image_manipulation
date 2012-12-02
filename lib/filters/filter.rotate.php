<?php

	require_once(realpath(dirname(__FILE__).'/../') . '/class.imagefilter.php');

	Class FilterRotate extends ImageFilter {

		public static function run($res, $angle, $bgd_color = -1) {

			return imagerotate($res, $angle, $bgd_color);
		}
	}