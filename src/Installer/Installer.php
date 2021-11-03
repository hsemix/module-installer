<?php

/**
 * Yuga composer installer
 *
 * @copyright Yuga Framework Development Team
 * @link https://github.com/hsemix/module-installer
 */

/**
 * @namespace
 */
namespace Yuga\Composer\Installer;

use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Installer\LibraryInstaller;

/**
 * Class Installer
 *
 * @package Yuga\Composer\Installer
 */
class Installer extends LibraryInstaller
{
    protected $vendorPath;

    /**
     * Get path to the installation package
     *
     * {@inheritDoc}
     */
    public function getInstallPath(PackageInterface $package): string
    {
        $this->vendorPath = parent::getInstallPath($package);
        return $this->vendorPath;
    }

    /**
     * Check type of the plugin
     *
     * {@inheritDoc}
     */
    public function supports($packageType): bool
    {
        return $packageType === 'yuga-module';
    }

    /**
     * Get inputOutput instance
     */
    public function getIo(): IOInterface
    {
        return $this->io;
    }

    /**
     * Get path to current vendor
     */
    public function getVendorPath()
    {
        return $this->vendorPath;
    }
}