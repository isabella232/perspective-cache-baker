<?php
/**
 * Class to run perspective cache code baking to make it operate within the perspective network.
 *
 * @package    Perspective
 * @subpackage Cache
 * @author     Squiz Pty Ltd <products@squiz.net>
 * @copyright  2018 Squiz Pty Ltd (ABN 77 084 670 600)
 */

namespace Perspective\Gateway\Bakers;

use PHP_CodeSniffer\Autoload;
use PHP_CodeSniffer\Ruleset;
use PHP_CodeSniffer\Config;
use PHP_CodeSniffer\Util\Tokens;
use PHP_CodeSniffer\Util\Standards;
use PHP_CodeSniffer\Files\DummyFile;

include dirname(__DIR__, 3).'/squizlabs/php_codesniffer/autoload.php';

class Cache
{


    /**
     * Bakes a given file from filepath and if it changed writes the changed content to that file for caching.
     *
     * @param string $path      The path to the file.
     * @param string $namespace The namespace the file operates in.
     *
     * @return void
     */
    private static function bakeWriteCacheFile(string $path, string $namespace)
    {
        if (file_exists($path) === true) {
            $originalContent = file_get_contents($path);
            $content         = Cache::bake($originalContent, null, false, $namespace);
            if ($originalContent !== $content) {
                file_put_contents($path, $content);
            }
        }

    }//end bakeWriteCacheFile()


    /**
     * Bakes a package of ours.
     *
     * @param string $namespace The namespace the package operates in.
     * @param string $path      The path to the file.
     *
     * @return void
     */
    private static function bakeOurPackageCache(string $namespace, string $path)
    {
        self::bakeWriteCacheFile($path.'/APIRouter.php', $namespace);

        $filepaths = \Perspective\Gateway\Libs\FileSystem::listDirectory($path.'/App', ['.php']);
        foreach ($filepaths as $filepath) {
            self::bakeWriteCacheFile($filepath, $namespace);
        }

        $filepaths = \Perspective\Gateway\Libs\FileSystem::listDirectory($path.'/CustomTypes', ['.php']);
        foreach ($filepaths as $filepath) {
            self::bakeWriteCacheFile($filepath, $namespace);
        }

    }//end bakeOurPackageCache()


    /**
     * Bakes all dependencies.
     *
     * @param string $namespace The namespace the main/top level project operates in.
     * @param array  $paths     The project and dependency paths.
     *
     * @return void
     */
    public static function bakeCache(string $namespace, array $paths)
    {
        $projectPath = $paths[$namespace];
        $vendorPath  = $projectPath.'/vendor';

        foreach ($paths as $ourNamespace => $ourPath) {
            self::bakeOurPackageCache($ourNamespace, $ourPath.'/src');
        }

        $filepaths  = \Perspective\Gateway\Libs\FileSystem::listDirectory($vendorPath, ['.php']);
        foreach ($filepaths as $filepath) {
            if (strpos($filepath, $vendorPath.'/composer') === 0) {
                // Skip composer.  It won't be used at runtime.
                continue;
            }

            $bakedAlready = false;
            foreach ($paths as $ourPath) {
                if (strpos($filepath, $ourPath) === 0) {
                    // We baked this already as one of our packages.
                    $bakedAlready = true;
                    break;
                }
            }

            if ($bakedAlready === true) {
                continue;
            }

            self::bakeWriteCacheFile($filepath, $namespace);
        }

    }//end bakeCache()


    /**
     * Runs sniffs on the content for cache integration and returns the modified content.
     *
     * @param string  $content           The content to check.
     * @param string  $filepath          The filepath if you have that instead of the file contents.
     * @param boolean $stripFirstOpenPHP Set true if you want us to string the first open PHP tag so returned content
     *                                   can be used with eval.
     * @param string  $namespace         The namespace the file operates in.
     *
     * @return string
     * @throws \Exception When no content and filepath given and the file does not exist.
     * @throws \Exception When no content and filepath given and we failed to read from the file.
     */
    public static function bake(
        string $content=null,
        string $filepath=null,
        bool $stripFirstOpenPHP=false,
        string $namespace=null
    ) {
        if ($content === null) {
            if (file_exists($filepath) === false) {
                throw \Exception('File does not exist.');
            }

            $content = file_get_contents($filepath);
            if ($content === false) {
                throw \Exception('Could not read from file.');
            }
        }

        if (defined('PHP_CODESNIFFER_CBF') === false) {
            define('PHP_CODESNIFFER_CBF', true);
        }

        $verbosity = 0;
        if (defined('PHP_CODESNIFFER_VERBOSITY') === false) {
            define('PHP_CODESNIFFER_VERBOSITY', $verbosity);
        }

        $config = new Config(['--report=summary']);
        if (empty($namespace) === true) {
            throw \Exception('Expecting a namespace.');
        }

        $config->setConfigData('namespace', '\\'.$namespace, true);
        $config->setConfigData('stripFirstOpenPHP', $stripFirstOpenPHP, true);
        $config->standards = array(dirname(__DIR__).'/standards/PerspectiveCache');

        // Create this class so it is autoloaded and sets up a bunch of PHP_CodeSniffer-specific token type constants.
        $tokens = new Tokens();

        // Allow autoloading of custom files inside installed standards.
        $installedStandards = Standards::getInstalledStandardDetails();
        foreach ($installedStandards as $name => $details) {
            Autoload::addSearchPath($details['path'], $details['namespace']);
        }

        $ruleset = new Ruleset($config);
        $config->verbosity    = $verbosity;
        $config->showProgress = false;
        $config->interactive  = false;
        $config->cache        = false;
        $config->showSources  = true;

        // Create and process a single file, faking the path so the report looks nice.
        $file       = new DummyFile($content, $ruleset, $config);
        $file->path = '/tmp/file.php';

        // Process the file.
        try {
            $file->process();
        } catch (\Exception $e) {
            $error = 'An error occurred during processing; checking has been aborted. The error message was: '.$e->getMessage();
            $file->addErrorOnLine($error, 1, 'Internal.Exception');
        }//end try

        if ($stripFirstOpenPHP === true) {
            $firstOpen = $file->findNext([T_OPEN_TAG], 0);
            $fix       = $file->addFixableError('Fake error to remove first open PHP', $firstOpen, 'Found', ['<?php']);
            $file->fixer->replaceToken($firstOpen, '');
        }

        $errors = $file->getFixableCount();
        if ($errors !== 0) {
            $fixed = $file->fixer->fixFile();
            if ($fixed === true) {
                $content = $file->fixer->getContents();
            }
        }

        $file->cleanUp();
        return $content;

    }//end bake()


}//end Cache
