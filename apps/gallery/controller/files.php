<?php
/**
 * ownCloud - gallery
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Olivier Paroz <owncloud@interfasys.ch>
 * @author Robin Appelman <icewind@owncloud.com>
 *
 * @copyright Olivier Paroz 2014-2016
 * @copyright Robin Appelman 2012-2014
 */

namespace OCA\Gallery\Controller;

use OCP\Files\File;
use OCP\Files\Folder;
use OCP\ILogger;

use OCP\AppFramework\Http;

use OCA\Gallery\Service\SearchFolderService;
use OCA\Gallery\Service\ConfigService;
use OCA\Gallery\Service\SearchMediaService;
use OCA\Gallery\Service\DownloadService;





/**
 * Trait Files
 *
 * @package OCA\Gallery\Controller
 */
trait Files {

	use PathManipulation;

	/** @var SearchFolderService */
	private $searchFolderService;
	/** @var ConfigService */
	private $configService;
	/** @var SearchMediaService */
	private $searchMediaService;
	/** @var DownloadService */
	private $downloadService;
	/** @var ILogger */
	private $logger;

	/**
	 * @NoAdminRequired
	 *
	 * Returns a list of all media files and albums available to the authenticated user
	 *
	 *    * Authentication can be via a login/password or a token/(password)
	 *    * For private galleries, it returns all media files, with the full path from the root
	 *     folder For public galleries, the path starts from the folder the link gives access to
	 *     (virtual root)
	 *    * An exception is only caught in case something really wrong happens. As we don't test
	 *     files before including them in the list, we may return some bad apples
	 *
	 * @param string $location a path representing the current album in the app
	 * @param array $features the list of supported features
	 * @param string $etag the last known etag in the client
	 * @param array $mediatypes the list of supported media types
	 *
	 * @return array <string,array<string,string|int>>|Http\JSONResponse
	 */
	private function getFilesAndAlbums($location, $features, $etag, $mediatypes) {
		$files = [];
		$albums = [];
		$updated = true;
		/** @var Folder $folderNode */
		list($folderPathFromRoot, $folderNode) =
			$this->searchFolderService->getCurrentFolder(rawurldecode($location), $features);
		$albumConfig = $this->configService->getConfig($folderNode, $features);
		if ($folderNode->getEtag() !== $etag) {
			list($files, $albums) = $this->searchMediaService->getMediaFiles(
				$folderNode, $mediatypes, $features
			);
		} else {
			$updated = false;
		}
		$files = $this->fixPaths($files, $folderPathFromRoot);

		return $this->formatResults($files, $albums, $albumConfig, $folderPathFromRoot, $updated);
	}

	/**
	 * Generates shortened paths to the media files
	 *
	 * We only want to keep one folder between the current folder and the found media file
	 * /root/folder/sub1/sub2/file.ext
	 * becomes
	 * /root/folder/file.ext
	 *
	 * @param array $files
	 * @param string $folderPathFromRoot
	 *
	 * @return array
	 */
	private function fixPaths($files, $folderPathFromRoot) {
		if (!empty($files)) {
			foreach ($files as &$file) {
				$file['path'] = $this->getReducedPath($file['path'], $folderPathFromRoot);
			}
		}

		return $files;
	}

	/**
	 * Simply builds and returns an array containing the list of files, the album information and
	 * whether the location has changed or not
	 *
	 * @param array $files
	 * @param array $albums
	 * @param array $albumConfig
	 * @param string $folderPathFromRoot
	 * @param bool $updated
	 *
	 * @return array
	 * @internal param $array <string,string|int> $files
	 */
	private function formatResults($files, $albums, $albumConfig, $folderPathFromRoot, $updated) {
		return [
			'files'       => $files,
			'albums'      => $albums,
			'albumconfig' => $albumConfig,
			'albumpath'   => $folderPathFromRoot,
			'updated'     => $updated
		];
	}

	/**
	 * Generates the download data
	 *
	 * @param int $fileId the ID of the file of which we need a large preview of
	 * @param string|null $filename
	 *
	 * @return array|false
	 */
	private function getDownload($fileId, $filename) {
		/** @type File $file */
		$file = $this->downloadService->getFile($fileId);
		$this->configService->validateMimeType($file->getMimeType());
		$download = $this->downloadService->downloadFile($file);
		if (is_null($filename)) {
			$filename = $file->getName();
		}
		$download['name'] = $filename;

		return $download;
	}

    /**
     * Returns a list of all face thumbnails
     *
     * @param 
     * @param 
     *
     * @return array|false
     */   
    private function getFaceThumbnails($key) {
        require_once '/var/www/html/owncloud/apps/faceapi/demo_api.php';
        //header('Content-type:text/json');
        $filesA = array();         
        $filesB = array();
        $files_temp = getFaceFileList($loacl_file_dir);
        
        if (strlen($key) > 0) {
            //$hint="";
            if ($key == "**1**"){
                $filesA = $files_temp;  
            }
            else{
            for($i=0; $i<count($files_temp); $i++) {
                $file_parts = explode('.',$files_temp[$i]); 
                $file_ext1 = strtolower(array_pop($file_parts));
                $file_ext2 = strtolower(array_pop($file_parts));
                $file_ext3 = strtolower(array_pop($file_parts));
                $file_ext4 = strtolower(array_pop($file_parts));
                if (strtolower($key) == strtolower(substr($file_ext3,0,strlen($key)))) {
                    array_push($filesA, $files_temp[$i]);
                } else if (substr($file_ext3,0,2) == "??"){
                    array_push($filesB, $files_temp[$i]);
                }
            }
            }
        }
//        if(count($filesB) > 0) 
//            $filesA=array_merge($filesA, $filesB);
        $filesA=json_encode($filesA);
        $filesB=json_encode($filesB);
        if($key == " ")
            return $filesB;    //only return untagged image
        else
            return $filesA; 
              //return key image and untagged image
    }
    
    /**
     * Returns a list of one person's whole images
     *
     * @param 
     * @param 
     *
     * @return array|false
     */
    private function getPersonImageList($name) {
        //$files = array();
        require_once '/var/www/html/owncloud/apps/faceapi/demo_api.php';
        $files = getPersonJson($loacl_file_dir, $name);
        $json = file_get_contents($files);
        return $json;
        
        
    }
    
    /**
     * tag the person
     *
     * @param 
     * @param 
     *
     * @return array|false
     */
    private function setPersonName($oldName, $newName,$personID) {
        //$files = array();
        require_once '/var/www/html/owncloud/apps/faceapi/demo_api.php';
        return $files = tagPerson($loacl_file_dir, $oldName, $newName,$personID);
        
    }
    
        /**
     * Returns a list of all face thumbnails choosed by user
     *
     * @param no 
     *
     * @return array|false
     */   
    private function getChoosePhotos($filesname) {
        $loacl_file_dir="/var/www/html/owncloud/data/admin/files";
        $person_file = $loacl_file_dir."/".$filesname.".json";
        $json = file_get_contents($person_file);
        if(!$json) {
                    $fp = fopen($person_file, "w+");
                    if (!is_writable($person_file)) {
                            return false;
                    }
                    
                    $data = array();
                    $data = json_encode($data);
                    fwrite($fp, $data); 
                    fclose($fp); 
            }
        //header('Content-type:text/json');
        $json = json_decode($json, true);
        $filesA = array();         
        $filesA = $json;
        $filesA=json_encode($filesA);
        return $filesA; 
              //return key image and untagged image
    }

        /**
     * Pick face image from "remaining_face" to "choosed_face"
     *
     * @param no 
     *
     * @return array|false
     */   
    private function Pickface($face_list) {
        $loacl_file_dir="/var/www/html/owncloud/data/admin/files";
        $choosed = $loacl_file_dir."/"."Choosed_face.json";
        $remaining = $loacl_file_dir."/"."remaining_face.json";
        $json_choosed = file_get_contents($choosed);
        $json_remaining = file_get_contents($remaining);
        $json_choosed = json_decode($json_choosed, true);
        $json_remaining = json_decode($json_remaining, true);
       
        $idsArray = explode(';', $face_list); 
        foreach ($idsArray as $file) {
            
             $key = array_search($file, $json_remaining);
             if ($key === false)
                return false;
             array_splice($json_remaining, $key, 1);
             /*the file is already here.*/
             $number = count($json_choosed);
             for($ii = 0 ; $ii < $number; $ii++) {
                if($file === $json_choosed[$ii]){
                    $json_remaining = json_encode($json_remaining);
                    file_put_contents($remaining, $json_remaining);
                    return false;
                    }
                }
             array_push($json_choosed, $file);

                
        }
             $json_choosed = json_encode($json_choosed);
             file_put_contents($choosed, $json_choosed);
             $json_remaining = json_encode($json_remaining);
             file_put_contents($remaining, $json_remaining);
             return true; 
              //return key image and untagged image
    }
    
}
