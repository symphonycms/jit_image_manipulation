<?php

namespace JIT;

use Symphony;
use SymphonyErrorPage;
use Page;

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
     * This function returns an instance of the Frontend
     * class. It is the only way to create a new Frontend, as
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

        // is this thing cached?
        if ($image = $this->isImageAlreadyCached($param)) {
            $param['cache'] = 'HIT';
            $param['type'] = $image->Meta()->type;
            // prepare caching headers
            $this->sendImageHeaders($param);
            return $this->displayImage($image);
        }

        $param['cache'] = !$this->caching ? 'DISABLED' : 'MISS';

        // get the actual image
        $image = $this->fetchImagePath($param);

        if ($image) {
            // fetch image
            $image_resource = $this->fetchImage($image, $param);

            // apply the filter
            $image = $this->applyFilterToImage($image_resource, $param);

            // send all headers
            $param['type'] = $image->Meta()->type;
            $this->sendImageHeaders($param);

            // figure out whether to cache the image or not
            if ($this->caching) {
                $this->cacheImage($image, $param);
            }

            // display the image
            return $this->displayImage($image);
        }
        return false;
    }

    /**
     * Given the parameters, check to see if this image is already in
     * the cache.
     *
     * @param array $parameters
     * @return boolean|array
     */
    public function isImageAlreadyCached(array &$parameters)
    {
        if (!$this->caching) {
            return false;
        }
        $file = $this->createCacheFilename($parameters['image']);
        if (@file_exists($file)) {
            $parameters['last_modified'] = @filemtime(WORKSPACE . '/'. $parameters['image']);
            $parameters['cached_image'] = $file;
            return @\Image::load($file);
        }
        return false;
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
                elseif (!preg_match('/^' . $recipe['url-parameter'] . '\//i', $parameter_string, $matches)) {
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
                    $image_settings['quality'] = $recipe['quality'];
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
            throw new JITParseParametersException('No JIT filter was found for this request.');
        }
        // Does the image tries to do parent folder escalation?
        if (preg_match('/[\.]{2}\//', $image_path)) {
            throw new JITParseParametersException('Invalid image path.');
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
            $image_path = "http://{$parameters['image']}";

            // Image is external, check to see that it is a trusted source
            $rules = file(WORKSPACE . '/jit-image-manipulation/trusted-sites', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            $allowed = false;

            $rules = array_map('trim', $rules);

            if (count($rules) > 0) {
                foreach ($rules as $rule) {
                    $rule = str_replace(array('http://', 'https://'), null, $rule);

                    // Wildcard
                    if ($rule == '*') {
                        $allowed = true;
                        break;
                    } // Wildcard after domain
                    elseif (substr($rule, -1) == '*' && strncasecmp($parameters['image'], $rule, strlen($rule) - 1) == 0) {
                        $allowed = true;
                        break;
                    } // Match the start of the rule with file path
                    elseif (strncasecmp($rule, $parameters['image'], strlen($rule)) === 0) {
                        $allowed = true;
                        break;
                    } // Match subdomain wildcards
                    elseif (substr($rule, 0, 1) == '*' && preg_match("/(".substr((substr($rule, -1) == '*' ? rtrim($rule, "/*") : $rule), 2).")/", $param->file)) {
                        $allowed = true;
                        break;
                    }
                }
            }

            if ($allowed == false) {
                \Page::renderStatusCode(Page::HTTP_STATUS_FORBIDDEN);
                exit(sprintf('Error: Connecting to %s is not permitted.', $parameters['image']));
            }

            $parameters['last_modified'] = strtotime(\Image::getHttpHeaderFieldValue($image_path, 'Last-Modified'));

        // If the image is not external check to see when the image was last modified
        } else {
            $image_path = WORKSPACE . '/' . $parameters['image'];
            $parameters['last_modified'] = is_file($image_path) ? filemtime($image_path) : null;
        }

        // If $this->caching is enabled, check to see that the cached file is still valid.
        if ($this->caching === true) {
            $cache_file = $this->createCacheFilename($parameters['image']);
            // Set the cached image path, worst case scenario an image will be saved here
            // if no valid cache exists.
            $parameters['cached_image'] = $cache_file;

            // Cache has expired or doesn't exist
            if (is_file($cache_file) && (filemtime($cache_file) < $parameters['last_modified'])) {
                unlink($cache_file);
            } elseif (is_file($cache_file)) {
                touch($cache_file);
            }
        }

        return $image_path;
    }

    public function sendImageHeaders($parameters)
    {
        // Send debug headers
        if (static::isLoggedIn()) {
            header('X-jit-mode: ' . $parameters['mode']);
            header('X-jit-cache: ' . $parameters['cache']);
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
            $etag = md5($parameters['last_modified'] . $image_path);
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

    public function fetchImage($image_path, $parameters)
    {
        // There is mode, or the image to JIT is external, so call `Image::load` or
        // `Image::loadExternal` to load the image into the Image class
        try {
            $method = 'load' . ($parameters['settings']['external'] === true ? 'External' : null);
            $image = call_user_func_array(array('Image', $method), array($image_path));

            if (!$image instanceof \Image) {
                throw new JITGenerationError('Could not load image');
            }
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

    public function cacheImage(\Image $image, $parameters)
    {
        // If $this->caching is enabled, and a cache file doesn't already exist,
        // save the JIT image to CACHE using the Quality setting from Symphony's
        // Configuration.
        if (!file_exists($parameters['cached_image'])) {
            if (!$image->save($parameters['cached_image'], intval($this->settings['quality']))) {
                throw new JITGenerationError('Error generating image, failed to create cache file.');
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

class JITGenerationError extends JITException
{
}

class JITParseParametersException extends JITException
{
}
