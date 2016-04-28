<?php

namespace Viison\Composer;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\PluginInterface;

class ComposerSymlinkInstallerPlugin implements PluginInterface {
    public function activate(Composer $composer, IOInterface $io)
    {
    	
    	
    	
    	$io->write("d");
    	print_r($composer->getConfig());
    	$io->write(print_r($composer->getConfig(), true));
    	$extra = $composer->getPackage()->getExtra();
    	
    	if (isset($extra['symlink-installer'])) {
        	$config = $extra['symlink-installer'];
        	
        	$installer = new ComposerSymlinkInstaller($io, $composer);
        	$composer->getInstallationManager()->addInstaller($installer);
        }
    }
}