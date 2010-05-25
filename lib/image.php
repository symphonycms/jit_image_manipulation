<?php

	require_once(LIB . '/class.symphony.php');
	require_once(LIB . '/class.lang.php');
	require_once('class.image.php');
	
	Class JITImageNotFoundException extends SymphonyErrorPage{
		public function __construct(View $page=NULL){
			
			if(is_null($page)){
				$views = View::findFromType('404');
				$page = array_shift($views);
			}
			
			parent::__construct(
				__('The image you requested does not exist.'),
				__('Image Not Found'),
				$page,
				array('header' => 'HTTP/1.0 404 Not Found')
			);
		}
	}

	Class JITImageNotFoundExceptionHandler extends SymphonyErrorPageHandler{
		/*
		
		TODO: Implement the logging of errors
		
		global $param;
		
		if(error_reporting() != 0 && in_array($errno, array(E_WARNING, E_USER_WARNING, E_ERROR, E_USER_ERROR))){
			$Log = new Log(ACTIVITY_LOG);
			
			$Log->pushToLog("{$errno} - ".strip_tags((is_object($errstr) ? $errstr->generate() : $errstr)).($errfile ? " in file {$errfile}" : '') . ($errline ? " on line {$errline}" : ''), ($errno == E_WARNING || $errno == E_USER_WARNING ? Log::WARNING : Log::ERROR), true);

			$Log->pushToLog(
				sprintf(
					'Image class param dump - mode: %d, width: %d, height: %d, position: %d, background: %d, file: %s, external: %d, raw input: %s', 
					$parameters->mode,
					$parameters->width,
					$parameters->height,
					$parameters->position,
					$parameters->background,
					$parameters->file,
					(bool)$parameters->external,
					$_GET['param']
				), Log::NOTICE, true
			);
		}
		*/
	}
	
	Class JITException extends Exception{
	}

	Class rendererJITImageManipulation extends Symphony {
		
		const MODE_NONE = 0;
		const MODE_RESIZE = 1;
		const MODE_RESIZE_CROP = 2;
		const MODE_CROP = 3;
		
		protected static $Headers;
		protected static $Conf;
		
		public static function instance() {
			if (!(self::$_instance instanceof Frontend)) {
				self::$_instance = new self;
			}

			return self::$_instance;
		}

		public static function Headers() {
			return self::$Headers;
		}
		
		public function __construct() {
			parent::__construct();
			self::$Headers = new DocumentHeaders;
			self::$Conf = self::Configuration()->jit();
			Lang::load(LANG . '/lang.%s.php', (strlen(trim(self::Configuration()->core()->symphony->lang)) > 0 ? self::Configuration()->core()->symphony->lang : 'en'));
		}

		private static function resolve($string){

			$parameters = (object)array(
				'mode' => 0,
				'width' => 0,
				'height' => 0,
				'position' => 0,
				'background' => 0,	
				'file' => 0,
				'external' => false
			);

			## Mode 3: Resize Canvas
			if(preg_match_all('/^\/3\/([0-9]+)\/([0-9]+)\/([1-9])\/([a-fA-f0-9]{3,6})\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
				$parameters->mode = 3;
				$parameters->width = $matches[0][1];
				$parameters->height = $matches[0][2];
				$parameters->position = $matches[0][3];
				$parameters->background = $matches[0][4];
				$parameters->external = (bool)$matches[0][5];
				$parameters->file = $matches[0][6];
			}

			## Mode 2: Crop to fill
			elseif(preg_match_all('/^\/2\/([0-9]+)\/([0-9]+)\/([1-9])\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
				$parameters->mode = 2;
				$parameters->width = $matches[0][1];
				$parameters->height = $matches[0][2];
				$parameters->position = $matches[0][3];
				$parameters->external = (bool)$matches[0][4];
				$parameters->file = $matches[0][5];
			}

			## Mode 1: Image resize
			elseif(preg_match_all('/^\/1\/([0-9]+)\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
				$parameters->mode = 1;
				$parameters->width = $matches[0][1];
				$parameters->height = $matches[0][2];
				$parameters->external = (bool)$matches[0][3];
				$parameters->file = $matches[0][4];
			}

			## Mode 0: Direct displaying of image
			elseif(preg_match_all('/^\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
				$parameters->external = (bool)$matches[0][1];
				$parameters->file = $matches[0][2];
			}

			return $parameters;
		}

		public function display($url=NULL){

			// Default headers. Can be overwritten later
			//self::$Headers->append('HTTP/1.0 200 OK');
			
			$parameters = self::resolve($url);

			$image_path = ($parameters->external === true ? "http://{$parameters->file}" : WORKSPACE . "/{$parameters->file}");
			
			define_safe('CACHING', ($parameters->external == false && self::$Conf->cache == 'enabled' ? true : false));
			
			if($parameters->external !== true){
				
				if(!file_exists($image_path)){
					throw new JITImageNotFoundException;
				}

				$last_modified = filemtime($image_path);
				$last_modified_gmt = gmdate('r', $last_modified);
				$etag = md5($last_modified . $image_path);

			    self::$Headers->append('ETag', sprintf('"%s"', $etag));

			    if(isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])){
			        if($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $last_modified_gmt || str_replace('"', NULL, stripslashes($_SERVER['HTTP_IF_NONE_MATCH'])) == $etag){
			            header('HTTP/1.1 304 Not Modified');
			            exit();
			        }
			    }

			    self::$Headers->append('Last-Modified', $last_modified_gmt);
			    self::$Headers->append('Cache-Control', 'public');

			}
			
			else {

				$allowed = false;

				$rules = array_map('trim', self::$Conf->{'trusted-external-sites'});

				if(count($rules) > 0){
					foreach($rules as $r){

						$r = str_replace('http://', NULL, $r);

						if($r == '*'){
							$allowed = true;
							break;
						}

						elseif(substr($r, -1) == '*' && strncasecmp($parameters->file, $r, strlen($r) - 1) == 0){
							$allowed = true;
							break;
						}

						elseif(strcasecmp($r, $parameters->file) == 0){
							$allowed = true;
							break;
						}
					}
				}

				if($allowed == false){
					throw new JITException(__('Connecting to that external site is not permitted.'));
				}

			}
			
			## Do cache checking stuff here
			if($parameters->external !== true && CACHING === true){

				$cache_file = sprintf('%s/%s_%s', CACHE, md5($url . self::$Conf->quality), basename($image_path));

				if(@is_file($cache_file) && (@filemtime($cache_file) < @filemtime($image_path))){ 
					unlink($cache_file);
				}

				elseif(is_file($cache_file)){
					$image_path = $cache_file;
					@touch($cache_file);
					$parameters->mode = self::MODE_NONE;
				}
			}
			####
			
			if($parameters->external !== true && $parameters->mode == self::MODE_NONE){

				if(!file_exists($image_path) || !is_readable($image_path)){
					throw new JITImageNotFoundException;
				}
				
				$meta = Image::getMetaInformation($image_path);
				
				self::Headers()->render();
				Image::renderOutputHeaders($meta->type);
				readfile($image_path);
				exit();
			}
			
			
			try{
				$method = 'load' . ($parameters->external === true ? 'External' : NULL);
				$image = call_user_func_array(array('Image', $method), array($image_path));
			}
			catch(Exception $e){
				throw new JITException($e->getMessage());
			}

			switch($parameters->mode){

				case self::MODE_RESIZE:
					$image->applyFilter('resize', array($parameters->width, $parameters->height));
					break;

				case self::MODE_RESIZE_CROP:

					$src_w = $image->Meta()->width;
					$src_h = $image->Meta()->height;

					$dst_w = $parameters->width;
					$dst_h = $parameters->height;

					if($parameters->height == 0) {
						$ratio = ($src_h / $src_w);
						$dst_w = $parameters->width;
						$dst_h = round($dst_w * $ratio);
					} 

					elseif($parameters->width == 0) {

						$ratio = ($src_w / $src_h);
						$dst_h = $parameters->height;
						$dst_w = round($dst_h * $ratio);

					}

					$src_r = ($src_w / $src_h);
					$dst_r = ($dst_w / $dst_h);

					if($src_r < $dst_r) $image->applyFilter('resize', array($dst_w, NULL));
					else $image->applyFilter('resize', array(NULL, $dst_h));		

					//	if($src_h < $parameters->height || $src_h > $parameters->height) ImageFilters::resize($image, NULL, $parameters->height);
					//	if($src_w < $parameters->width) ImageFilters::resize($image, $parameters->width, NULL);			

				case self::MODE_CROP:
					$image->applyFilter('crop', array($parameters->width, $parameters->height, $parameters->position, $parameters->background));
					break;
			}

			self::Headers()->render();
			if(!$image->display((int)self::$Conf->quality)){
				throw new JITException(__('Error generating image'));
			}

			if(CACHING && !is_file($cache_file)){ 
				$image->save($cache_file, (int)self::$Conf->quality);
			}
			
			exit();
			
		}
	}

	return 'rendererJITImageManipulation';
