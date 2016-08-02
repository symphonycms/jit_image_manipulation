<?php

namespace JIT;

use Symphony;
use SymphonyErrorPage;
use Page;
use General;

require_once __DIR__ . '/interface.imagefilter.php';
require_once __DIR__ . '/class.imagefilter.php';
require_once __DIR__ . '/class.jitfiltermanager.php';
require_once __DIR__ . '/class.image.php';

class JIT extends Symphony
{

    /**
     * @var array
     */
    private $settings = array();

    /**
     * @var boolean
     */
    private $caching = false;

    /**
     * @var array
     */
    public static $available_filters = array();

    /**
     * This function returns an instance of the JIT
     * class. It is the only way to create a new JIT, as
     * it implements the Singleton interface
     *
     * @return JIT
     */
    public static function instance()
    {
        if (!(self::$_instance instanceof JIT)) {
            self::$_instance = new JIT;
        }

        return self::$_instance;
    }

    public static function getAvailableFilters()
    {
        if (empty(self::$available_filters)) {
            $filters = JitFilterManager::listAll();

            foreach ($filters as $filter) {
                self::$available_filters[] = JitFilterManager::create($filter['handle']);
            }
        }

        return self::$available_filters;
    }

    public function display()
    {
        $this->settings = Symphony::Configuration()->get('image');
        $this->caching = (\General::intval($this->settings['cache']) === 1);

        // process the parameters
        $param = $this->parseParameters($_GET['param']);
        $param['destination'] = isset($_GET['save']);

        // get the actual image path
        $param['image_path'] = $this->fetchImagePath($param);

        // is this thing cached?
        if ($image = $this->fetchValidImageCache($param)) {
            $param['cache'] = 'HIT';
            // prepare caching headers
            $this->sendImageHeaders($param);
            // send image
            $this->displayImage($image);
            // end
            return;
        }

        $param['cache'] = !$this->caching ? 'DISABLED' : 'MISS';

        if ($param['image_path']) {
            // fetch image
            $image_resource = $this->fetchImage($param);

            // apply the filter
            $image = $this->applyFilterToImage($image_resource, $param);

            // Cache images, if activated, and log any errors
            try {
                $this->cacheImage($image, $param);
            } catch (\Exception $ex) {
                static::Log()->pushExceptionToLog($ex, true);
            }

            // send all headers
            $this->sendImageHeaders($param);

            // display the image
            $this->displayImage($image);
        }
    }

    /**
     * Given the parameters, tries to load the wanted image from cache.
     * Returns null when not found or if the cache is not valid anymore.
     * Returns false when cache is disabled
     *
     * @param array $parameters
     * @return JIT\Image
     */
    public function fetchValidImageCache(array &$parameters)
    {
        if (!$this->caching) {
            return false;
        }
        // Create cache file path
        $file = $this->createCacheFilename($parameters['image']);

        // Do we have a cache file ?
        if (@file_exists($file) && @is_file($file)) {
            $cache_mtime = @filemtime($file);
            // Validate that the cache is more recent than the original
            if ($this->shouldDoCacheInvalidation()) {
                $original_mtime = $this->fetchLastModifiedTime($parameters);

                // Original file's mtime is not determined or is more recent than cache
                if ($original_mtime === 0 || $original_mtime > $cache_mtime) {
                    // Delete cache
                    General::deleteFile($file);
                    // Export the invalidation state
                    $parameters['cached_invalidated'] = 'invalidated';
                    // Cache is invalid
                    return null;
                } else {
                    // Export the invalidation state
                    $parameters['cached_invalidated'] = 'validated';
                }
            } else {
                // Export the invalidation state
                $parameters['cached_invalidated'] = 'ignored';
            }

            // Load the cache file
            $image = @\Image::load($file);

            if ($image) {
                // Export the last modified time
                $parameters['last_modified'] = $cache_mtime;

                // Export the cache file path
                $parameters['cached_image'] = $file;

                // Export the $image's type
                $parameters['type'] = $image->Meta()->type;

                // This fixes transparency issues
                \JIT\ImageFilter::fill($image->Resource(), $image->Resource());
                return $image;
            }
        }
        // No valid cache found
        return null;
    }

    /**
     * This method returns true when this request should check if the
     * cache is still valid. By default, this method always returns true.
     * If the `cache_invalidation_odds` is properly set, it returns true
     * if the odds are greater than a random number.
     *
     * @return boolean
     *  true if cache validation should occur, false otherwise
     */
    public function shouldDoCacheInvalidation()
    {
        $odds = Symphony::Configuration()->get('cache_invalidation_odds', 'image');
        if (!is_numeric($odds)) {
            return true;
        }
        $odds = min((float)$odds, 1.0);
        $random = (float)mt_rand() / (float)mt_getrandmax();
        return $odds >= $random;
    }

    /**
     * Given the parameters, tries to fetch the last modified time
     * of the image. If the image is local, filemtime is used.
     * If the image is external, a HEAD request is made on the remote server
     * and the Last-Modified header is parsed
     *
     * @param array $parameters
     * @return JIT\Image
     */
    public function fetchLastModifiedTime(array $parameters)
    {
        $original_mtime = 0;
        if ($parameters['settings']['external'] === true) {
            try {
                $image_url = $this->normalizeExternalImageUrl($parameters['image']);
                $infos = \Image::fetchHttpCachingInfos($image_url);
                $original_mtime = strtotime($infos['last_modified']);
            } catch (Exception $ex) {
                $original_mtime = 0;
            }
        } else {
            $image_path = $this->normalizeLocalImagePath($parameters['image']);
            if (@file_exists($image_path) && @is_file($image_path)) {
                $original_mtime = @filemtime($image_path);
            }
        }
        return $original_mtime;
    }

    /**
     * Creates a cache key with the given $image_path
     *
     * @param string $image_path
     * @return string
     */
    public function createCacheFilename($image_path)
    {
        return sprintf('%s/%s_%s', CACHE, md5($_GET['param'] . intval($this->settings['quality'])), basename($image_path));
    }

    /**
     * Given the parameters, this function will attempt to parse them
     * to determine the mode, it's settings and the image path.
     *
     * @param string $parameter_string
     * @return array
     */
    public function parseParameters($parameter_string)
    {
        $settings = array();
        $mode = false;
        $image_path = false;

        // Check for matching recipes
        if (file_exists(WORKSPACE . '/jit-image-manipulation/recipes.php')) {
            include(WORKSPACE . '/jit-image-manipulation/recipes.php');
        }
        // check to see if $recipes is even available before even checking if it is an array
        if (!empty($recipes) && is_array($recipes)) {
            foreach ($recipes as $recipe) {
                // Is the mode regex? If so, bail early and let not JIT process it.
                if ($recipe['mode'] === 'regex' && preg_match($recipe['url-parameter'], $parameter_string)) {
                    // change URL to a "normal" JIT URL
                    $parameter_string = preg_replace($recipe['url-parameter'], $recipe['jit-parameter'], $parameter_string);
                    $is_regex = true;
                    if (!empty($recipe['quality'])) {
                        $this->settings['quality'] = $recipe['quality'];
                    }
                    break;
                } // Nope, we're not regex, so make a regex and then check whether we this recipe matches
                // the URL string. If not, continue to the next recipe.
                elseif (!preg_match('/^' . $recipe['url-parameter'] . '\//i', $parameter_string)) {
                    continue;
                }

                $new_paramstring = $recipe['mode'] . '/';
                $new_paramstring .= $recipe['width'] . '/';
                $new_paramstring .= $recipe['height'] . '/';

                if (isset($recipe['position'])) {
                    $new_paramstring .= $recipe['position'] . '/';
                }

                // If we're here, the recipe name matches, so we'll go on to fill out the params
                // Is it an external image?
                if (isset($recipe['external']) && (bool)$recipe['external']) {
                    $new_paramstring .= '1/';
                }

                // Path to file
                $new_paramstring .= substr($parameter_string, strlen($recipe['url-parameter']) + 1);

                // Set output quality
                if (!empty($recipe['quality'])) {
                    $settings['quality'] = $recipe['quality'];
                }

                // Continue with the new string
                $parameter_string = $new_paramstring;
                $is_recipe = true;
            }
        }

        // Check if only recipes are allowed.
        // We only have to check if we are using a `regex` recipe
        // because the other recipes already return `$param`.
        if ($this->settings['disable_regular_rules'] === 'yes' && $is_regex !== true && $is_recipe !== true) {
            throw new JITParseParametersException('Regular JIT rules are disabled and no matching recipe was found.');
        }

        $filters = self::getAvailableFilters();
        foreach ($filters as $filter) {
            if ($params = $filter->parseParameters($parameter_string)) {
                if ($params['mode'] === $filter->mode) {
                    extract($params);
                    break;
                }
            }
        }

        // Did the delegate resolve anything?
        if (!$mode || !$image_path) {
            throw new JITParseParametersException(
                sprintf('No JIT filter was found for <code>%s</code>.', \General::sanitize($parameter_string))
            );
        }
        // Does the image tries to do parent folder escalation?
        if (preg_match('/[\.]{2}\//', $image_path)) {
            throw new JITParseParametersException(
                sprintf('Invalid image path <code>%s</code>.', \General::sanitize($image_path))
            );
        }

        // If the background has been set, ensure that it's not mistakenly
        // a folder. This is rare edge case in that if a folder is named like
        // a hexcode, JIT will interpret it as the background colour instead of
        // the filepath.
        // @link https://github.com/symphonycms/jit_image_manipulation/issues/8
        if (($settings['background'] !== 0 || empty($settings['background'])) && $settings['external'] === false) {
            // Also check the case of `bbbbbb/bbbbbb/file.png`, which should resolve
            // as background = bbbbbb, file = bbbbbb/file.png (if that's the correct path of
            // course)
            if (is_dir(WORKSPACE . '/'. $settings['background'])
                && (!is_file(WORKSPACE . '/' . $image_path) && is_file(WORKSPACE . '/' . $settings['background'] . '/' . $image_path))
            ) {
                $image_path = $settings['background'] . '/' . $image_path;
                $settings['background'] = 0;
            }
        }

        return array(
            'settings' => $settings,
            'mode' => $mode,
            'image' => $image_path,
            'cache' => null,
        );
    }

    /**
     * Given the raw image url, normally coming from the request, this function
     * will ensure that the correct protocol is applied, defaulting to http.
     *
     * @param string $image
     *  The image url
     * @return string
     *  The normalized url
     */
    public function normalizeExternalImageUrl($image)
    {
        $image_url = null;
        if (preg_match('/^https?:\/\/?/i', $image)) {
            // User agent will reduce multiple slashes (//) after the protocol.
            // This replacement will take this fact into account
            $image_url = preg_replace('/^(https?:)\/([^\/])(.+)$/i', '$1//$2$3', $image);
        }
        else {
            $image_url = "http://$image";
        }
        return $image_url;
    }

    /**
     * Given the raw image path, normally coming from the request, this function
     * will ensure that the relative path is a complete absolute path, relative
     * to the `WORKSPACE` path.
     *
     * @param string $image
     *  The image relative path
     * @return string
     *  The normalized path
     */
    public function normalizeLocalImagePath($image)
    {
        return WORKSPACE . '/' . $image;
    }

    /**
     * Given the parsed parameters, this function will go and grab
     * the desired image, whether it be local, or external.
     *
     * @param array $parameters
     * @return Resource
     */
    public function fetchImagePath(array &$parameters)
    {
        // Fetch external images
        if ($parameters['settings']['external'] === true) {
            $image_path = $this->normalizeExternalImageUrl($parameters['image']);
            $protocolLess = str_replace(array('http://', 'https://'), null, $image_path);

            // Image is external, check to see that it is a trusted source
            $rules = @file(WORKSPACE . '/jit-image-manipulation/trusted-sites', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $allowed = false;

            if (is_array($rules) && count($rules) > 0) {
                $rules = array_map('trim', $rules);
                foreach ($rules as $rule) {
                    $rule = str_replace(array('http://', 'https://'), null, $rule);

                    // Wildcard
                    if ($rule == '*') {
                        $allowed = true;
                        break;
                    } // Wildcard after domain
                    elseif (substr($rule, -1) == '*' && strncasecmp($protocolLess, $rule, strlen($rule) - 1) == 0) {
                        $allowed = true;
                        break;
                    } // Match the start of the rule with file path
                    elseif (strncasecmp($rule, $protocolLess, strlen($rule)) === 0) {
                        $allowed = true;
                        break;
                    } // Match subdomain wildcards
                    elseif (substr($rule, 0, 1) == '*' && preg_match('/(' . preg_quote(substr((substr($rule, -1) == '*' ? rtrim($rule, "/*") : $rule), 2), '/') . ')/', $protocolLess)) {
                        $allowed = true;
                        break;
                    }
                }
            }

            if ($allowed == false) {
                throw new JITDomainNotAllowed(
                    sprintf('Error: Connecting to %s is not permitted.', \General::sanitize($image_path))
                );
            }

        // If the image is not external
        } else {
            $image_path = $this->normalizeLocalImagePath($parameters['image']);
        }

        return $image_path;
    }

    public function sendImageHeaders(array $parameters)
    {
        // Send debug headers
        if (static::isLoggedIn()) {
            header('X-jit-mode: ' . $parameters['mode']);
            header('X-jit-cache: ' . $parameters['cache']);
            if ($this->caching) {
                header('X-jit-cache-file: ' . basename($parameters['cached_image']));
                if (isset($parameters['cached_invalidated'])) {
                    header('X-jit-cache-validation: ' . $parameters['cached_invalidated']);
                }
            }
        }

        // PHP's image type
        $type = isset($parameters['type']) ? $parameters['type'] : IMAGETYPE_JPEG;

        // Send proper content-type
        header('Content-Type: ' . image_type_to_mime_type($type));

        // Send attachment headers, if needed
        if (isset($parameters['destination']) && $parameters['destination'] === true) {
            $destination = basename($parameters['image']);
            // Try to remove old file extension
            $ext = strrchr($destination, '.');
            if ($ext !== false) {
                $destination = substr($destination, 0, -strlen($ext));
            }

            header('Expires: ' . gmdate('D, d M Y H:i:s') . ' GMT');
            header("Content-Disposition: attachment; filename=$destination" . image_type_to_extension($type) . ';');
            header('Pragma: no-cache');
            // exit: no more headers are needed
            return;
        }

        // if there is no `$last_modified` value, params should be NULL and headers
        // should not be set. Otherwise, set caching headers for the browser.
        if (isset($parameters['last_modified']) && !empty($parameters['last_modified'])) {
            $last_modified_gmt = gmdate('D, d M Y H:i:s', $parameters['last_modified']) . ' GMT';
            $etag = md5($parameters['last_modified'] . $parameters['image']);
            $cacheControl = 'public';

            // Add no-transform in order to prevent ISPs to
            // serve image over http through a compressing proxy
            // See #79
            if ($this->settings['disable_proxy_transform'] == 'yes') {
                $cacheControl .= ', no-transform';
            }

            // Use configured max-age or fallback on 3 days (See #88)
            $maxage = isset($this->settings['max_age']) ? \General::intval($this->settings['max_age']) : 60 * 60 * 24 * 3;
            if (!empty($maxage)) {
                // Add max-age directive at the end
                $cacheControl .= '; max-age=' . $maxage;
            }

            header('Last-Modified: ' . $last_modified_gmt);
            header(sprintf('ETag: "%s"', $etag));
            header('Cache-Control: '. $cacheControl);
        } else {
            $last_modified_gmt = null;
            $etag = null;
        }

        // Allow CORS
        // respond to preflights
        if (isset($this->settings['allow_origin']) && $this->settings['allow_origin'] !== null) {
            if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
                // return only the headers and not the content
                // only allow CORS if we're doing a GET - i.e. no sending for now.
                if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD']) && $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] == 'GET') {
                    header('Access-Control-Allow-Origin: *');
                    header('Access-Control-Allow-Headers: X-Requested-With');
                }
            } else {
                header('Origin: ' . $this->settings['allow_origin']);
                header('Access-Control-Allow-Origin: ' . $this->settings['allow_origin']);
                header('Access-Control-Allow-Methods: GET');
                header('Access-Control-Max-Age: 3000');
            }
        }

        // Check to see if the requested image needs to be generated or if a 304
        // can just be returned to the browser to use it's cached version.
        if (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH'])) {
            if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $last_modified_gmt || str_replace('"', null, stripslashes($_SERVER['HTTP_IF_NONE_MATCH'])) == $etag) {
                // see https://www.w3.org/Protocols/rfc2616/rfc2616-sec10.html#sec10.3.5
                // other headers are ignored
                \Page::renderStatusCode(\Page::HTTP_NOT_MODIFIED);
                exit;
            }
        }
    }

    public function fetchImage(array &$parameters)
    {
        // There is mode, or the image to JIT is external, so call `Image::load` or
        // `Image::loadExternal` to load the image into the Image class
        try {
            $method = 'load' . ($parameters['settings']['external'] === true ? 'External' : '');
            $image = call_user_func_array(array('Image', $method), array($parameters['image_path']));

            if (!$image instanceof \Image) {
                throw new JITGenerationError('Could not load image');
            }

            // Export the last modified time
            $parameters['last_modified'] = $image->ModifiedTime();

            // Export the $image's type
            $parameters['type'] = $image->Meta()->type;

        } catch (JITGenerationError $ex) {
            throw $ex;
        } catch (Exception $ex) {
            throw new JITGenerationError($ex->getMessage());
        }

        return $image;
    }

    /**
     * Given a filter name, this function will attempt to load the filter
     * from the `/filters` folder and call it's `run` method. The JIT core
     * provides filters for 'crop', 'resize' and 'scale'.
     *
     * @param \Image $image
     * @param array $parameters
     * @return \Image
     */
    public function applyFilterToImage(\Image $image, $parameters)
    {
        // Calculate the correct dimensions. If necessary, avoid upscaling the image.
        $src_w = $image->Meta()->width;
        $src_h = $image->Meta()->height;
        $dst_w = $parameters['settings']['width'];
        $dst_h = $parameters['settings']['height'];

        $parameters['meta']['disable_upscaling'] = $this->settings['disable_upscaling'] == 'yes';

        if ($parameters['meta']['disable_upscaling']) {
            if ($src_w < $dst_w) {
                if ($dst_h > 0) {
                    $dst_h = $src_w * ($dst_h / $dst_w);
                }
                $dst_w = $src_w;
            }

            if ($src_h < $dst_h) {
                if ($dst_w > 0) {
                    $dst_w = $src_h * ($dst_w / $dst_h);
                }
                $dst_h = $src_h;
            }
        }

        $this->validateMemoryNeeded($image, $dst_w, $dst_h);

        $parameters['meta']['width'] = $dst_w;
        $parameters['meta']['height'] = $dst_h;

        $filters = self::getAvailableFilters();
        foreach ($filters as $filter) {
            if ($filter->mode === $parameters['mode']) {
                $resource = $filter->run($image, $parameters);
                break;
            }
        }

        return $resource;
    }

    public function validateMemoryNeeded(\Image $image, $dst_w, $dst_h)
    {
        $mem = array();
        if ($this->hasEnoughMemory($image, $dst_w, $dst_h, $mem) === false) {
            throw new JITGenerationError(sprintf(
                'Estimated memory needed not available. %d / %d',
                $mem['need'],
                $mem['free']
            ));
        }
    }

    public function hasEnoughMemory(\Image $image, $dst_w, $dst_h, &$meminfos = array())
    {
        $factor = (float)$this->settings['memory_exhaustion_factor'];
        if (!$factor) {
            return null;
        }
        $memlim = @ini_get('memory_limit');
        if ($memlim !== false) {
            $memlim = General::convertHumanFileSizeToBytes($memlim);
            if ($memlim > 0) {
                $memfre = $memlim - memory_get_usage();
                if ($memfre >= 0) {
                    $meminfos['free'] = $memfre;
                    $meminfos['need'] = $this->getMemoryNeeded($image, $dst_w, $dst_h, $factor);
                    return $memfre >= $meminfos['need'];
                }
            }
        }
        return null;
    }

    public function getMemoryNeeded(\Image $image, $dst_w, $dst_h, $factor = 1.8)
    {
        $meta = $image->Meta();
        $destination = $dst_w * $dst_h * ((int)$meta->channels + 1);
        return (int)(($destination + 1) * $factor);
    }

    public function cacheImage(\Image $image, array &$parameters)
    {
        // If $this->caching is enabled, and a cache file doesn't already exist,
        // save the JIT image to CACHE using the Quality setting from Symphony's
        // Configuration.
        if ($this->caching) {
            $file = $this->createCacheFilename($parameters['image']);
            if (!empty($file) && !@file_exists($file)) {
                if (!$image->save($file, intval($this->settings['quality']))) {
                    throw new JITGenerationError('Error generating image, failed to create cache file.');
                }

                // Overwrite the last_modified time with our cached image mtime
                $parameters['last_modified'] = @filemtime($file);
                // Export the cache file path
                $parameters['cached_image'] = $file;

                // Try to set proper rights on the file
                $perm = Symphony::Configuration()->get('write_mode', 'file');
                @chmod($file, intval($perm, 8));
            }
        }
    }

    public function displayImage(\Image $image)
    {
        if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS' || $_SERVER['REQUEST_METHOD'] === 'HEAD') {
            return;
        }
        // Display the image in the browser using the Quality setting from Symphony's
        // Configuration. If this fails, trigger an error.
        if (!$image->display(intval($this->settings['quality']))) {
            throw new JITGenerationError('Error generating image');
        }
    }
}

class JITException extends SymphonyErrorPage
{
    public function __construct($message, $heading = 'JIT Error', $template = 'generic', array $additional = array(), $status = \Page::HTTP_STATUS_ERROR)
    {
        return parent::__construct($message, $heading, $template, $additional, $status);
    }
}

class JITImageNotFound extends JITException
{
    public function __construct($message, $heading = 'JIT Image Not Found', $template = 'generic', array $additional = array(), $status = \Page::HTTP_STATUS_NOT_FOUND)
    {
        return parent::__construct($message, $heading, $template, $additional, $status);
    }
}

class JITDomainNotAllowed extends JITException
{
    public function __construct($message, $heading = 'JIT Domain Not Allowed', $template = 'generic', array $additional = array(), $status = \Page::HTTP_STATUS_FORBIDDEN)
    {
        return parent::__construct($message, $heading, $template, $additional, $status);
    }
}

class JITGenerationError extends JITException
{
}

class JITParseParametersException extends JITException
{
}
