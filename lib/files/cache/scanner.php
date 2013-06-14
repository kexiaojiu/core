<?php
/**
 * Copyright (c) 2012 Robin Appelman <icewind@owncloud.com>
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OC\Files\Cache;

use OC\Files\Filesystem;

class Scanner {
	/**
	 * @var \OC\Files\Storage\Storage $storage
	 */
	private $storage;

	/**
	 * @var string $storageId
	 */
	private $storageId;

	/**
	 * @var \OC\Files\Cache\Cache $cache
	 */
	private $cache;

	const SCAN_RECURSIVE = true;
	const SCAN_SHALLOW = false;

	public function __construct(\OC\Files\Storage\Storage $storage) {
		$this->storage = $storage;
		$this->storageId = $this->storage->getId();
		$this->cache = $storage->getCache();
	}

	/**
	 * get all the metadata of a file or folder
	 * *
	 *
	 * @param string $path
	 * @return array with metadata of the file
	 */
	public function getData($path) {
		$data = array();
		if (!$this->storage->isReadable($path)) return null; //cant read, nothing we can do
		$data['mimetype'] = $this->storage->getMimeType($path);
		$data['mtime'] = $this->storage->filemtime($path);
		if ($data['mimetype'] == 'httpd/unix-directory') {
			$data['size'] = -1; //unknown
		} else {
			$data['size'] = $this->storage->filesize($path);
		}
		$data['etag'] = $this->storage->getETag($path);
		return $data;
	}

	/**
	 * scan a single file and store it in the cache
	 *
	 * @param string $file
	 * @param bool $checkExisting check existing folder sizes in the cache instead of always using -1 for folder size
	 * @return array with metadata of the scanned file
	 */
	public function scanFile($file, $checkExisting = false) {
		if (!self::isPartialFile($file)
			and !Filesystem::isFileBlacklisted($file)
		) {
			\OC_Hook::emit('\OC\Files\Cache\Scanner', 'scan_file', array('path' => $file, 'storage' => $this->storageId));
			$data = $this->getData($file);
			if ($data) {
				if ($file) {
					$parent = dirname($file);
					if ($parent === '.' or $parent === '/') {
						$parent = '';
					}
					if (!$this->cache->inCache($parent)) {
						$this->scanFile($parent);
					}
				}
				$newData = $data;
				if ($cacheData = $this->cache->get($file)) {
					if ($checkExisting && $data['size'] === -1) {
						$data['size'] = $cacheData['size'];
					}
					if ($data['mtime'] === $cacheData['mtime'] &&
						$data['size'] === $cacheData['size']
					) {
						$data['etag'] = $cacheData['etag'];
					}
					// Only update metadata that has changed
					$newData = array_diff($data, $cacheData);
				}
				if (!empty($newData)) {
					$this->cache->put($file, $newData);
				}
			}
			return $data;
		}
		return null;
	}

	/**
	 * scan a folder and all it's children
	 *
	 * @param string $path
	 * @param bool $recursive
	 * @return int the size of the scanned folder or -1 if the size is unknown at this stage
	 */
	public function scan($path, $recursive = self::SCAN_RECURSIVE) {
		$this->scanFile($path);
		return $this->scanChildren($path, $recursive);
	}

	/**
	 * scan all the files and folders in a folder
	 *
	 * @param string $path
	 * @param bool $recursive
	 * @return int the size of the scanned folder or -1 if the size is unknown at this stage
	 */
	public function scanChildren($path, $recursive = self::SCAN_RECURSIVE) {
		\OC_Hook::emit('\OC\Files\Cache\Scanner', 'scan_folder', array('path' => $path, 'storage' => $this->storageId));
		$size = 0;
		$childQueue = array();
		if ($this->storage->is_dir($path) && ($dh = $this->storage->opendir($path))) {
			\OC_DB::beginTransaction();
			while ($file = readdir($dh)) {
				$child = ($path) ? $path . '/' . $file : $file;
				if (!Filesystem::isIgnoredDir($file)) {
					$data = $this->scanFile($child, $recursive === self::SCAN_SHALLOW);
					if ($data) {
						if ($data['size'] === -1) {
							if ($recursive === self::SCAN_RECURSIVE) {
								$childQueue[] = $child;
							} else {
								$size = -1;
							}
						} else if ($size !== -1) {
							$size += $data['size'];
						}
					}
				}
			}
			\OC_DB::commit();
			foreach ($childQueue as $child) {
				$childSize = $this->scanChildren($child, self::SCAN_RECURSIVE);
				if ($childSize === -1) {
					$size = -1;
				} else {
					$size += $childSize;
				}
			}
			if ($size !== -1) {
				$this->cache->put($path, array('size' => $size));
			}
		}
		return $size;
	}

	/**
	 * @brief check if the file should be ignored when scanning
	 * NOTE: files with a '.part' extension are ignored as well!
	 *       prevents unfinished put requests to be scanned
	 * @param String $file
	 * @return boolean
	 */
	public static function isPartialFile($file) {
		if (pathinfo($file, PATHINFO_EXTENSION) === 'part') {
			return true;
		}
		return false;
	}

	/**
	 * walk over any folders that are not fully scanned yet and scan them
	 */
	public function backgroundScan() {
		while (($path = $this->cache->getIncomplete()) !== false) {
			$this->scan($path);
			$this->cache->correctFolderSize($path);
		}
	}
}
