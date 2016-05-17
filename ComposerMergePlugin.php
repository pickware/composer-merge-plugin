<?php

/**
 * This file is part of VIISON/composer-merge-plugin.
 *
 * Copyright (c) 2016 VIISON GmbH
 *
 * Permission is hereby granted, free of charge, to any person obtaining a
 * copy of this software and associated documentation files (the "Software"),
 * to deal in the Software without restriction, including without limitation
 * the rights to use, copy, modify, merge, publish, distribute, sublicense,
 * and/or sell copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER
 * DEALINGS IN THE SOFTWARE.
 *
 * @license MIT <http://opensource.org/licenses/MIT>
 */

namespace Viison\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Util\Filesystem;
use Composer\Plugin\PluginInterface;
use Composer\Script\CommandEvent;

class ComposerMergePlugin implements PluginInterface, EventSubscriberInterface {
	const MERGE_LOG_FILENAME = 'composer-merge-log.json';
	
	const TYPE_SYMLINK = 'symlink';
	const TYPE_DIRECTORY = 'directory';
	const TYPE_FILE = 'file';
	
	const MERGE_STRATEGY_SYMLINK = 'symlink';
	const MERGE_STRATEGY_COPY = 'copy';
	
	protected $composer;
	protected $io;
	protected $filesystem;
	protected $mergeLog = array();
	
	public function activate(Composer $composer, IOInterface $io) {
		$this->composer = $composer;
		$this->io = $io;
		$this->filesystem = new Filesystem();
	}
	
	public static function getSubscribedEvents() {
		return array('post-install-cmd' => 'onPostInstallCmd', 'post-update-cmd' => 'onPostUpdateCmd');
	}
	
	public function onPostInstallCmd(CommandEvent $event) {
		$this->run($event);
	}
	
	public function onPostUpdateCmd(CommandEvent $event) {
		$this->run($event);
	}
	
	protected function run(CommandEvent $event) {
		$this->unmerge();
		$this->merge($event->isDevMode());
	}
	
	protected function unmerge() {
		$json = @file_get_contents(self::MERGE_LOG_FILENAME);
		if ($json === false) return;
		
		$mergeLog = json_decode($json);
		if (is_array($mergeLog)) {
			$mergeLog = array_reverse($mergeLog);
			
			foreach ($mergeLog as $entry) {
				list ($type, $dst, $src, $md5) = $entry;
				
				if ($type === self::TYPE_SYMLINK) {
					if (is_link($dst) /* TODO Check if link target points to src */) {
						if (!$this->filesystem->unlink($dst)) {
							$this->io->writeError('<warning>Previously merged symlink ' . $dst . ' could not be removed</warning>');
						}
					} else {
						$this->io->writeError('<warning>Previously merged symlink ' . $dst . ' has been modified or removed</warning>');
					}
				} else if ($type === self::TYPE_FILE) {
					if (is_file($dst) && md5_file($dst) === $md5) {
						if (!$this->filesystem->unlink($dst)) {
							$this->io->writeError('<warning>Previously merged file ' . $dst . ' could not be removed</warning>');
						}
					} else {
						$this->io->writeError('<warning>Previously merged file ' . $dst . ' has been modified or removed</warning>');
					}
				} else if ($type === self::TYPE_DIRECTORY) {
					if (is_dir($dst) && md5_file($dst) === $md5) {
						if (!$this->filesystem->rmdir($dst)) {
							$this->io->writeError('<warning>Previously merged directory ' . $dst . ' could not be removed</warning>');
						}
					} else {
						$this->io->writeError('<warning>Previously merged directory ' . $dst . ' has been modified or removed</warning>');
					}
				}
			}
		} else {
			$this->io->writeError('<warning>Previously merged files could not be removed due to ' . self::MERGE_LOG_FILENAME . ' containing invalid JSON</warning>');
		}
		
		if (!@unlink(self::MERGE_LOG_FILENAME)) {
			$this->io->writeError('<warning>Merge plugin couldn\'t remove ' . self::MERGE_LOG_FILENAME . '</warning>');
		}
	}
	
	protected function merge($devMode) {
		$repository = $this->composer->getRepositoryManager()->getLocalRepository();
		
		$config = $this->parseConfig($this->composer->getPackage()->getExtra(), $devMode);
		
		foreach ($config as $packageName => $packageConfig) {
			$packages = $repository->findPackages($packageName);
			if (empty($packages)) {
				$this->io->writeError('<warning>Merge plugin couldn\'t find package ' . $packageName . '</warning>');
			} else {
				foreach ($packages as $package) {
					$this->mergePackage($package, $packageConfig);
				}
			}
		}
		
		if (!empty($this->mergeLog)) {
			if (@file_put_contents(self::MERGE_LOG_FILENAME, json_encode($this->mergeLog)) === false) {
				$this->io->writeError('<warning>Merge plugin couldn\'t write ' . self::MERGE_LOG_FILENAME . '</warning>');
			}
		}
	}
	
	protected function mergePackage($package, $packageConfig) {
		$repository = $this->composer->getRepositoryManager()->getLocalRepository();
		$installationManager = $this->composer->getInstallationManager();
		
		if ($installationManager->getInstaller($package->getType())->isInstalled($repository, $package)) {
			
			$absolutePackageInstallPath = $installationManager->getInstallPath($package);
			$relativePackageInstallPath = $this->filesystem->findShortestPath(getcwd(), $absolutePackageInstallPath);
			
			$mergePaths = $this->findMergePaths($relativePackageInstallPath, $packageConfig['merge-patterns']);
			
			if ($packageConfig['merge-strategy'] === self::MERGE_STRATEGY_SYMLINK) {
				$this->mergeSymlink($relativePackageInstallPath, $mergePaths);
			} else {
				$this->mergeCopy($relativePackageInstallPath, $mergePaths);
			}
		}
	}
	
	protected function parseConfig($extra, $devMode) {
		$config = array();
		
		// Read the configuration for each package:
		if (isset($extra['merge-plugin'])) {
			foreach ($extra['merge-plugin'] as $packageName => $packageExtra) {
				$config[$packageName] = $this->parsePackageConfig($packageExtra, $devMode);
			}
		} else {
			$this->io->writeError('<warning>Merge plugin configuration not found</warning>');
		}
		
		return $config;
	}
	
	protected function parsePackageConfig($packageExtra, $devMode) {
		$packageConfig = array();
		
		$packageConfig['merge-patterns'] = array();
		
		if (isset($packageExtra['merge-patterns'])) {
			foreach ($packageExtra['merge-patterns'] as $pattern) {
				if (is_array($pattern) && isset($pattern['src']) && isset($pattern['dst']) && is_string($pattern['src']) && is_string($pattern['dst'])) {
					$packageConfig['merge-patterns'][$pattern['src']] = $pattern['dst'];
				} else if (is_string($pattern)) {
					$packageConfig['merge-patterns'][$pattern] = null;
				} else {
					$this->io->writeError('<warning>Invalid merge pattern</warning>');
				}
			}
		} else {
			$this->io->writeError('<warning>No merge patterns found</warning>');
		}
		
		if ($devMode && isset($packageExtra['merge-strategy-dev'])) {
			$packageConfig['merge-strategy'] = $packageExtra['merge-strategy-dev'];
		} else if (isset($packageExtra['merge-strategy'])) {
			$packageConfig['merge-strategy'] = $packageExtra['merge-strategy'];
		} else {
			$packageConfig['merge-strategy'] = self::MERGE_STRATEGY_SYMLINK;
		}
		
		if ($packageConfig['merge-strategy'] !== self::MERGE_STRATEGY_SYMLINK && $packageConfig['merge-strategy'] !== self::MERGE_STRATEGY_COPY) {
			$this->io->writeError('<warning>Merge strategy not recognized, assuming ' . self::MERGE_STRATEGY_SYMLINK . '</warning>');
			$packageConfig['merge-strategy'] = self::MERGE_STRATEGY_SYMLINK;
		}
		
		return $packageConfig;
	}
	
	protected function findMergePaths($packageInstallPath, $packageMergePatterns) {
		$mergePaths = array();
		
		$fileIterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
			$packageInstallPath,
			\RecursiveDirectoryIterator::SKIP_DOTS |
			\RecursiveDirectoryIterator::UNIX_PATHS |
			\RecursiveDirectoryIterator::CURRENT_AS_FILEINFO
		));
		
		foreach ($fileIterator as $fileinfo) {
			$relativePath = $fileIterator->getSubPathname();
			
			foreach ($packageMergePatterns as $pattern => $replacement) {
				$matches = array();
				
				if (preg_match($pattern, $relativePath, $matches)) {
					$src = $matches[0];
					if (!empty($replacement)) {
						$dst = preg_replace($pattern, $replacement, $src);
					} else {
						$dst = $src;
					}
					$mergePaths[$packageInstallPath . DIRECTORY_SEPARATOR . $src] = $dst;
				}
			}
		}
		
		return $mergePaths;
	}
	
	protected function mergeSymlink($packageInstallPath, $mergePaths) {
		foreach ($mergePaths as $src => $dst) {
			
			if (!is_link($dst)) {
				
				$absoluteSrc = getcwd() . DIRECTORY_SEPARATOR . $src;
				$absoluteDst = getcwd() . DIRECTORY_SEPARATOR . $dst;
				
				if (@$this->filesystem->relativeSymlink($absoluteSrc, $absoluteDst)) {
					$this->mergeLog[] = array(self::MERGE_STRATEGY_SYMLINK, $dst, $src, md5_file($dst));
				} else {
					$this->io->writeError('<warning>Can\'t create symlink ' . $dst . ' with target ' . $src . '</warning>');
				}
			} else {
				if (!readlink($dst) == $src) {
					$this->io->writeError('<warning>Can\'t create symlink ' . $dst . ' with target ' . $src . ' as there is already a link with different target in place</warning>');
				} else {
					$this->io->writeError('<warning>Symlink ' . $dst . ' with target ' . $src . ' already exists</warning>');
				}
			}
		}
	}
	
	protected function mergeCopy($packageInstallPath, $mergePaths) {
		foreach ($mergePaths as $src => $dst) {
			
			if (!file_exists($dst)) {
				$this->deepCopy($src, $dst);
			} else {
				$this->io->writeError('<warning>' . $dst . ' already exists</warning>');
			}
		}
	}
	
	protected function deepCopy($src, $dst) {
		if (is_link($src)) {
			if (@symlink(readlink($src), $dst)) {
				$this->mergeLog[] = array(self::TYPE_SYMLINK, $dst, $src, md5_file($dst));
			} else {
				$this->io->writeError('<warning>Can\'t copy symlink ' . $src . ' to ' . $dst . '</warning>');
			}
		} else if (is_file($src)) {
			if (@copy($src, $dst)) {
				$this->mergeLog[] = array(self::TYPE_FILE, $dst, $src, md5_file($dst));
			} else {
				$this->io->writeError('<warning>Can\'t copy file ' . $src . ' to ' . $dst . '</warning>');
			}
		} else if (is_dir($src)) {
			if (!is_dir($dst)) {
				if (@mkdir($dst)) {
					$this->mergeLog[] = array(self::TYPE_DIRECTORY, $dst, $src, md5_file($dst));
				} else {
					$this->io->writeError('<warning>Can\'t copy directory ' . $src . ' to ' . $dst . '</warning>');
				}
			}
			
			$dir = dir($src);
			while (($path = $dir->read()) !== false) {
				if ($path === '.' || $path === '..') continue;
				$this->deepCopy($src . DIRECTORY_SEPARATOR . $path, $dst . DIRECTORY_SEPARATOR . $path);
			}
			$dir->close();
		}
	}
}