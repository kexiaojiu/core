<?php
/**
 * ownCloud - gallery
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Olivier Paroz <owncloud@interfasys.ch>
 *
 * @copyright Olivier Paroz 2016
 */

namespace OCA\Gallery\Controller;

use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\ILogger;
use OCP\Files\File;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

use OCA\Gallery\Http\ImageResponse;
use OCA\Gallery\Service\ConfigService;
use OCA\Gallery\Service\ThumbnailService;
use OCA\Gallery\Service\PreviewService;
use OCA\Gallery\Service\DownloadService;
use OCA\Gallery\Utility\EventSource;

/**
 * Class PreviewController
 *
 * @package OCA\Gallery\Controller
 */
class PreviewController extends Controller {

	use Preview;

	/** @var EventSource */
	private $eventSource;

	/**
	 * Constructor
	 *
	 * @param string $appName
	 * @param IRequest $request
	 * @param IURLGenerator $urlGenerator
	 * @param ConfigService $configService
	 * @param ThumbnailService $thumbnailService
	 * @param PreviewService $previewService
	 * @param DownloadService $downloadService
	 * @param EventSource $eventSource
	 * @param ILogger $logger
	 */
	public function __construct(
		$appName,
		IRequest $request,
		IURLGenerator $urlGenerator,
		ConfigService $configService,
		ThumbnailService $thumbnailService,
		PreviewService $previewService,
		DownloadService $downloadService,
		EventSource $eventSource,
		ILogger $logger
	) {
		parent::__construct($appName, $request);

		$this->urlGenerator = $urlGenerator;
		$this->configService = $configService;
		$this->thumbnailService = $thumbnailService;
		$this->previewService = $previewService;
		$this->downloadService = $downloadService;
		$this->eventSource = $eventSource;
		$this->logger = $logger;
	}

	/**
	 * @NoAdminRequired
	 *
	 * Generates thumbnails
	 *
	 * Uses EventSource to send thumbnails back as soon as they're created
	 *
	 * FIXME: @LukasReschke says: The exit is required here because
	 * otherwise the AppFramework is trying to add headers as well after
	 * dispatching the request which results in a "Cannot modify header
	 * information" notice.
	 *
	 * WARNING: Returning a JSON response does not get rid of the problem
	 *
	 * @param string $ids the ID of the files of which we need thumbnail previews of
	 * @param bool $square
	 * @param double $scale
	 *
	 * @return array<string,array|string|null>
	 */
	public function getThumbnails($ids, $square, $scale) {
		$idsArray = explode(';', $ids);

		foreach ($idsArray as $id) {
			// Casting to integer here instead of using array_map to extract IDs from the URL
			list($thumbnail, $status) = $this->getThumbnail((int)$id, $square, $scale);
			$thumbnail['fileid'] = $id;
			$thumbnail['status'] = $status;

			$this->eventSource->send('preview', $thumbnail);
		}
		$this->eventSource->close();

		$this->exitController();
		// @codeCoverageIgnoreStart
	} // @codeCoverageIgnoreEnd

	/**
	 * @NoAdminRequired
	 *
	 * Sends either a large preview of the requested file or the original file itself
	 *
	 * @param int $fileId the ID of the file of which we need a large preview of
	 * @param int $width
	 * @param int $height
	 *
	 * @return ImageResponse|Http\JSONResponse
	 */
	public function getPreview($fileId, $width, $height) {
		/** @type File $file */
		list($file, $preview, $status) = $this->getData($fileId, $width, $height);

		if (!$preview) {
			return new JSONResponse(
				[
					'message' => "I'm truly sorry, but we were unable to generate a preview for this file",
					'success' => false
				], $status
			);
		}
		$preview['name'] = $file->getName();

		return new ImageResponse($preview, $status);
	}
    
    
    public function getFaceThumbnails($face_list) {
            $idsArray = explode(';', $face_list); 
        //foreach ($fileArray as $id) {
            foreach ($idsArray as $file) {
            // Casting to integer here instead of using array_map to extract IDs from the URL
            list($thumbnail, $status) = $this->getFaceThumbnail($file);
            $file_parts = explode('.',$file);
            /* 
            $file_ext1 = strtolower(array_pop($file_parts));
            $file_ext2 = strtolower(array_pop($file_parts));
            $file_ext3 = strtolower(array_pop($file_parts));
            $file_ext4 = strtolower(array_pop($file_parts));
            */
            $file_ext1 = $file_parts[3];
            $file_ext2 = $file_parts[2];
            $file_ext3 = $file_parts[1];
            $file_ext4 = $file_parts[0];
            $thumbnail['filesname'] = $file_ext4;
            $thumbnail['status'] = $status;
            $thumbnail['mimetype'] = "image/png";
            $thumbnail['name'] = $file_ext3;
            $this->eventSource->send('preview', $thumbnail);
        }
        $this->eventSource->close();

        $this->exitController();
        // @codeCoverageIgnoreStart
        
    }
}
