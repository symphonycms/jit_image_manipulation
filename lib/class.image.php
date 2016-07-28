<?php

class Image
{
    private $_resource;
    private $_meta;
    private $_truepath;
    private $_temppath;

    const DEFAULT_QUALITY = 80;
    const DEFAULT_INTERLACE = true;
    const CURL_MAXREDIRS = 6;

    protected function __construct($resource, stdClass $meta, $truepath)
    {
        $this->_resource = $resource;
        $this->_meta = $meta;
        $this->_truepath = $truepath;
    }

    public function __destruct()
    {
        if (is_resource($this->_resource)) {
            @imagedestroy($this->_resource);
        }
        if (@is_file($this->_temppath)) {
            General::deleteFile($this->_temppath);
        }
    }

    public function setResource($resource)
    {
        $this->_resource = $resource;
    }

    public function Resource()
    {
        return $this->_resource;
    }

    public function Meta()
    {
        return $this->_meta;
    }

    public function TruePath()
    {
        return $this->_truepath;
    }

    public function ModifiedTime()
    {
        if ($this->_truepath == null) {
            return 0;
        }
        return @filemtime($this->_truepath);
    }

    /**
     * Sends a HEAD request and returns headers dealing with caching
     * and invalidating this url
     *
     * @param string $url
     * @return array
     *  Contains the cache related http headers
     */
    public static function fetchHttpCachingInfos($url)
    {
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
        $info = $gateway->getInfoLast();
        // Clean up
        $gateway->flush();

        if ($head === false || (int)$info['http_code'] !== 200) {
            throw new JIT\JITException(sprintf('Error reading external image <code>%s</code>. Please check the URI.', $uri));
        }

        $headers = self::parseHttpHeaderFields($head);

        return array(
            'last_modified' => isset($headers['Last-Modified']) ? $headers['Last-Modified'] : null,
            'expires' => isset($headers['Expires']) ? $headers['Expires'] : null,
            'date' => isset($headers['Date']) ? $headers['Date'] : null,
            'etag' => isset($headers['ETag']) ? $headers['ETag'] : null,
            'cache_control' => isset($headers['Cache-Control']) ? $headers['Cache-Control'] : null,
        );
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
    public static function loadExternal($uri)
    {
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
        $info = $gateway->getInfoLast();

        if ($response === false || (int)$info['http_code'] !== 200) {
            throw new JIT\JITException(sprintf('Error reading external image <code>%s</code>. Please check the URI.', $uri));
        }

        // clean up
        $gateway->flush();

        // Symphony 2.4 enhances the TMP constant so it can be relied upon
        $temppath = tempnam(TMP, 'IMAGE');

        if (!@file_put_contents($temppath, $response)) {
            General::deleteFile($temppath);
            throw new JIT\JITException(sprintf('Error writing to temporary file <code>%s</code>.', $dest));
        }

        // Load the image as a local resource
        $image = static::load($temppath);

        // This will insure that the temp file gets deleted later on
        $image->_temppath = $temppath;

        return $image;
    }

    /**
     * Given a path to an image, `$image`, this function will verify it's
     * existence, and generate a resource for use with PHP's image functions
     * based off the file's type (.gif, .jpg, .png).
     * If you are running a GD version less than 2.0.22
     * images must be RGB, CMYK jpg's are not supported due to GD limitations.
     *
     * @param string $image
     *  The path to the file
     * @return Image
     */
    public static function load($image)
    {
        if (!is_file($image) || !is_readable($image)) {
            throw new JIT\JITImageNotFound(
                sprintf('Error loading image <code>%s</code>. Check it exists and is readable.', \General::sanitize(str_replace(DOCROOT, '', $image)))
            );
        }

        $meta = self::getMetaInformation($image);

        switch ($meta->type) {
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
                    throw new JIT\JITException('Cannot load CMYK JPG images');

                    // Can handle CMYK, or image has less than 3 channels.
                } else {
                    $resource = imagecreatefromjpeg($image);
                }
                break;

            // PNG
            case IMAGETYPE_PNG:
                $resource = imagecreatefrompng($image);
                break;

            default:
                throw new JIT\JITException('Unsupported image type. Supported types: GIF, JPEG and PNG');
                break;
        }

        if (!is_resource($resource)) {
            throw new JIT\JITGenerationError(
                sprintf('Error creating image <code>%s</code>. Check it exists and is readable.', General::sanitize(str_replace(DOCROOT, '', $image)))
            );
        }

        return new self($resource, $meta, $image);
    }

    /**
     * Given a path to a file, this function will attempt to find out the
     * dimensions, type and channel information using `getimagesize`.
     *
     * @link http://www.php.net/manual/en/function.getimagesize.php
     * @param string $file
     *  The path to the image.
     */
    public static function getMetaInformation($file)
    {
        if (!$array = getimagesize($file)) {
            throw new JIT\JITException('Unable to retrieve image size information for ' . $file);
        }

        $meta = array();

        $meta['width'] = $array[0];
        $meta['height'] = $array[1];
        $meta['type'] = $array[2];
        $meta['channels'] = isset($array['channels']) ? $array['channels'] : false;

        return (object)$meta;
    }

    /**
     * Parse HTTP response headers
     *
     * @param string $header - response header
     * @return array - header fields; name => value
     */
    public static function parseHttpHeaderFields($header)
    {
        $retVal = array();
        $fields = explode("\r\n", preg_replace('/\x0D\x0A[\x09\x20]+/', ' ', $header));
        foreach ($fields as $field) {
            if (preg_match('/([^:]+): (.+)/m', $field, $match)) {
                $match[1] = preg_replace_callback('/(?<=^|[\x09\x20\x2D])./', function ($matches) {
                    return strtoupper($matches[0]);
                }, strtolower(trim($match[1])));
                if (isset($retVal[$match[1]])) {
                    $retVal[$match[1]] = array($retVal[$match[1]], $match[2]);
                } else {
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
    public static function height($res)
    {
        return imagesy($res);
    }

    /**
     * Accessor to return of the width of the given `$res`
     *
     * @param resource $res
     * @return integer
     *  The width of the image in pixels, false otherwise
     */
    public static function width($res)
    {
        return imagesx($res);
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
    public function display($quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null)
    {
        if (!$output) {
            $output = $this->Meta()->type; //DEFAULT_OUTPUT_TYPE;
        }
        return self::__render(null, $quality, $interlacing, $output);
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
    public function save($dest, $quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null)
    {
        if (!$output) {
            $output = $this->Meta()->type; //DEFAULT_OUTPUT_TYPE;
        }
        return self::__render($dest, $quality, $interlacing, $output);
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
    protected function __render($dest, $quality = Image::DEFAULT_QUALITY, $interlacing = Image::DEFAULT_INTERLACE, $output = null)
    {
        if (!is_resource($this->_resource)) {
            throw new JIT\JITException('Invalid image resource supplied');
        }

        // Turn interlacing on for JPEG or PNG only
        if ($interlacing && ($output === IMAGETYPE_JPEG || $output === IMAGETYPE_PNG)) {
            imageinterlace($this->_resource);
        }

        switch ($output) {
            case IMAGETYPE_GIF:
                return imagegif($this->_resource, $dest);
                break;

            case IMAGETYPE_PNG:
                return imagepng($this->_resource, $dest, round(9 * ($quality * 0.01)));
                break;

            case IMAGETYPE_JPEG:
            case null:
                return imagejpeg($this->_resource, $dest, $quality);
                break;
        }

        throw new JIT\JITException('Invalid image resource output supplied: '. $output);
    }
}
