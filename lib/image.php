<?php

	@ini_set('display_errors', 'off');
	@ini_set("gd.jpeg_ignore_warning", 1);

	define('DOCROOT', rtrim(realpath(dirname(__FILE__) . '/../../../'), '/'));
	define('DOMAIN', rtrim(rtrim($_SERVER['HTTP_HOST'], '/') . str_replace('/extensions/jit_image_manipulation/lib', NULL, dirname($_SERVER['PHP_SELF'])), '/'));

	// Include some parts of the engine
	require_once DOCROOT . '/vendor/autoload.php';
	require_once DOCROOT . '/symphony/lib/boot/bundle.php';
	require_once 'class.image.php';
	require_once CONFIG;

	// Setup the environment
	if(method_exists('DateTimeObj', 'setSettings')) {
		DateTimeObj::setSettings($settings['region']);
	}
	else {
		DateTimeObj::setDefaultTimezone($settings['region']['timezone']);
	}

	define_safe('MODE_NONE', 0);
	define_safe('MODE_RESIZE', 1);
	define_safe('MODE_RESIZE_CROP', 2);
	define_safe('MODE_CROP', 3);
	define_safe('MODE_FIT', 4);
	define_safe('CACHING', ($settings['image']['cache'] == 1 ? true : false));

	set_error_handler('__errorHandler');

	function processParams($string, &$image_settings){
		$param = (object)array(
			'mode' => 0,
			'width' => 0,
			'height' => 0,
			'position' => 0,
			'background' => 0,
			'file' => 0,
			'external' => false
		);

		// Check for matching recipes
		if(file_exists(WORKSPACE . '/jit-image-manipulation/recipes.php')) include(WORKSPACE . '/jit-image-manipulation/recipes.php');

		// check to see if $recipes is even available before even checking if it is an array
		if (!empty($recipes) && is_array($recipes)) {
			foreach($recipes as $recipe) {
				// Is the mode regex? If so, bail early and let not JIT process it.
				if($recipe['mode'] === 'regex' && preg_match($recipe['url-parameter'], $string)) {
					// change URL to a "normal" JIT URL
					$string = preg_replace($recipe['url-parameter'], $recipe['jit-parameter'], $string);
					$is_regex = true;
					if (!empty($recipe['quality'])) {
						$image_settings['quality'] = $recipe['quality'];
					}
					break;
				}
				// Nope, we're not regex, so make a regex and then check whether we this recipe matches
				// the URL string. If not, continue to the next recipe.
				else if(!preg_match('/^' . $recipe['url-parameter'] . '\//i', $string, $matches)) {
					continue;
				}

				// If we're here, the recipe name matches, so we'll go on to fill out the params

				// Is it an external image?
				$param->external = (bool)$recipe['external'];

				// Path to file
				$param->file = substr($string, strlen($recipe['url-parameter']) + 1);

				// Set output quality
				if (!empty($recipe['quality'])) {
					$image_settings['quality'] = $recipe['quality'];
				}

				// Specific variables based off mode
				// 0 is ignored (direct display)
				// regex is already handled
				switch ($recipe['mode']) {
					// Resize
					case '1':
					// Resize to fit
					case '4':
						$param->mode = (int)$recipe['mode'];
						$param->width = (int)$recipe['width'];
						$param->height = (int)$recipe['height'];
						break;

					// Resize and crop
					case '2':
					// Crop
					case '3':
						$param->mode = (int)$recipe['mode'];
						$param->width = (int)$recipe['width'];
						$param->height = (int)$recipe['height'];
						$param->position = (int)$recipe['position'];
						$param->background = $recipe['background'];
						break;
				}

				return $param;
			}
		}

		// Check if only recipes are allowed.
		// We only have to check if we are using a `regex` recipe
		// because the other recipes already return `$param`.
		if($image_settings['disable_regular_rules'] == 'yes' && $is_regex != true){
			Page::renderStatusCode(Page::HTTP_STATUS_NOT_FOUND);
			trigger_error('Error generating image', E_USER_ERROR);
			echo 'Regular JIT rules are disabled and no matching recipe was found.';
			exit;
		}

		// Mode 2: Resize and crop
		// Mode 3: Crop
		if(preg_match_all('/^(2|3)\/([0-9]+)\/([0-9]+)\/([1-9])\/([a-fA-F0-9]{3,6}\/)?(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = (int)$matches[0][1];
			$param->width = (int)$matches[0][2];
			$param->height = (int)$matches[0][3];
			$param->position = (int)$matches[0][4];
			$param->background = trim($matches[0][5],'/');
			$param->external = (bool)$matches[0][6];
			$param->file = $matches[0][7];
		}

		// Mode 1: Resize
		// Mode 4: Resize to fit
		else if(preg_match_all('/^(1|4)\/([0-9]+)\/([0-9]+)\/(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->mode = (int)$matches[0][1];
			$param->width = (int)$matches[0][2];
			$param->height = (int)$matches[0][3];
			$param->external = (bool)$matches[0][4];
			$param->file = $matches[0][5];
		}

		// Mode 0: Direct display of image
		elseif(preg_match_all('/^(?:(0|1)\/)?(.+)$/i', $string, $matches, PREG_SET_ORDER)){
			$param->external = (bool)$matches[0][1];
			$param->file = $matches[0][2];
		}

		return $param;
	}

	$param = processParams($_GET['param'], $settings['image']);

	// If the background has been set, ensure that it's not mistakenly
	// a folder. This is rare edge case in that if a folder is named like
	// a hexcode, JIT will interpret it as the background colour instead of
	// the filepath.
	// @link https://github.com/symphonycms/jit_image_manipulation/issues/8
	if(($param->background !== 0 || empty($param->background)) && $param->external === false) {
		// Also check the case of `bbbbbb/bbbbbb/file.png`, which should resolve
		// as background = bbbbbb, file = bbbbbb/file.png (if that's the correct path of
		// course)
		if(
			is_dir(WORKSPACE . '/'. $param->background)
			&& (!is_file(WORKSPACE . '/' . $param->file) && is_file(WORKSPACE . '/' . $param->background . '/' . $param->file))
		) {
			$param->file = $param->background . '/' . $param->file;
			$param->background = 0;
		}
	}

	function __errorHandler($errno=NULL, $errstr, $errfile=NULL, $errline=NULL, $errcontext=NULL){
		global $param;

		if(error_reporting() != 0 && in_array($errno, array(E_WARNING, E_USER_WARNING, E_ERROR, E_USER_ERROR))){
			$Log = new Log(ACTIVITY_LOG);
			$Log->pushToLog("{$errno} - ".strip_tags((is_object($errstr) ? $errstr->generate() : $errstr)).($errfile ? " in file {$errfile}" : '') . ($errline ? " on line {$errline}" : ''), $errno, true);
			$Log->pushToLog(
				sprintf(
					'Image class param dump - mode: %d, width: %d, height: %d, position: %d, background: %d, file: %s, external: %d, raw input: %s',
					$param->mode,
					$param->width,
					$param->height,
					$param->position,
					$param->background,
					$param->file,
					(bool)$param->external,
					$_GET['param']
				), E_NOTICE, true
			);
		}
	}

	$meta = $cache_file = NULL;
	$image_path = ($param->external === true ? "http://{$param->file}" : WORKSPACE . "/{$param->file}");

	// If the image is not external check to see when the image was last modified
	if($param->external !== true){
		$last_modified = is_file($image_path) ? filemtime($image_path) : null;
	}
	// Image is external, check to see that it is a trusted source
	else {
		$rules = file(WORKSPACE . '/jit-image-manipulation/trusted-sites', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
		$allowed = false;

		$rules = array_map('trim', $rules);

		if(count($rules) > 0) foreach($rules as $rule) {
			$rule = str_replace(array('http://', 'https://'), NULL, $rule);

			// Wildcard
			if($rule == '*'){
				$allowed = true;
				break;
			}

			// Wildcard after domain
			else if(substr($rule, -1) == '*' && strncasecmp($param->file, $rule, strlen($rule) - 1) == 0){
				$allowed = true;
				break;
			}

			// Match the start of the rule with file path
			else if(strncasecmp($rule, $param->file, strlen($rule)) == 0){
				$allowed = true;
				break;
			}

			// Match subdomain wildcards
			else if(substr($rule, 0, 1) == '*' && preg_match("/(".substr((substr($rule, -1) == '*' ? rtrim($rule, "/*") : $rule), 2).")/", $param->file)){
				$allowed = true;
				break;
			}
		}

		if($allowed == false){
			Page::renderStatusCode(Page::HTTP_STATUS_FORBIDDEN);
			exit(sprintf('Error: Connecting to %s is not permitted.', $param->file));
		}

		$last_modified = strtotime(Image::getHttpHeaderFieldValue($image_path, 'Last-Modified'));
	}

	// if there is no `$last_modified` value, params should be NULL and headers
	// should not be set. Otherwise, set caching headers for the browser.
	if($last_modified) {
		$last_modified_gmt = gmdate('D, d M Y H:i:s', $last_modified) . ' GMT';
		$etag = md5($last_modified . $image_path);
		$cacheControl = 'public';
		
		// Add no-transform in order to prevent ISPs to
		// serve image over http through a compressing proxy
		// See #79
		if ($settings['image']['disable_proxy_transform'] == 'yes') {
			$cacheControl .= ', no-transform';
		}
		
		header('Last-Modified: ' . $last_modified_gmt);
		header(sprintf('ETag: "%s"', $etag));
		header('Cache-Control: '. $cacheControl);
	}
	else {
		$last_modified_gmt = NULL;
		$etag = NULL;
	}

	// Check to see if the requested image needs to be generated or if a 304
	// can just be returned to the browser to use it's cached version.
	if(CACHING === true && (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH']))){
		if($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $last_modified_gmt || str_replace('"', NULL, stripslashes($_SERVER['HTTP_IF_NONE_MATCH'])) == $etag){
			Page::renderStatusCode(Page::HTTP_NOT_MODIFIED);
			exit;
		}
	}

	// The 'image_path' may change and point to a cache file, but we will
	// still need to know which file is supposed to be processed.
	$original_file = $image_path;

	// If CACHING is enabled, check to see that the cached file is still valid.
	if(CACHING === true){
		$cache_file = sprintf('%s/%s_%s', CACHE, md5($_REQUEST['param'] . intval($settings['image']['quality'])), basename($image_path));

		// Cache has expired or doesn't exist
		if(is_file($cache_file) && (filemtime($cache_file) < $last_modified)){
			unlink($cache_file);
		}
		else if(is_file($cache_file)) {
			$image_path = $cache_file;
			touch($cache_file);
			$param->mode = MODE_NONE;
		}
	}

	// If there is no mode for the requested image, just read the image
	// from it's location (which may be external)
	if($param->mode == MODE_NONE){
		if(
			// If the external file still exists
			($param->external && Image::getHttpResponseCode($original_file) != 200)
			// If the file is local, does it exist and can we read it?
			|| ($param->external === FALSE && (!file_exists($original_file) || !is_readable($original_file)))
		) {
			// Guess not, return 404.
			Page::renderStatusCode(Page::HTTP_STATUS_NOT_FOUND);
			trigger_error(sprintf('Image <code>%s</code> could not be found.', str_replace(DOCROOT, '', $original_file)), E_USER_ERROR);
			echo sprintf('Image <code>%s</code> could not be found.', str_replace(DOCROOT, '', $original_file));
			exit;
		}
		$meta = Image::getMetaInformation($image_path);
		Image::renderOutputHeaders($meta->type);
		readfile($image_path);
		exit;
	}

	// There is mode, or the image to JIT is external, so call `Image::load` or
	// `Image::loadExternal` to load the image into the Image class
	try{
		$method = 'load' . ($param->external === true ? 'External' : NULL);
		$image = call_user_func_array(array('Image', $method), array($image_path));

		if(!$image instanceof Image) {
			throw new Exception('Error generating image');
		}
	}
	catch(Exception $e){
		Page::renderStatusCode(Page::HTTP_STATUS_BAD_REQUEST);
		trigger_error($e->getMessage(), E_USER_ERROR);
		echo $e->getMessage();
		exit;
	}

	// Calculate the correct dimensions. If necessary, avoid upscaling the image.
	$src_w = $image->Meta()->width;
	$src_h = $image->Meta()->height;
	if ($settings['image']['disable_upscaling'] == 'yes') {
		$dst_w = min($param->width, $src_w);
		$dst_h = min($param->height, $src_h);
	} else {
		$dst_w = $param->width;
		$dst_h = $param->height;
	}

	// Make sure we have a valid size
	if ($dst_w == 0 && $dst_h == 0) {
		// Return 400
		Page::renderStatusCode(Page::HTTP_STATUS_BAD_REQUEST);
		// Init log
		Symphony::initialiseLog();
		// Get referrer
		$httpRef = General::sanitize($_SERVER["HTTP_REFERER"]);
		if (!$httpRef) {
			$httpRef = 'unknown referrer';
		}
		// push to log
		Symphony::Log()->pushToLog(sprintf('Invalid size (0 x 0) requested from "%s"', $httpRef), E_WARNING, true);
		// output and exit
		echo 'Both width and height can not be 0';
		exit;
	}

	// Apply the filter to the Image class (`$image`)
	switch($param->mode) {
		case MODE_RESIZE:
			$image->applyFilter('resize', array($dst_w, $dst_h));
			break;

		case MODE_FIT:
			if($param->height == 0) {
				$ratio = ($src_h / $src_w);
				$dst_h = round($dst_w * $ratio);
			}

			else if($param->width == 0) {
				$ratio = ($src_w / $src_h);
				$dst_w = round($dst_h * $ratio);
			}

			$src_r = ($src_w / $src_h);
			$dst_r = ($dst_w / $dst_h);

			if ($src_h <= $dst_h && $src_w <= $dst_w){
				$image->applyFilter('resize', array($src_w,$src_h));
				break;
			}

			if($src_h >= $dst_h && $src_r <= $dst_r) {
				$image->applyFilter('resize', array(NULL, $dst_h));
			}

			if($src_w >= $dst_w && $src_r >= $dst_r) {
				$image->applyFilter('resize', array($dst_w, NULL));
			}

			break;

		case MODE_RESIZE_CROP:
			if($param->height == 0) {
				$ratio = ($src_h / $src_w);
				$dst_h = round($dst_w * $ratio);
			}

			else if($param->width == 0) {
				$ratio = ($src_w / $src_h);
				$dst_w = round($dst_h * $ratio);
			}

			$src_r = ($src_w / $src_h);
			$dst_r = ($dst_w / $dst_h);

			if($src_r < $dst_r) {
				$image->applyFilter('resize', array($dst_w, NULL));
			}
			else {
				$image->applyFilter('resize', array(NULL, $dst_h));
			}

		case MODE_CROP:
			$image->applyFilter('crop', array($dst_w, $dst_h, $param->position, $param->background));
			break;
	}

	// If CACHING is enabled, and a cache file doesn't already exist,
	// save the JIT image to CACHE using the Quality setting from Symphony's
	// Configuration.
	if(CACHING && !is_file($cache_file)){
		if(!$image->save($cache_file, intval($settings['image']['quality']))) {
			Page::renderStatusCode(Page::HTTP_STATUS_NOT_FOUND);
			trigger_error('Error generating image', E_USER_ERROR);
			echo 'Error generating image, failed to create cache file.';
			exit;
		}
	}

	// Display the image in the browser using the Quality setting from Symphony's
	// Configuration. If this fails, trigger an error.
	if(!$image->display(intval($settings['image']['quality']))) {
		Page::renderStatusCode(Page::HTTP_STATUS_NOT_FOUND);
		trigger_error('Error generating image', E_USER_ERROR);
		echo 'Error generating image';
		exit;
	}

	exit;
