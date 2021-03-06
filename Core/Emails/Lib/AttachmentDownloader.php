<?php
	class AttachmentDownloader extends Object{
		public function  __construct($messageId) {
			$this->attachmentPath = App::pluginPath('emails') . 'attachments' . DS . $messageId;
			$this->webrootPath = App::pluginPath('emails') . 'webroot' . DS . 'img' . DS . 'images' . DS . $messageId;
		}

		public function save($attachment) {
			$attachment['download_url'] = self::__getDownloadUrl($attachment);
			switch($attachment['type']) {
				case 'msword':
				case 'pdf':
				case 'vnd.oasis.opendocument.presentation': // odp openoffice presentation
				case 'vnd.ms-powerpoint': // pps ms office presentation
				case 'octet-stream': // executable
					$attachment['_path'] = $this->attachmentPath;
					return self::__fileDownload($attachment);
					break;

				case 'gif':
				case 'jpeg':
				case 'tiff':
					$attachment['_path'] = $this->webrootPath;
					$attachment['versions'] = self::__imageDownload($attachment);

					$attachment['_path'] = $this->attachmentPath;
					return self::__fileDownload($attachment);
					break;

				default:
					pr($attachment);
					exit;
					break;
			}
		}

		/**
		 * build the params for the file download
		 * 
		 * @param <type> $attachment
		 * @return <type>
		 */
		private function __getDownloadUrl($attachment) {
			return array(
				'message' => $attachment['message_id'],
				'file' => self::__getCachedName($attachment, 'data')
			);
		}

		/**
		 * check if the attachment has already been downloaded
		 *
		 * @param <type> $attachment
		 * @return <type>
		 */
		public function alreadySaved($attachment) {
			$filename = self::__getCachedName($attachment);
			if(is_file($this->attachmentPath . DS . $filename)) {
				$this->File = new File($this->attachmentPath . DS . $filename);
				return unserialize($this->File->read());
			}
			
			return false;
		}

		/**
		 * write the attachment details to disk so that they can be easilly
		 * downloaded with the email media classes.
		 * 
		 * @param <type> $attachment
		 * @return <type>
		 */
		private function __fileDownload($attachment) {
			$this->AttachmentFolder = new Folder($this->attachmentPath, true);

			$filename = self::__getCachedName($attachment, 'data');
			$name = $this->attachmentPath . DS . $filename;

			$this->File = new File($name, true);
			if($this->File->write(base64_decode($attachment['attachment']))) {

				unset($attachment['attachment']);
				$filename = self::__getCachedName($attachment);
				$name = $this->attachmentPath . DS . $filename;
				$this->File = new File($name, true);
				if($this->File->write(serialize($attachment))) {
					return $attachment;
				}
			}

			return false;
		}

		/**
		 * get images and make thumbnails, save them to email/webroot and
		 * maybe write the raw data to disk for download.
		 * 
		 * @param <type> $attachment
		 */
		private function __imageDownload($attachment) {
			$this->WebrootFolder = new Folder($this->webrootPath, true);
			$file = $this->webrootPath . DS .'original_' . $attachment['filename'];

			$this->File = new File($file, true);
			if($this->File->write(base64_decode($attachment['attachment']))) {				
				return array(
					'original' => sprintf('/email/img/images/%s/original_%s', $attachment['message_id'], urlencode($attachment['filename'])),
					'thumbnail' => sprintf('/email/img/images/%s/thumbnail_%s', $attachment['message_id'], urlencode($attachment['filename'])), // resize here
					'large' => sprintf('/email/img/images/%s/large_%s', $attachment['message_id'], urlencode($attachment['filename'])), // resize here
				);
			}

			return false;
		}

		/**
		 * generate cache names for the attachment. there is the info and the
		 * main data file with appropriate prefixes
		 * 
		 * @param <type> $attachment
		 * @param <type> $type
		 * @return <type>
		 */
		private function __getCachedName($attachment, $type = 'info') {		
			return cacheName(
				$type,
				array(
					'message_id' => $attachment['message_id'],
					'name' => $attachment['name'],
					'filename' => $attachment['filename']
				)
			);
		}
	}