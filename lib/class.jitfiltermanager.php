<?php

namespace JIT;

use Symphony;
use FileResource;
use General;
use ReflectionMethod;
use ReflectionException;

require_once FACE . '/interface.fileresource.php';
require_once __DIR__ . '/class.imagefilter.php';

class JitFilterManager implements FileResource
{
    /**
     * Given the filename of a Filter, return its handle. This will remove
     * the Symphony convention of `data.*.php`
     *
     * @param string $filename
     *  The filename of the Filter
     * @return string
     */
    public static function __getHandleFromFilename($filename)
    {
        return preg_replace(array('/^filter./i', '/.php$/i'), '', $filename);
    }

    /**
     * Given a name, returns the full class name of an Filters. Filters
     * use a 'datasource' prefix.
     *
     * @param string $handle
     *  The Filter handle
     * @return string
     */
    public static function __getClassName($handle)
    {
        return 'filter' . $handle;
    }

    /**
     * Finds a Filter by name by searching the `filters` folder in the
     * `WORKSPACE`, inside the JIT extension, and then in all installed extension
     * folders and returns the path to it's folder.
     *
     * @param string $handle
     *  The handle of the Filter free from any Symphony conventions
     *  such as `filter.*.php`
     * @return mixed
     *  If the filter is found, the function returns the path it's folder, otherwise false.
     */
    public static function __getClassPath($handle)
    {
        $path = __DIR__ . '/../filters';
        $file = "/filter.$handle.php";

        // Look in the WORKSPACE directory
        if (is_file(WORKSPACE . '/jit-image-manipulation/filters' . $file)) {
            return WORKSPACE . '/jit-image-manipulation/filters';
            
        // Look in the JIT directory
        } elseif (is_file($path . $file)) {
            return $path;
            
        // Look in the EXTENSIONS directory
        } else {
            $extensions = Symphony::ExtensionManager()->listInstalledHandles();

            if (is_array($extensions) && !empty($extensions)) {
                foreach ($extensions as $e) {
                    if (is_file(EXTENSIONS . "/$e/filters" . $file)) {
                        return EXTENSIONS . "/$e/filters";
                    }
                }
            }
        }

        return false;
    }

    /**
     * Given a name, return the path to the Filter class
     *
     * @see JitFilterManager::__getClassPath()
     * @param string $handle
     *  The handle of the Filter free from any Symphony conventions
     *  such as `filter.*.php`
     * @return string
     */
    public static function __getDriverPath($handle)
    {
        return self::__getClassPath($handle) . "/filter.$handle.php";
    }

    /**
     * Finds all available Filters by searching the `filters` folder in the
     * `WORKSPACE`, inside the JIT extension, and then in all installed extension
     * folders. Returns an associative array of filters.
     *
     * @see toolkit.Manager#about()
     * @return array
     *  Associative array of Filters with the key being the handle of the
     *  Filter and the value being the Filter's `about()` information.
     */
    public static function listAll()
    {
        $result = array();
        
        // Workspace first
        $structure = General::listStructure(WORKSPACE . '/jit-image-manipulation/filters', '/filter.[\\w-]+.php/', false, 'ASC', WORKSPACE . '/jit-image-manipulation/filters');
        if (is_array($structure['filelist']) && !empty($structure['filelist'])) {
            foreach ($structure['filelist'] as $f) {
                if ($about = self::getAboutForFilter($f)) {
                    $result[$f] = $about;
                }
            }
        }

        // Now do extensions (this will find it's own too!)
        $extensions = Symphony::ExtensionManager()->listInstalledHandles();
        if (is_array($extensions) && !empty($extensions)) {
            foreach ($extensions as $e) {
                if (!is_dir(EXTENSIONS . "/$e/filters")) {
                    continue;
                }

                $tmp = General::listStructure(EXTENSIONS . "/$e/filters", '/filter.[\\w-]+.php/', false, 'ASC', EXTENSIONS . "/$e/filters");

                if (is_array($tmp['filelist']) && !empty($tmp['filelist'])) {
                    foreach ($tmp['filelist'] as $f) {
                        if ($about = self::getAboutForFilter($f)) {
                            $result[$f] = $about;
                        }
                    }
                }
            }
        }

        ksort($result);
        return $result;
    }

    public static function about($name)
    {
        $classname = self::__getClassName($name);
        $path = self::__getDriverPath($name);

        if (!@file_exists($path)) {
            return false;
        }

        if (!class_exists($classname)) {
            require_once $path;
        }

        $handle = self::__getHandleFromFilename(basename($path));
        $class = new $classname;

        try {
            $method = new ReflectionMethod($classname, 'about');
            $about = $method->invoke($class);
        } catch (ReflectionException $e) {
            $about = array();
        }

        return array_merge($about, array('handle' => $handle));
    }

    /**
     * Given the `$filename`, this function will attempt to get metadata
     * about the filter.
     *
     * @param string $file
     * @return array|boolean
     */
    private function getAboutForFilter($file)
    {
        $f = self::__getHandleFromFilename($file);

        if ($about = self::about($f)) {
            return $about;
        } else {
            return false;
        }
    }

    /**
     * Creates an instance of a given class and returns it.
     *
     * @param string $handle
     *  The handle of the Filter to create
     * @throws Exception
     * @return Filter
     */
    public static function create($handle)
    {
        $classname = self::__getClassName($handle);
        $path = self::__getDriverPath($handle);

        if (!is_file($path)) {
            throw new Exception(
                __('Could not find JIT Filter %s.', array('<code>' . $handle . '</code>'))
                . ' ' . __('If it was provided by an Extension, ensure that it is installed, and enabled.')
            );
        }

        if (!class_exists($classname)) {
            require_once $path;
        }

        return new $classname;
    }
}
