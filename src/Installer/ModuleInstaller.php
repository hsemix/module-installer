<?php

namespace Yuga\Composer\Installer;

use RuntimeException;
use Composer\Composer;
use Composer\Script\Event;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;
use Composer\Repository\InstalledRepositoryInterface;


class ModuleInstaller extends LibraryInstaller
{
    /**
     * A flag to check usage - once
     *
     * @var bool
     */
    protected static $checkUsage = true;
    protected $path = 'modulez';


    /**
     * Check usage upon construction
     *
     * @param IOInterface $io composer object
     * @param Composer    $composer composer object
     * @param string      $type what are we loading
     * @param Filesystem  $filesystem composer object
     */
    public function __construct(IOInterface $io, Composer $composer, $type = 'library', Filesystem $filesystem = null)
    {
        parent::__construct($io, $composer, $type, $filesystem);

        $this->checkUsage($composer);
    }

    /**
     * Check that the root composer.json file use the post-autoload-dump hook
     *
     * If not, warn the user they need to update their application's composer file.
     * Do nothing if the main project is not a project (if it's a plugin in development).
     *
     * @param Composer $composer object
     * @return void
     */
    public function checkUsage(Composer $composer)
    {
        if (static::$checkUsage === false) {
            return;
        }

        static::$checkUsage = false;

        $package = $composer->getPackage();

        if (! $package || ($package->getType() !== 'project')) {
            return;
        }

        $scripts = $composer->getPackage()->getScripts();

        $postAutoloadDump = 'Yuga\Composer\Installer\PackageInstaller::postAutoloadDump';

        if (!isset($scripts['post-autoload-dump']) || ! in_array($postAutoloadDump, $scripts['post-autoload-dump'])) {
            $this->warnUser(
                'Action required!',
                'Please update your application composer.json file to add the post-autoload-dump hook.'
            );
        }
    }

    /**
     * Warn the developer of action they need to take
     *
     * @param string $title Warning title
     * @param string $text warning text
     *
     * @return void
     */
    public function warnUser($title, $text)
    {
        $wrap = function ($text, $width = 75) {
            return '<error>     ' .str_pad($text, $width) .'</error>';
        };

        $messages = array(
            '',
            '',
            $wrap(''),
            $wrap($title),
            $wrap(''),
        );

        $lines = explode("\n", wordwrap($text, 68));

        foreach ($lines as $line) {
            $messages[] = $wrap($line);
        }

        $messages = array_merge($messages, array($wrap(''), '', ''));

        $this->io->write($messages);
    }

    /**
     * Called whenever composer (re)generates the autoloader
     *
     * Recreates Yuga's plugin path map, based on composer information and available app-modules.
     *
     * @param Event $event the composer event object
     * @return void
     */
    public static function postAutoloadDump(Event $event)
    {
        $composer = $event->getComposer();

        $config = $composer->getConfig();

        $vendorDir = realpath($config->get('vendor-dir'));

        //
        $packages = $composer->getRepositoryManager()->getLocalRepository()->getPackages();

        //
        $packagesDir = dirname($vendorDir) .DIRECTORY_SEPARATOR .'modules';

        $packages = static::determinePlugins($packages, $packagesDir, $vendorDir);

        //
        $configFile = static::getConfigFile($vendorDir);

        static::writeConfigFile($configFile, $packages);
    }

    /**
     * Find all plugins available
     *
     * Add all composer packages of type yuga-module, and all plugins located
     * in the plugins directory to a plugin-name indexed array of paths
     *
     * @param array $packages an array of \Composer\Package\PackageInterface objects
     * @param string $pluginsDir the path to the plugins dir
     * @param string $vendorDir the path to the vendor dir
     * @return array plugin-name indexed paths to plugins
     */
    public static function determinePlugins($packages, $packagesDir = 'modules', $vendorDir = 'vendor')
    {
        $results = [];

        foreach ($packages as $package) {
            if ($package->getType() !== 'yuga-module') {
                continue;
            }

            $namespace = static::primaryNamespace($package);

            $path = $vendorDir . DIRECTORY_SEPARATOR . $package->getPrettyName();

            $results[$namespace] = $path;
        }

        if (is_dir($packagesDir)) {
            $iterator = new \DirectoryIterator($packagesDir);

            foreach ($iterator as $info) {
                if (! $info->isDir() || $info->isDot()) {
                    continue;
                }

                $path = $packagesDir . DIRECTORY_SEPARATOR . $info->getFilename();

                // Gather the information from the plugin's composer.json
                $composerJson = $path . DIRECTORY_SEPARATOR . 'composer.json';

                if (! is_readable($composerJson)) {
                    continue;
                }

                $config = json_decode(file_get_contents($composerJson), true);

                if (is_array($config) && ($config['type'] === 'yuga-module')) {
                    $namespace = static::primaryNamespace($config);

                    $results[$namespace] = $path;
                }
            }
        }

        ksort($results);

        return $results;
    }

    /**
     * Rewrite the config file with a complete list of plugins
     *
     * @param string $configFile the path to the config file
     * @param array $packages of packages
     * @return void
     */
    public static function writeConfigFile($configFile, $packages)
    {
        $root = dirname(dirname($configFile));

        $data = array();

        foreach ($packages as $name => $packagePath) {
            $packagePath = str_replace(
                DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR,
                $packagePath
            );

            // Normalize to *nix paths.
            $packagePath = str_replace('\\', '/', $packagePath);

            $packagePath .= '/';

            // Namespaced plugins should use /
            $name = str_replace('\\', '/', $name);

            $data[] = sprintf("        '%s' => '%s'", $name, $packagePath);
        }

        $data = implode(",\n", $data);

        if (! empty($data)) {
            $contents = <<<PHP
<?php
\$baseDir = dirname(dirname(__FILE__));
return array(
    'modules' => array(
$data,
    ),
);
PHP;
        } else {
            $contents = <<<'PHP'
<?php
$baseDir = dirname(dirname(__FILE__));
return array(
    'modules' => array(),
);
PHP;
        }

        $root = str_replace(
            DIRECTORY_SEPARATOR . DIRECTORY_SEPARATOR,
            DIRECTORY_SEPARATOR,
            $root
        );

        // Normalize to *nix paths.
        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' .$root, '$baseDir .\'', $contents);

        file_put_contents($configFile, $contents);
    }

    /**
     * Path to the plugin config file
     *
     * @param string $vendorDir path to composer-vendor dir
     * @return string absolute file path
     */
    protected static function getConfigFile($vendorDir)
    {
        return $vendorDir . DIRECTORY_SEPARATOR . 'yuga-modules.php';
    }

    /**
     * Get the primary namespace for a plugin package.
     *
     * @param \Composer\Package\PackageInterface $package composer object
     * @return string The package's primary namespace.
     * @throws \RuntimeException When the package's primary namespace cannot be determined.
     */
    public static function primaryNamespace($package)
    {
        $namespace = null;

        $autoLoad = ! is_array($package)
            ? $package->getAutoload()
            : (isset($package['autoload']) ? $package['autoload'] : array());

        foreach ($autoLoad as $type => $pathMap) {
            if ($type !== 'psr-4') {
                continue;
            }

            $count = count($pathMap);

            if ($count === 1) {
                $namespace = key($pathMap);

                break;
            }

            $matches = preg_grep('#^(\./)?src/?$#', $pathMap);

            if ($matches) {
                $namespace = key($matches);

                break;
            }

            foreach (array('', '.') as $path) {
                $key = array_search($path, $pathMap, true);

                if ($key !== false) {
                    $namespace = $key;
                }
            }

            break;
        }

        if (is_null($namespace)) {
            throw new RuntimeException(
                sprintf(
                    "Unable to get primary namespace for module %s." .
                    "\nEnsure you have added proper 'autoload' section to your Module's config" .
                    " as stated in README on https://github.com/hsemix/packages-installer",
                    ! is_array($package) ? $package->getName() : $package['name']
                )
            );
        }

        return trim($namespace, '\\');
    }

    /**
     * Decides if the installer supports the given type.
     *
     * This installer only supports package of type 'nova-plugin'.
     *
     * @return bool
     */
    public function supports($packageType)
    {
        return ('yuga-module' === $packageType);
    }

    /**
     * Installs specific plugin.
     *
     * After the plugin is installed, app's `yuga-modules.php` config file is updated with
     * plugin namespace to path mapping.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function install(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::install($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $version = $package->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Updates specific plugin.
     *
     * After the plugin is installed, app's `nova-packages.php` config file is updated with
     * plugin namespace to path mapping.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $initial Already installed package version.
     * @param \Composer\Package\PackageInterface $target Updated version.
     * @deprecated superceeded by the post-autoload-dump hook
     *
     * @throws \InvalidArgumentException if $initial package is not installed
     */
    public function update(InstalledRepositoryInterface $repo, PackageInterface $initial, PackageInterface $target)
    {
        parent::update($repo, $initial, $target);

        $namespace = static::primaryNamespace($initial);

        $this->updateConfig($namespace, null);

        $path = $this->getInstallPath($target);

        $namespace = static::primaryNamespace($target);

        $version = $target->getVersion();

        $this->updateConfig($namespace, $path, $version);
    }

    /**
     * Uninstalls specific package.
     *
     * @param \Composer\Repository\InstalledRepositoryInterface $repo Repository in which to check.
     * @param \Composer\Package\PackageInterface $package Package instance.
     * @deprecated superceeded by the post-autoload-dump hook
     */
    public function uninstall(InstalledRepositoryInterface $repo, PackageInterface $package)
    {
        parent::uninstall($repo, $package);

        $path = $this->getInstallPath($package);

        $namespace = static::primaryNamespace($package);

        $this->updateConfig($namespace, null);
    }

    /**
     * Update the plugin path for a given package.
     *
     * @param string $name The plugin name being installed.
     * @param string $path The path, the plugin is being installed into.
     */
    public function updateConfig($name, $path)
    {
        $name = str_replace('\\', '/', $name);

        $configFile = static::getConfigFile($this->vendorDir);

        $this->ensureConfigFile($configFile);

        //
        $return = include $configFile;

        if (is_array($return) && empty($config)) {
            $config = $return;
        }

        if (!isset($config)) {
            $this->io->write(
                'ERROR - `vendor/yuga-modules.php` file is invalid. modules path configuration not updated.'
            );

            return;
        }

        if (!isset($config['packages'])) {
            $config['packages'] = array();
        }

        if (is_null($path)) {
            unset($config['packages'][$name]);
        } else {
            $config['packages'][$name] = $path;
        }

        $this->writeConfig($configFile, $config);
    }

    /**
     * Ensure that the vendor/yuga-modules.php file exists.
     *
     * @param string $path the config file path.
     * @return void
     */
    protected function ensureConfigFile($path)
    {
        if (file_exists($path)) {
            if ($this->io->isVerbose()) {
                $this->io->write('vendor/yuga-modules.php exists.');
            }

            return;
        }

        $contents = <<<'PHP'
<?php
$baseDir = dirname(dirname(__FILE__));
return array(
    'modules' => array(),
);
PHP;
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path));
        }

        file_put_contents($path, $contents);

        if ($this->io->isVerbose()) {
            $this->io->write('Created vendor/yuga-modules.php');
        }
    }

    /**
     * Dump the generate configuration out to a file.
     *
     * @param string $path The path to write.
     * @param array $config The config data to write.
     * @param string|null $root The root directory. Defaults to a value generated from $configFile
     * @return void
     */
    protected function writeConfig($path, $config, $root = null)
    {
        $root = $root ?: dirname($this->vendorDir);

        $data = '';

        foreach ($config['packages'] as $name => $packagePath) {
            // $packagePath = $properties['path'];

            //
            $data .= sprintf("        '%s' => '%s'", $name, str_replace('vendor', 'modules', $packagePath));
        }

        if (! empty($data)) {
            $contents = <<<PHP
<?php
\$baseDir = dirname(dirname(__FILE__));
return array(
    'modules' => array(
$data
    )
);
PHP;
        } else {
            $contents = <<<'PHP'
<?php
$baseDir = dirname(dirname(__FILE__));
return array(
    'modules' => array(),
);
PHP;
        }

        $root = str_replace('\\', '/', $root);

        $contents = str_replace('\'' .$root, '$baseDir .\'', $contents);

        file_put_contents($path, $contents);
    }
}