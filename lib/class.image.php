<?php

	Class Image {
		private $_resource;
		private $_meta;
		private $_image;

		const DEFAULT_QUALITY = 80;
		const DEFAULT_INTERLACE = true;

		private function __construct($resource, stdClass $meta){
			$this->_resource = $resource;
			$this->_meta = $meta;
		}

		public function __destruct(){
			@imagedestroy($this->_resource);
		}

		public function Resource(){
			return $this->_resource;
		}

		public function Meta(){
			return $this->_meta;
		}

		public static function loadExternal($uri){
			if(function_exists('curl_init')){
				$ch = curl_init();

				curl_setopt($ch, CURLOPT_URL, $uri);
				curl_setopt($ch, CURLOPT_HEADER, 0);
				curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

				$tmp = curl_exec($ch);
				curl_close($ch);
			}
			else $tmp = @file_get_contents($uri);

			if($tmp === false){
				new Exception(__('Error reading external image <code>%s</code>. Please check the URI.', array($uri)));
			}

			$dest = @tempnam(@sys_get_temp_dir(), 'IMAGE');

			if(!@file_put_contents($dest, $tmp)) new Exception(__('Error writing to temporary file <code>%s</code>.', array($dest)));

			return self::load($dest);
		}

		public static function load($image){
			if(!is_file($image) || !is_readable($image)){
				throw new Exception(__('Error loading image <code>%s</code>. Check it exists and is readable.', array($image)));
			}

			$meta = self::getMetaInformation($image);

			switch($meta->type) {
				// GIF
				case IMAGETYPE_GIF:
					$resource = imagecreatefromgif($image);
					break;

				// JPEG
				case IMAGETYPE_JPEG:

					if($meta->channels <= 3){
						$resource = imagecreatefromjpeg($image);
					}
					// Can't handle CMYK JPEG files
					else{
						throw new Exception(__('Cannot load CMYK JPG Images'));
					}
					break;

				// PNG
				case IMAGETYPE_PNG:
					$resource = imagecreatefrompng($image);
					break;

				default:
					throw new Exception(__('Unsupported image type. Supported types: GIF, JPEG and PNG'));
					break;
			}

			if(!is_resource($resource)){
				throw new Exception(__('Error loading image <code>%s</code>. Check it exists and is readable.', array($image)));
			}

			$obj = new self($resource, $meta);

			return $obj;
		}

		public static function getMetaInformation($file){
			if(!$array = @getimagesize($file)) return false;

			$meta = array();

			$meta['width']	  = $array[0];
			$meta['height']	  = $array[1];
			$meta['type']	  = $array[2];
			$meta['channels'] = $array['channels'];

			return (object)$meta;
		}

		public static function renderOutputHeaders($output, $dest=NULL){
			header('Content-Type: ' . image_type_to_mime_type($output));

			if(!$dest) return;

			// Try to remove old extension
			$ext = strrchr($dest, '.');
			if($ext !== false){
				$dest = substr($dest, 0, -strlen($ext));
			}

			header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
			header("Content-Disposition: inline; filename=$dest" . image_type_to_extension($output));
			header('Pragma: no-cache');
		}

		/**
		 * Get the HTTP response code of a resource
		 *
		 * @param string $url
		 * @return integer - HTTP response code
		 */
		public static function getHttpResponseCode($url){
			$ch = curl_init();
			$options = array(
				CURLOPT_URL => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_NOBODY => true,
			);
			curl_setopt_array($ch, $options);
			curl_exec($ch);
		    $info = curl_getinfo($ch);
			curl_close($ch);

			$status = $info['http_code'];
			return $status;
		}

		/**
		 * Get the value of a named HTTP response header field
		 *
		 * @param string $url
		 * @param string $field - name of the header field
		 * @return string - value of the header field
		 */
		public static function getHttpHeaderFieldValue($url, $field){
			$headers = self::getHttpHeaders($url);
			$value = $headers[$field];
			return $value;
		}

		/**
		 * Get all HTTP response headers as an array
		 *
		 * @param string $url
		 * @return array - response headers
		 */
		public static function getHttpHeaders($url){
			$ch = curl_init();
			$options = array(
				CURLOPT_URL => $url,
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_HEADER => true,
				CURLOPT_NOBODY => true,
			);
			curl_setopt_array($ch, $options);
			$head = curl_exec($ch);
			curl_close($ch);
			$headers = self::parseHttpHeaderFields($head);
			return $headers;
		}

		/**
		 * Parse HTTP response headers
		 *
		 * @param string $header - response header
		 * @return array - header fields; name => value
		 */
		public static function parseHttpHeaderFields($header){
			$retVal = array();
			$fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
			foreach( $fields as $field ){
				if( preg_match('/([^:]+): (.+)/m', $field, $match) ){
					$match[1] = preg_replace('/(?<=^|[\x09\x20\x2D])./e', 'strtoupper("\0")', strtolower(trim($match[1])));
					if( isset($retVal[$match[1]]) ){
						$retVal[$match[1]] = array($retVal[$match[1]], $match[2]);
					}
					else{
						$retVal[$match[1]] = trim($match[2]);
					}
				}
			}
			return $retVal;
		}

		public static function height(Resource $res){
			return imagesy($res);
		}

		public static function width(Resource $res){
			return imagesx($res);
		}

		public function display($quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null) {
			if(!$output) $output = $this->Meta()->type; //DEFAULT_OUTPUT_TYPE;

			self::renderOutputHeaders($output);

			if(isset($this->_image) && is_resource($this->_image)) {
				return $this->_image;
			}
			else return self::__render(NULL, $quality, $output, $interlacing);
		}

		public function save($dest, $quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null) {
			if(!$output) $output = $this->Meta()->type; //DEFAULT_OUTPUT_TYPE;

			$this->_image = self::__render($dest, $quality, $output, $interlacing);
			return $this->_image;
		}

		private function __render($dest, $quality, $output, $interlacing=false){
			if(!is_resource($this->_resource)) {
				throw new Exception(__('Invalid image resource supplied'));
			}

			// Turn interlacing on for JPEG or PNG only
			if($interlacing && ($output == IMAGETYPE_JPEG || $output == IMAGETYPE_PNG)) {
				imageinterlace($this->_resource);
			}

			switch($output) {
				case IMAGETYPE_GIF:
					return imagegif($this->_resource, $dest);
					break;

				case IMAGETYPE_PNG:
					return imagepng($this->_resource, $dest, round(9 * ($quality * 0.01)));
					break;

				case IMAGETYPE_JPEG:
				default:
					return imagejpeg($this->_resource, $dest, $quality);
					break;
			}

			return false;
		}

		public function applyFilter($filter, array $args = array()) {
			require_once("filters/filter.{$filter}.php");

			array_unshift($args, $this->_resource);

			$this->_resource = call_user_func_array(array(sprintf('Filter%s', ucfirst($filter)), 'run'), $args);

			return true;
		}

	}
