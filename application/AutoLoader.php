<?php

/**
 * AutoLoader singleton class.
 * Thanks to Linus Norton <linusnorton@gmail.com> http://code.google.com/p/php-xframe/
 * @todo Add support for PHP5.3 namespaces
 * @todo Add more caching support
 * @author jason
 */
class AutoLoader {

    protected $rootDirectory;
    protected $classMap = array();
    protected $reloadClassMap;
    protected $fileExt = array(".php" => true, ".php4" => true, ".php5" => true, ".mphp" => true, ".phpm" => true);
    protected static $instance;
    protected $cacheFile = ".classmapcache.php";
    protected $ignore = array();
    protected $rebuiltInThisExecution = false;

    /**
     *
     * @param string $rootDirectory
     * @param boolean $reloadClassMap
     * @param array $fileExt
     */
    protected function __construct($rootDirectory = null, $reloadClassMap = true, $fileExt = null) {
        $this->rootDirectory = $rootDirectory != null ? $rootDirectory : dirname(__FILE__);
        $this->reloadClassMap = $reloadClassMap;
        $this->fileExt = $fileExt != null && is_array($fileExt) ? $fileExt : $this->fileExt;

        spl_autoload_register(__CLASS__ . "::autoload");
    }
    
    /**
     * initialises the class map
     */
    public function init() {
        try {
            $this->loadFromCache();
        } catch (Exception $ex) {
            $this->rebuild();
        }
    }

    /**
     * Adds a path to the ignore list
     * @param string $path
     * @return AutoLoader
     */
    public function ignore($path) {
        $this->ignore[$path] = true;
        return $this;
    }

    /**
     * Returns the instance of the AutoLoader Singleton or instantiates a new one
     * @return AutoLoader
     */
    public static function instance($rootDirectory = null, $reloadClassMap = true, $fileExt = null) {
        if (self::$instance == null) {
            self::$instance = new AutoLoader($rootDirectory, $reloadClassMap, $fileExt);
        }
        return self::$instance;
    }

    /**
     * rebuilds the class map
     */
    public function rebuild() {
        // rebuild if we are allowed and we haven't done so already during this execution
        if ($this->reloadClassMap && !$this->rebuiltInThisExecution) { 
            $this->classMap = array();
            $this->mapFilesInDir($this->rootDirectory);
            $this->saveToCache();
            $this->rebuiltInThisExecution = true;
        } else {
            throw new Exception("Unable to rebuild class map");
        }
    }

    /**
     * @return string
     */
    public function getCacheLocation() {
        return $this->rootDirectory. DIRECTORY_SEPARATOR . $this->cacheFile;
    }

    /**
     *
     * @param array $classMap
     */
    public function setClassMap(array $classMap) {
        $this->classMap = $classMap;
    }

    /**
     * Loads the class map from the cache file
     */
    protected function loadFromCache() {
        if (file_exists($this->getCacheLocation())) {
            try {
                include $this->getCacheLocation();
                return;
            } catch (Exception $ex) {
                // fall below and thro exception
            }
        }
        throw new Exception("Unable to load cache file " . $this->getCacheLocation());
    }

    /**
     * saves the classMap array to the cacheFile
     */
    public function saveToCache() {
        $contentToWrite  = "<?php\n";
        $contentToWrite .= "/**\n";
        $contentToWrite .= " * Do not edit\n";
        $contentToWrite .= " * Generated by ".__CLASS__."\n";
        $contentToWrite .= " * Generated at ".date("Y-m-d H:i:s")."\n";
        $contentToWrite .= " */\n";
        $contentToWrite .= __CLASS__."::instance()->setClassMap(".var_export($this->classMap, true).");";
        $fp = fopen($this->getCacheLocation(), 'w');
        fwrite($fp, $contentToWrite);
        fclose($fp);
        chmod($this->getCacheLocation(), 0775);
    }

    /**
     * expires the cache
     */
    public function expireCache() {
        try {
            if (file_exists($this->getCacheLocation())) {
                unlink($this->getCacheLocation());
            }
        } catch (Exception $ex) {
            // file didn't exist so it doesn't matter.
        }
    }

    /**
     * Scan a directory for file with an accepted extension and add them to the map.
     * @param string $dir
     */
    protected function mapFilesInDir($dir) {
        $handle = opendir($dir);
        while (($file = readdir($handle)) !== false) {
            if ($file[0] == ".") {
                continue;
            }
            $ext = ".".pathinfo($dir. DIRECTORY_SEPARATOR . $file , PATHINFO_EXTENSION);
            $filepath = $dir == '.' ? $file : $dir . DIRECTORY_SEPARATOR . $file;
            
            $ignore        = isset($this->ignore[$filepath]) && $this->ignore[$filepath] == true ? true : false;
            $fileExt    = isset($this->fileExt[$ext]) && $this->fileExt[$ext] == true ? true : false;

            if (is_dir($filepath) && !$ignore) {
                $this->mapFilesInDir($filepath);
            }
            else if (is_file($filepath) && $fileExt && $filepath != $this->getCacheLocation()) {
                $this->loadClassesFromFile($filepath);
            }
        }
        closedir($handle);
    }

    /**
     * Scans a file for classes
     * @todo Add namespace support
     * @param string $filepath
     */
    protected function loadClassesFromFile($filepath) {
        $sourceCode = file_get_contents($filepath);
        $tokens = @token_get_all($sourceCode);
        
        $count = count($tokens);
        $namespace = "";
        for ($i = 2; $i < $count; $i++) {
            if ($tokens[$i - 2][0] == T_NAMESPACE && $tokens[$i - 1][0] == T_WHITESPACE && $tokens[$i][0] == T_STRING) {
                $namespace = $tokens[$i][1];
                // we've found a namespace now carry on until we hit some whitespace
                $i++;
                while ($tokens[$i][0] != T_WHITESPACE) {
                    $namespace .= $tokens[$i][1];
                    $i++;
                }
            }
            if (($tokens[$i - 2][0] == T_CLASS || $tokens[$i - 2][0] == T_INTERFACE) &&
                $tokens[$i - 1][0] == T_WHITESPACE &&
                $tokens[$i][0] == T_STRING) {
                    
                $className = $tokens[$i][1];
                if ($namespace != '') {
                    $className = $namespace . '\\' . $className;
                }
                $this->classMap[strtolower($className)] = str_replace($this->rootDirectory . DIRECTORY_SEPARATOR, "", $filepath);
                $namespace = "";
            }
        }
    }

    /**
     * Attempts to include the requested class name if it doesn't already exist
     * and is in our class map
     * @todo look at PHP5.3 and namespace issues...
     * @param string $className
     * @return boolean
     */
    public function includeClass($className) {
        // this used to reassign className but one day php thought that it wouldn't strtolower MySQL_Query_i, it just left it, randomly. way to go PHP
        $lowerClassName = strtolower($className); // yup...
        if (class_exists($lowerClassName)) {
            return true;
        }
        
        if (isset($this->classMap[$lowerClassName])) {
            try {
                include $this->rootDirectory . $this->classMap[$lowerClassName];
                return true;
            }
            catch (Exception $ex) { /*drop below and return false */ }
        }

        return false;
    }

    /**
     * This function is registered with the SPL
     * @param string $className
     */
    public static function autoload($className) {
        if (self::instance()->includeClass($className) === false) {
            try {
                self::instance()->rebuild();
                self::instance()->includeClass($className);
            } catch (Exception $ex) {
                return false; // return false and allow PHP to move onto the next registered autoloader
            }
        }
        return true;
    }
}
