<?php
	
	function processParams($string){
		
		$param = (object)array(
			'mode' => 0,
			'width' => 0,
			'height' => 0,
			'position' => 0,
			'background' => 0,	
			'file' => 0
		);

		## Mode 3: Resize Canvas
		if(preg_match_all('/^3\/([0-9]+)\/([0-9]+)\/([1-9])\/([a-fA-f0-9]{3,6})\/(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = 3;
			$param->width = $matches[0][1];
			$param->height = $matches[0][2];			
			$param->position = $matches[0][3];		
			$param->background = $matches[0][4];
			$param->file = $matches[0][5];
		}
		
		## Mode 2: Crop to fill
		elseif(preg_match_all('/^2\/([0-9]+)\/([0-9]+)\/([1-9])(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = 2;
			$param->width = $matches[0][1];
			$param->height = $matches[0][2];			
			$param->position = $matches[0][3];			
			$param->file = $matches[0][4];
		}
		
		## Mode 1: Image resize
		elseif(preg_match_all('/^1\/([0-9]+)\/([0-9]+)(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = 1;
			$param->width = $matches[0][1];
			$param->height = $matches[0][2];			
			$param->file = $matches[0][3];
		}
		
		## Mode 0: Direct displaying of image
		elseif(preg_match_all('/^(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->file = $matches[0][1];
		}
		
		return $param;
	}
	
	$param = processParams($_GET['param']);
	
	##Include some parts of the engine
	require_once(realpath(dirname(__FILE__) . '/../../../manifest/config.php'));
	require_once(TOOLKIT . '/class.lang.php');

	Lang::init(LANG . '/lang.%s.php', ($settings['symphony']['lang'] ? $settings['symphony']['lang'] : 'en'));

	include('class.image.php');
	
	define_safe('MODE_NONE', 0);
	define_safe('MODE_RESIZE', 1);
	define_safe('MODE_RESIZE_CROP', 2);
	define_safe('MODE_CROP', 3);
	
	define_safe('CACHING', ($settings['image']['cache'] == 1 ? true : false));
	
	$meta = $cache_file = NULL;

	$image_path = WORKSPACE . "/{$param->file}";
	
	## Do cache checking stuff here
	if(CACHING === true){
		
	    $cache_file = sprintf('%s/%s_$s', CACHE, md5($_REQUEST['param'] . $quality), basename($image_path));	

		if(@is_file($cache_file) && (@filemtime($cache_file) < @filemtime($image_path))){ 
			unlink($cache_file);
		}		
		
		elseif(is_file($cache_file)){
			$image_path = $cache_file;
			@touch($cache_file);
			$param->mode = MODE_NONE;
		}
	}
	
	####
	
	if($param->mode == MODE_NONE){
		
		if(!$contents = @file_get_contents($image_path)){
			header('HTTP/1.0 404 Not Found');
			trigger_error(__('Image <code>%s</code> could not be found.', array($image_path)), E_USER_ERROR);
		}
		
		$meta = Image::meta($image_path);
		Image::renderOutputHeaders($meta['type']);		
		print $contents;
		@imagedestroy($image);
		exit();
	} 

	try{
		$image = Image::load($image_path, &$meta);
	}
	catch(Exception $e){
		header('HTTP/1.0 404 Not Found');
		trigger_error($e->getMessage(), E_USER_ERROR);
	}
	

	switch($param->mode){
		
		case MODE_RESIZE:
			
			include_once('filters/filter.resize.php');
			
			if($param->width == 0){
				$r = (float)($param->height / $meta['height']);
				$param->width = $meta['width'] * $r;
			}
			
			elseif($param->height == 0){
				$r = (float)($param->width / $meta['width']);
				$param->height = $meta['height'] * $r;				
			}
		
			//$result = $img->resize($param->width, $param->height, 'fill');
			$image = FilterResize::run($image, $param->width, $param->height);
			break;
			
		case MODE_RESIZE_CROP:

			include_once('filters/filter.resize.php');
		
			$src_w = Image::width($image);
			$src_h = Image::height($image);
			
			$dst_w = $param->width;
			$dst_h = $param->height;
			
			$src_r = ($src_w / $src_h);
			$dst_r = ($dst_w / $dst_h);

			if($src_r < $dst_r) $image = FilterResize::run($image, $dst_w, NULL);
			else $image = FilterResize::run($image, NULL, $dst_h);			
			
			/*
				if($src_h < $param->height || $src_h > $param->height) ImageFilters::resize($image, NULL, $param->height);
				if($src_w < $param->width) ImageFilters::resize($image, $param->width, NULL);			
					
			*/

		case MODE_CROP:
			include_once('filters/filter.crop.php');
			$image = FilterCrop::run($image, $param->width, $param->height, $param->position, $param->background);
			break;
	}

	if(!Image::display($image, intval($settings['image']['quality']), true, $meta['type'])) trigger_error(__('Error generating image'), E_USER_ERROR);
	
	if(CACHING && !is_file($cache_file)){ 
		Image::save($image, $cache_file, intval($settings['image']['quality']), true, $meta['type']);
	}
	
	@imagedestroy($image);
	exit();
