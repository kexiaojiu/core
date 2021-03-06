<?php
/**
 * @author Arthur Schiwon <blizzz@arthur-schiwon.de>
 * @author Bart Visscher <bartv@thisnet.nl>
 * @author Björn Schießle <bjoern@schiessle.org>
 * @author Clark Tomlinson <fallen013@gmail.com>
 * @author Florian Pritz <bluewind@xinu.at>
 * @author Frank Karlitschek <frank@karlitschek.de>
 * @author Individual IT Services <info@individual-it.net>
 * @author Joas Schilling <nickvergessen@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@statuscode.ch>
 * @author Luke Policinski <lpolicinski@gmail.com>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Roman Geber <rgeber@owncloudapps.com>
 * @author TheSFReader <TheSFReader@gmail.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2016, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
\OC::$server->getSession()->close();

// Firefox and Konqueror tries to download application/json for me.  --Arthur
OCP\JSON::setContentTypeHeader('text/plain');

// If a directory token is sent along check if public upload is permitted.
// If not, check the login.
// If no token is sent along, rely on login only

$errorCode = null;

//include face api
require_once '/var/www/html/owncloud/apps/faceapi/demo_api.php';
$loacl_file_dir='/var/www/html/owncloud/data/admin/files';

$l = \OC::$server->getL10N('files');
if (empty($_POST['dirToken'])) {
	// The standard case, files are uploaded through logged in users :)
	OCP\JSON::checkLoggedIn();
	$dir = isset($_POST['dir']) ? (string)$_POST['dir'] : '';
	if (!$dir || empty($dir) || $dir === false) {
		OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('Unable to set upload directory.')))));
		die();
	}
} else {
	// TODO: ideally this code should be in files_sharing/ajax/upload.php
	// and the upload/file transfer code needs to be refactored into a utility method
	// that could be used there

	\OC_User::setIncognitoMode(true);

	$publicDirectory = !empty($_POST['subdir']) ? (string)$_POST['subdir'] : '/';

	$linkItem = OCP\Share::getShareByToken((string)$_POST['dirToken']);
	if ($linkItem === false) {
		OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('Invalid Token')))));
		die();
	}

	if (!($linkItem['permissions'] & \OCP\Constants::PERMISSION_CREATE)) {
		OCP\JSON::checkLoggedIn();
	} else {
		// resolve reshares
		$rootLinkItem = OCP\Share::resolveReShare($linkItem);

		OCP\JSON::checkUserExists($rootLinkItem['uid_owner']);
		// Setup FS with owner
		OC_Util::tearDownFS();
		OC_Util::setupFS($rootLinkItem['uid_owner']);

		// The token defines the target directory (security reasons)
		$path = \OC\Files\Filesystem::getPath($linkItem['file_source']);
		if($path === null) {
			OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('Unable to set upload directory.')))));
			die();
		}
		$dir = sprintf(
			"/%s/%s",
			$path,
			$publicDirectory
		);

		if (!$dir || empty($dir) || $dir === false) {
			OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('Unable to set upload directory.')))));
			die();
		}

		$dir = rtrim($dir, '/');
	}
}

OCP\JSON::callCheck();

// get array with current storage stats (e.g. max file size)
$storageStats = \OCA\Files\Helper::buildFileStorageStatistics($dir);

if (!isset($_FILES['files'])) {
	OCP\JSON::error(array('data' => array_merge(array('message' => $l->t('No file was uploaded. Unknown error')), $storageStats)));
	exit();
}

foreach ($_FILES['files']['error'] as $error) {
	if ($error != 0) {
		$errors = array(
			UPLOAD_ERR_OK => $l->t('There is no error, the file uploaded with success'),
			UPLOAD_ERR_INI_SIZE => $l->t('The uploaded file exceeds the upload_max_filesize directive in php.ini: ')
			. OC::$server->getIniWrapper()->getNumeric('upload_max_filesize'),
			UPLOAD_ERR_FORM_SIZE => $l->t('The uploaded file exceeds the MAX_FILE_SIZE directive that was specified in the HTML form'),
			UPLOAD_ERR_PARTIAL => $l->t('The uploaded file was only partially uploaded'),
			UPLOAD_ERR_NO_FILE => $l->t('No file was uploaded'),
			UPLOAD_ERR_NO_TMP_DIR => $l->t('Missing a temporary folder'),
			UPLOAD_ERR_CANT_WRITE => $l->t('Failed to write to disk'),
		);
		$errorMessage = $errors[$error];
		\OC::$server->getLogger()->alert("Upload error: $error - $errorMessage", array('app' => 'files'));
		OCP\JSON::error(array('data' => array_merge(array('message' => $errorMessage), $storageStats)));
		exit();
	}
}
$files = $_FILES['files'];

$error = false;

$maxUploadFileSize = $storageStats['uploadMaxFilesize'];
$maxHumanFileSize = OCP\Util::humanFileSize($maxUploadFileSize);

$totalSize = 0;
$isReceivedShare = \OC::$server->getRequest()->getParam('isReceivedShare', false) === 'true';
// defer quota check for received shares
if (!$isReceivedShare && $storageStats['freeSpace'] >= 0) {
	foreach ($files['size'] as $size) {
		$totalSize += $size;
	}
}
if ($maxUploadFileSize >= 0 and $totalSize > $maxUploadFileSize) {
	OCP\JSON::error(array('data' => array('message' => $l->t('Not enough storage available'),
		'uploadMaxFilesize' => $maxUploadFileSize,
		'maxHumanFilesize' => $maxHumanFileSize)));
	exit();
}

$result = array();
if (\OC\Files\Filesystem::isValidPath($dir) === true) {
	$fileCount = count($files['name']);
	for ($i = 0; $i < $fileCount; $i++) {

		if (isset($_POST['resolution'])) {
			$resolution = $_POST['resolution'];
		} else {
			$resolution = null;
		}

		// target directory for when uploading folders
		$relativePath = '';
		if(!empty($_POST['file_directory'])) {
			$relativePath = '/'.$_POST['file_directory'];
		}

		// $path needs to be normalized - this failed within drag'n'drop upload to a sub-folder
		if ($resolution === 'autorename') {
			// append a number in brackets like 'filename (2).ext'
			$target = OCP\Files::buildNotExistingFileName($dir . $relativePath, $files['name'][$i]);
		} else {
			$target = \OC\Files\Filesystem::normalizePath($dir . $relativePath.'/'.$files['name'][$i]);
		}

		// relative dir to return to the client
		if (isset($publicDirectory)) {
			// path relative to the public root
			$returnedDir = $publicDirectory . $relativePath;
		} else {
			// full path
			$returnedDir = $dir . $relativePath;
		}
		$returnedDir = \OC\Files\Filesystem::normalizePath($returnedDir);


		$exists = \OC\Files\Filesystem::file_exists($target);
		if ($exists) {
			$updatable = \OC\Files\Filesystem::isUpdatable($target);
		}
		if ( ! $exists || ($updatable && $resolution === 'replace' ) ) {
			// upload and overwrite file
			try
			{
				if (is_uploaded_file($files['tmp_name'][$i]) and \OC\Files\Filesystem::fromTmpFile($files['tmp_name'][$i], $target)) {

					// updated max file size after upload
					$storageStats = \OCA\Files\Helper::buildFileStorageStatistics($dir);

					$meta = \OC\Files\Filesystem::getFileInfo($target);
					if ($meta === false) {
						$error = $l->t('The target folder has been moved or deleted.');
						$errorCode = 'targetnotfound';
					} else {
						$data = \OCA\Files\Helper::formatFileInfo($meta);
						$data['status'] = 'success';
						$data['originalname'] = $files['name'][$i];
						$data['uploadMaxFilesize'] = $maxUploadFileSize;
						$data['maxHumanFilesize'] = $maxHumanFileSize;
						$data['permissions'] = $meta['permissions'];
						$data['directory'] = $returnedDir;
						$result[] = $data;
					}

				} else {
					$error = $l->t('Upload failed. Could not find uploaded file');
				}
			} catch(Exception $ex) {
				$error = $ex->getMessage();
			}

		} else {
			// file already exists
			$meta = \OC\Files\Filesystem::getFileInfo($target);
			if ($meta === false) {
				$error = $l->t('Upload failed. Could not get file info.');
			} else {
				$data = \OCA\Files\Helper::formatFileInfo($meta);
				if ($updatable) {
					$data['status'] = 'existserror';
				} else {
					$data['status'] = 'readonly';
				}
				$data['originalname'] = $files['name'][$i];
				$data['uploadMaxFilesize'] = $maxUploadFileSize;
				$data['maxHumanFilesize'] = $maxHumanFileSize;
				$data['permissions'] = $meta['permissions'];
				$data['directory'] = $returnedDir;
				$result[] = $data;
			}
		}

        /*iterate all upload files */
        //add code here to call face api.
        //local path = $dir + $relativePath
        $face_filename = $files['name'][$i];
        $face_filename = $loacl_file_dir.$returnedDir.'/'.$face_filename;
        //call face detect api to get *.json file.   
        if($face_result = api_detect_face($face_filename)) {
            $json_name = rtrim($face_filename, '.');
            if($json_file = fopen($json_name.'.json', "w")) {
                fwrite($json_file, $face_result);
                fclose($json_file);
            }
            
            //detect person via face compare api
            $face_json_result = json_decode($face_result,true);
            $face_count = count($face_json_result['faces']);
            for($ii = 0; $ii < $face_count; $ii++) {
                $face_id = $face_json_result['faces'][$ii]['faceId'];
                $person_result = identify_face($face_id);
                $person_json_result = json_decode($person_result, true);
                if($person_json_result['identified']) {
                    //if the guy already there, link this face to the persion.
                    $link_result = link_person_to_face($person_json_result['personId'], $face_id);
                    $PersonName = $person_json_result['personId'].'.'.$person_json_result['name'];
                    //adjust the thresthold for updating the thumbnail,
                    //small or negative faceness will impact the customer experience.
                    if($face_json_result['faces'][$ii]['faceness'] > 0.1) {
                        update_person_image($PersonName,
                                        $face_filename,$face_json_result['faces'][$ii]['left'],
                                        $face_json_result['faces'][$ii]['right'],
                                        $face_json_result['faces'][$ii]['top'],
                                        $face_json_result['faces'][$ii]['bottom']);
                        
                        add_faceimage_2json($PersonName);
                    }
                    //add this image to personId.person.json                    
                    api_add_person_file($face_filename, 
                                        $person_json_result['name'], 
                                        $person_json_result['personId'],
                                        $data['id'], 
                                        1);                   
                }
                else {
                    //can't find the person, create a person id with "??"+"random number"
                    //we will change the person id later with the new tag.
                    $person_rand = strtotime("now") + rand();
                    $person_rand = '??'.(string)$person_rand;
                    $person_add_result = add_person($person_rand);
                    $person_add_json_result =  json_decode($person_add_result, true);
                    //if($person_add_json_result['personId'] !== 'false')                                        
                    $personId =  $person_add_json_result['personId'];                          
                    $link_result = link_person_to_face($personId, $face_id);
                    $PersonName = $personId.'.'.$person_rand;
                    update_person_image($PersonName, $face_filename,
                                        $face_json_result['faces'][$ii]['left'],
                                        $face_json_result['faces'][$ii]['right'],
                                        $face_json_result['faces'][$ii]['top'],
                                        $face_json_result['faces'][$ii]['bottom']);
                    //create new personid.person.json
                    api_add_person_file($face_filename, $person_rand, $personId, $data['id'], 0);
                    add_faceimage_2json($PersonName);                                        
                }     
            }
            
            //$face_count = count($face_data);
            
        }
    }
} else {
	$error = $l->t('Invalid directory.');
}

if ($error === false) {
	OCP\JSON::encodedPrint($result);
} else {
	OCP\JSON::error(array(array('data' => array_merge(array('message' => $error, 'code' => $errorCode), $storageStats))));
}

