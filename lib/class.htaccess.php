<?php

/**
 * Provides a list of HTAccess transformations for JIT Image Manipulation
 * install, update, and uninstall.
 *
 * @author Kristjan Siimson <dev@siimsoni.ee>
 */
class HTAccess
{
    /**
     * @var string
     */
    private $content;

    /**
     * @var bool
     */
    private $exists;

    /**
     * @var string
     */
    private $path;

    public function __construct()
    {
        $this->path = DOCROOT . '/.htaccess';
        $this->exists = file_exists($this->path);
    }

    /**
     * @return bool
     */
    public function exists()
    {
        return $this->exists;
    }

    public function enableExtension()
    {
        $this->read();
        $this->removeImageRules();

        // Cannot use $1 in a preg_replace replacement string, so using a token instead
        $token = md5(time());

        $rule = "
    ### IMAGE RULES
    RewriteRule ^image\/(.+)$ extensions/jit_image_manipulation/lib/image.php?param={$token} [B,L,NC]" . PHP_EOL . PHP_EOL;

        if (preg_match('/### IMAGE RULES/', $this->content)) {
            $this->content = preg_replace(
                '/### IMAGE RULES/',
                $rule,
                $this->content
            );
        } else {
            $this->content = preg_replace(
                '/RewriteRule .\* - \[S=14\]\s*/i',
                'RewriteRule .* - [S=14]' . PHP_EOL . "{$rule}\t",
                $this->content
            );
        }

        // Replace the token with the real value
        $this->content = str_replace($token, '$1', $this->content);
        $this->content = preg_replace(
            '/(' . PHP_EOL . "(\t)?){3,}/",
            PHP_EOL . PHP_EOL . "\t",
            $this->content
        );

        $this->write();
    }

    public function disableExtension()
    {
        $this->read();
        $this->removeImageRules();
        $this->content = preg_replace(
            '/### IMAGE RULES/',
            null,
            $this->content
        );
        $this->content = preg_replace(
            '/(' . PHP_EOL . "(\t)?){3,}/",
            PHP_EOL . PHP_EOL . "\t",
            $this->content
        );
        $this->write();
    }

    /**
     * Update from < 1.21
     */
    public function simplifyJITAccessRule()
    {
        $this->read();
        $this->content = str_replace(
            'RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))$',
            'RewriteRule ^image\/(.+)$',
            $this->content
        );
        $this->write();
    }

    /**
     * Update from < 1.17
     *
     * @throws Exception
     */
    public function addBFlagToRule()
    {
        $this->read();
        $this->content = str_replace(
            'extensions/jit_image_manipulation/lib/image.php?param={$token} [L,NC]',
            'extensions/jit_image_manipulation/lib/image.php?param={$token} [B,L,NC]',
            $this->content
        );
        $this->write();
    }

    private function removeImageRules()
    {
        $this->content = preg_replace(
            '/RewriteRule \^image[^\r\n]+[\r\n\t]?/i',
            null,
            $this->content
        );
    }

    /**
     * Populates $this->content from .htaccess
     *
     * @throws Exception
     */
    private function read()
    {
        try {
            $this->content = file_get_contents($this->path);
        } catch(Exception $ex) {
            $message = "Permission denied to '%s'";
            throw new Exception(sprintf($message, $this->path));
        }
    }

    /**
     * Flushes $this->content to .htaccess
     *
     * @throws Exception
     */
    private function write()
    {
        try {
            file_put_contents($this->path, $this->content);
        } catch(Exception $ex) {
            $message = sprintf("Permission denied to '%s'", $this->path);
            throw new Exception($message);
        }
    }
}