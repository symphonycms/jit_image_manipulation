<?php

namespace JIT;

use Symphony;
use SymphonyErrorPage;

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

    public function display($page = null)
    {
        $this->settings = Symphony::Configuration()->get('image');
        $this->caching = ($this->settings['cache'] == 1 ? true : false);

        // is this thing cached?
        if ($image = $this->isImageAlreadyCached($_GET['param'])) {
            return $this->displayImage($image);
        }

        // process the parameters
        $param = $this->parseParameters($_GET['param']);

        // get the actual image
        $image = $this->fetchImagePath($param);

        if ($image) {
            // prepare caching headers, potentially 304.
            //$this->sendImageHeaders($param);

            $image_resource = $this->fetchImage($image, $param);

            // apply the filter
            $image = $this->applyFilterToImage($image_resource, $param);
 
            // figure out whether to cache the image or not
            if ($this->caching) {
                $this->cacheImage($image, $param);
            }

            // display the image
            return $this->displayImage($image);
        }
    }

    /**
     * Given the parameters, check to see if this image is already in
     * the cache.
     *
     * @param string $parameter_string
     * @return boolean
     */
    public function isImageAlreadyCached($parameter_string)
    {
        return false;
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

        $filters = self::getAvailableFilters();
        foreach ($filters as $filter) {
            if ($params = $filter->parseParameters($parameter_string)) {
                extract($params);
                break;
            }
        }

        // Did the delegate resolve anything?
        if (($mode && $image_path) === false) {
            throw new JITParseParametersException('No JIT filter was found for this request.');
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
            'image' => $image_path
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
        if ($parameters['external'] === true) {
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
                    elseif (strncasecmp($rule, $parameters['image'], strlen($rule)) == 0) {
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
            $image_path = WORKSPACE . "/" . $parameters['image'];
            $parameters['last_modified'] = is_file($image_path) ? filemtime($image_path) : null;
        }

        // If $this->caching is enabled, check to see that the cached file is still valid.
        if ($this->caching === true) {
            $cache_file = sprintf('%s/%s_%s', CACHE, md5($_GET['param'] . intval($this->settings['quality'])), basename($image_path));
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
        // if there is no `$last_modified` value, params should be NULL and headers
        // should not be set. Otherwise, set caching headers for the browser.
        if ($parameters['last_modified']) {
            $last_modified_gmt = gmdate('D, d M Y H:i:s', $parameters['last_modified']) . ' GMT';
            $etag = md5($parameters['last_modified'] . $image_path);
            $cacheControl = 'public';

            // Add no-transform in order to prevent ISPs to
            // serve image over http through a compressing proxy
            // See #79
            if ($this->settings['disable_proxy_transform'] == 'yes') {
                $cacheControl .= ', no-transform';
            }

            header('Last-Modified: ' . $last_modified_gmt);
            header(sprintf('ETag: "%s"', $etag));
            header('Cache-Control: '. $cacheControl);
        } else {
            $last_modified_gmt = null;
            $etag = null;
        }

        // Check to see if the requested image needs to be generated or if a 304
        // can just be returned to the browser to use it's cached version.
        if ($this->caching === true && (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) || isset($_SERVER['HTTP_IF_NONE_MATCH']))) {
            if ($_SERVER['HTTP_IF_MODIFIED_SINCE'] == $last_modified_gmt || str_replace('"', null, stripslashes($_SERVER['HTTP_IF_NONE_MATCH'])) == $etag) {
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
            $method = 'load' . ($parameters['external'] === true ? 'External' : null);
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

        if ($this->settings['disable_upscaling'] == 'yes') {
            $parameters['meta']['width'] = min($parameters['settings']['width'], $src_w);
            $parameters['meta']['height'] = min($parameters['settings']['height'], $src_h);
        } else {
            $parameters['meta']['width'] = $parameters['settings']['width'];
            $parameters['meta']['height'] = $parameters['settings']['height'];
        }

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
        if (!is_file($parameters['cached_image'])) {
            if (!$image->save($parameters['cached_image'], intval($this->settings['quality']))) {
                throw new JITGenerationError('Error generating image, failed to create cache file.');
            }
        }
    }

    public function displayImage($image)
    {
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
