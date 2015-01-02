<?php

	require_once(TOOLKIT . '/class.gateway.php');

	Class Image {
		private $_resource;
		private $_meta;
		private $_image;
		static $_result;

		const DEFAULT_QUALITY = 80;
		const DEFAULT_INTERLACE = true;
		const CURL_MAXREDIRS = 6;

		private function __construct($resource, stdClass $meta){
			$this->_resource = $resource;
			$this->_meta = $meta;
		}

		public function __destruct(){
			if(is_resource($this->_resource)) {
				imagedestroy($this->_resource);
			}
		}

		public function Resource(){
			return $this->_resource;
		}

		public function Meta(){
			return $this->_meta;
		}

		/**
		 * This function will attempt to load an image from a remote URL using
		 * CURL. If CURL is not available, `file_get_contents` will attempt to resolve
		 * the given `$uri`. The remote image will be saved into PHP's temporary directory
		 * as determined by `sys_get_temp_dir`. Once the remote image has been
		 * saved to the temp directory, it's path will be passed to `Image::load`
		 * to return an instance of this `Image` class. If the file cannot be found
		 * an Exception is thrown.
		 *
		 * @param string $uri
		 *  The URL of the external image to load.
		 * @return Image
		 */
		public static function loadExternal($uri){
			// create the Gateway object
			$gateway = new Gateway();
			// set our url
			$gateway->init($uri);
			// set some options
			$gateway->setopt(CURLOPT_HEADER, false);
			$gateway->setopt(CURLOPT_RETURNTRANSFER, true);
			$gateway->setopt(CURLOPT_FOLLOWLOCATION, true);
			$gateway->setopt(CURLOPT_MAXREDIRS, Image::CURL_MAXREDIRS);
			// get the raw body response, ignore errors
			$response = @$gateway->exec();

			if($response === false){
				throw new Exception(sprintf('Error reading external image <code>%s</code>. Please check the URI.', $uri));
			}

			// clean up
			$gateway->flush();

			// Symphony 2.4 enhances the TMP constant so it can be relied upon
			$dest = tempnam(TMP, 'IMAGE');

			if(!file_put_contents($dest, $response)) {
				throw new Exception(sprintf('Error writing to temporary file <code>%s</code>.', $dest));
			}

			return self::load($dest);
		}

		/**
		 * Given a path to an image, `$image`, this function will verify it's
		 * existence, and generate a resource for use with PHP's image functions
		 * based off the file's type (.gif, .jpg, .png).
		 * Images must be RGB, CMYK jpg's are not supported due to GD limitations.
		 *
		 * @param string $image
		 *  The path to the file
		 * @return Image
		 */
		public static function load($image){
			if(!is_file($image) || !is_readable($image)){
				throw new Exception(sprintf('Error loading image <code>%s</code>. Check it exists and is readable.', str_replace(DOCROOT, '', $image)));
			}

			$meta = self::getMetaInformation($image);

			switch($meta->type) {
				// GIF
				case IMAGETYPE_GIF:
					$resource = imagecreatefromgif($image);
					break;

				// JPEG
				case IMAGETYPE_JPEG:
					// GD 2.0.22 supports basic CMYK to RGB conversion.
					// RE: https://github.com/symphonycms/jit_image_manipulation/issues/47
					$gdSupportsCMYK = version_compare(GD_VERSION, '2.0.22', '>=');

					// Can't handle CMYK JPEG files
					if ($meta->channels > 3 && $gdSupportsCMYK === false) {
						throw new Exception('Cannot load CMYK JPG images');

					// Can handle CMYK, or image has less than 3 channels.
					} else{
						$resource = imagecreatefromjpeg($image);
					}
					break;

				// PNG
				case IMAGETYPE_PNG:
					$resource = imagecreatefrompng($image);
					break;

				default:
					throw new Exception('Unsupported image type. Supported types: GIF, JPEG and PNG');
					break;
			}

			if(!is_resource($resource)){
				throw new Exception(sprintf('Error creating image <code>%s</code>. Check it exists and is readable.', str_replace(DOCROOT, '', $image)));
			}

			$obj = new self($resource, $meta);

			return $obj;
		}

		/**
		 * Given a path to a file, this function will attempt to find out the
		 * dimensions, type and channel information using `getimagesize`.
		 *
		 * @link http://www.php.net/manual/en/function.getimagesize.php
		 * @param string $file
		 *  The path to the image.
		 */
		public static function getMetaInformation($file){
			if(!$array = @getimagesize($file)) return false;

			$meta = array();

			$meta['width'] = $array[0];
			$meta['height'] = $array[1];
			$meta['type'] = $array[2];
			$meta['channels'] = isset($array['channels']) ? $array['channels'] : false;

			return (object)$meta;
		}

		/**
		 * Given string representing the type return by `getMetaInformation`,
		 * this function will generate the correct Content-Type header using
		 * `image_type_to_mime_type` function.
		 *
		 * @see getMetaInformation()
		 * @link http://php.net/manual/en/function.image-type-to-mime-type.php
		 * @link http://www.php.net/manual/en/image.constants.php
		 * @param integer $type
		 *  One of the IMAGETYPE constants
		 * @param string $destination
		 *  The destination of the image. This defaults to null, if provided,
		 *  this function will prompt the user to download the image rather
		 *  than display it inline
		 */
		public static function renderOutputHeaders($type, $destination=NULL){
			header('Content-Type: ' . image_type_to_mime_type($type));

			if(is_null($destination)) return;

			// Try to remove old extension
			$ext = strrchr($destination, '.');
			if($ext !== false){
				$destination = substr($destination, 0, -strlen($ext));
			}

			header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
			header("Content-Disposition: inline; filename=$destination" . image_type_to_extension($type));
			header('Pragma: no-cache');
		}

		/**
		 * Get the HTTP response code of a resource
		 *
		 * @param string $url
		 * @return integer - HTTP response code
		 */
		public static function getHttpResponseCode($url){
			$head = self:: getHttpHead($url);
			return $head['info']['http_code'];
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
			return $headers[$field];
		}

		/**
		 * Get all HTTP response headers as an array
		 *
		 * @param string $url
		 * @return array - response headers
		 */
		public static function getHttpHeaders($url){
			$head = self:: getHttpHead($url);
			return $head['headers'];
		}

		/**
		 * Get all HTTP response headers and info as an array of arrays.
		 *
		 * @since 1.17
		 *
		 * @param string $url
		 * @return array
		 *  Contains the headers and the infos
		 */
		public static function getHttpHead($url){
			// Check if we have a cached result
			if(isset(self::$_result[$url])) {
				return self::$_result[$url];
			}

			// create the Gateway object
			$gateway = new Gateway();
			// set our url
			$gateway->init($url);
			// set some options
			$gateway->setopt(CURLOPT_URL, $url);
			$gateway->setopt(CURLOPT_RETURNTRANSFER, true);
			$gateway->setopt(CURLOPT_HEADER, true);
			$gateway->setopt(CURLOPT_NOBODY, true);
			$gateway->setopt(CURLOPT_FOLLOWLOCATION, true);
			$gateway->setopt(CURLOPT_MAXREDIRS, Image::CURL_MAXREDIRS);
			// get the raw head response, ignore errors
			$head = @$gateway->exec();
			// Get all info
			$result = array(
				'headers' => array(),
				'info' => $gateway->getInfoLast()
			);
			// Clean up
			$gateway->flush();

			if ($head !== false) {
				$result['headers'] = self::parseHttpHeaderFields($head);
			}

			// Save for subsequent requests
			self::$_result[$url] = $result;

			return $result;
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

		/**
		 * Accessor to return of the height of the given `$res`
		 *
		 * @param resource $res
		 * @return integer
		 *  The height of the image in pixels, false otherwise
		 */
		public static function height($res){
			return imagesy($res);
		}

		/**
		 * Accessor to return of the width of the given `$res`
		 *
		 * @param resource $res
		 * @return integer
		 *  The width of the image in pixels, false otherwise
		 */
		public static function width($res){
			return imagesx($res);
		}

		/**
		 * Given a filter name, this function will attempt to load the filter
		 * from the `/filters` folder and call it's `run` method. The JIT core
		 * provides filters for 'crop', 'resize' and 'scale'.
		 *
		 * @param string $filter
		 *  The filter name
		 * @param array $args
		 *  The arguments to pass to the filter's `run()` method
		 * @return boolean
		 */
		public function applyFilter($filter = null, array $args = array()) {
			if(is_null($filter) || !is_file(EXTENSIONS . "/jit_image_manipulation/lib/filters/filter.{$filter}.php")) return false;

			require_once("filters/filter.{$filter}.php");

			array_unshift($args, $this->_resource);

			$this->_resource = call_user_func_array(array(sprintf('Filter%s', ucfirst($filter)), 'run'), $args);

			return true;
		}

		/**
		 * The function takes optional `$quality`, `$interlacing` and `$output`
		 * parameters to return an image resource after it has been processed
		 * using `applyFilter`.
		 *
		 * @param integer $quality
		 *  Range of 1-100, if not provided, uses `Image::DEFAULT_QUALITY`
		 * @param boolean $interlacing
		 *  If true, the resulting image will be interlaced, otherwise it won't.
		 *  By default uses the value of `Image::DEFAULT_INTERLACE`
		 * @param IMAGETYPE_xxx $output
		 *  A IMAGETYPE constant of the image's type, by default this is null
		 *  which will attempt to get the constant using the `$this->Meta` accessor
		 * @return resource
		 */
		public function display($quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null) {
			if(!$output) $output = $this->Meta()->type; //DEFAULT_OUTPUT_TYPE;

			self::renderOutputHeaders($output);

			if(isset($this->_image) && is_resource($this->_image)) {
				return $this->_image;
			}
			else return self::__render(NULL, $quality, $interlacing, $output);
		}

		/**
		 * This function will attempt to save an image at a desired destination.
		 *
		 * @param string $dest
		 *  The path to save the image at
		 * @param integer $quality
		 *  Range of 1-100, if not provided, uses `Image::DEFAULT_QUALITY`
		 * @param boolean $interlacing
		 *  If true, the resulting image will be interlaced, otherwise it won't.
		 *  By default uses the value of `Image::DEFAULT_INTERLACE`
		 * @param IMAGETYPE_xxx $output
		 *  A IMAGETYPE constant of the image's type, by default this is null
		 *  which will attempt to get the constant using the `$this->Meta` accessor
		 * @return boolean
		 */
		public function save($dest, $quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null) {
			if(!$output) $output = $this->Meta()->type; //DEFAULT_OUTPUT_TYPE;

			$this->_image = self::__render($dest, $quality, $interlacing, $output);
			return $this->_image;
		}

		/**
		 * Renders the `$this->_resource` using the PHP image functions to save the
		 * image at a location specified by `$dest`.
		 *
		 * @param string $dest
		 *  The path to save the image at
		 * @param integer $quality
		 *  Range of 1-100, if not provided, uses `Image::DEFAULT_QUALITY`
		 * @param boolean $interlacing
		 *  If true, the resulting image will be interlaced, otherwise it won't.
		 *  By default uses the value of `Image::DEFAULT_INTERLACE`
		 * @param IMAGETYPE_xxx $output
		 *  A IMAGETYPE constant of the image's type, by default this is null
		 *  which will attempt to get the constant using the `$this->Meta` accessor
		 * @return boolean
		 */
		private function __render($dest, $quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null){
			if(!is_resource($this->_resource)) {
				throw new Exception('Invalid image resource supplied');
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

	}
