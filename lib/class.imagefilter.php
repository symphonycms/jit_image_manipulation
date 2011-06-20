<?php

	Abstract Class ImageFilter{

		const CHANNEL_RED = 0;
		const CHANNEL_GREEN = 1;
		const CHANNEL_BLUE = 2;

		public static function colourChannelHex2Dec($colour, $channel){
			return hexdec(substr($colour, ($channel * 2), 2));
		}

		public static function expandColourString($colour){
			return (strlen($colour) == 3 ? $colour{0}.$colour{0}.$colour{1}.$colour{1}.$colour{2}.$colour{2} : $colour);
		}

		protected static function __fill(&$res, &$dst, $colour){
			
			if(!$colour || strlen(trim($colour)) == 0){
				
				$tr_idx = imagecolortransparent($res);
				if($tr_idx >= 0){
					$tr_colour = imagecolorsforindex($res, $tr_idx);
					$tr_idx = imagecolorallocate($dst, $tr_colour['red'], $tr_colour['green'], $tr_colour['blue']);
					imagefill($dst, 0, 0, $tr_idx);
					imagecolortransparent($dst, $tr_idx);
					
				}
				else{
					//var_dump($tr_idx);
					imagealphablending($dst, false);
					$colour = imagecolorallocatealpha($dst, 0, 0, 0, 127);
					imagefill($dst, 0, 0, $colour);
					imagesavealpha($dst, true);
					//var_dump($tr_idx, $colour);die;
				}
				if(function_exists('imageAntiAlias')){
					imageAntiAlias($dst, true);
				}
			}
			else{
				$colour = self::expandColourString($colour);
	
				$col_a = array(
					'r' => self::colourChannelHex2Dec($colour, self::CHANNEL_RED),
					'g' => self::colourChannelHex2Dec($colour, self::CHANNEL_GREEN),
					'b' => self::colourChannelHex2Dec($colour, self::CHANNEL_BLUE),
				);

				imagefill($dst, 0, 0, imagecolorallocate($dst, $col_a['r'], $col_a['g'], $col_a['b']));
			}
		}

		protected static function __copy($src, &$dst, $resize=true){
			$w = imagesx($src);
			$h = imagesy($src);

			if($resize) $dst = imagecreatetruecolor($w, $h);
			imagecopy($dst, $src, 0, 0, 0, 0, $w, $h);

			return $dst;
		}

	}
