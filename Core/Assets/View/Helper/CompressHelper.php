<?php
	/**
	 * The helper definition for the Script Combiner helper.
	 * @author Geoffrey Garbers
	 * @version 0.1
	 *
	 * @property JavascriptHelper $Javascript
	 */

	App::uses('InfinitasHelper', 'Libs.View/Helper');
	App::uses('File', 'Utility');
	
	class CompressHelper extends InfinitasHelper {
		/**
		 * The directory to which the combined CSS files will be cached.
		 *
		 * @access public
		 * @var string
		 */
		public $cssCachePath;

		/**
		 * The directory to which the combined Javascript files will be cached.
		 *
		 * @access public
		 * @var string
		 */
		public $jsCachePath;

		/**
		 * Indicates the time of expiry for the combined files.
		 *
		 * @access public
		 * @var int
		 */
		public $cacheLength;

		/**
		 * Indicates whether the class is active and is able to begin combining scripts.
		 *
		 * @access public
		 * @var bool
		 */
		public $enabled = false;

		/**
		 * Sets up the helper's properties, and determines whether the helper's environment
		 * is set up and ready to be used.
		 *
		 * @access public
		 *
		 * @return void
		 */
		public function __construct(View $View, $settings = array()) {
			parent::__construct($View, $settings);

			$this->cssCachePath = CSS;
			$this->jsCachePath  = JS;

			$cacheLength = Configure::read('Assets.cacheLength');
			if (is_string($cacheLength)) {
				$this->cacheLength = strtotime($cacheLength) - time();
			} 
			
			else {
				$this->cacheLength = (int)$cacheLength;
			}

			if(Configure::read('debug') != 2) {
				$this->enabled = true;
			}
		}

		/**
		 * Receives numerous CSS files, and combines all the supplied CSS files into one
		 * file, which helps in reducing the number of HTTP requests needed to load numerous
		 * CSS files.
		 *
		 * Files to be combined should be supplied exactly as if they were being used in
		 * the HtmlHelper::css() method, as this method is used to generate the paths
		 * to the files.
		 *
		 * @param mixed [$url1,$url2,...] Either an array of files to combine, or multiple arguments of filenames.
		 * @access public
		 *
		 * @return string The HTML &lt;link /&gt; to either the combined file, or to the multiple CSS files if the combined file could not be cached.
		 */
		public function css() {
			$cssFiles = func_get_args();

			if (empty($cssFiles)) {
				return '';
			}

			// Whoops. No configuration options defined, or something else went wrong
			// in trying to set up the class. Either way, we can't process the files,
			// so we'll need to handle this through the parent.
			if (!$this->enabled) {
				return $this->_View->Helpers->load('Html')->css($cssFiles);
			}

			// Let's generate the cache hash, and ensure we have all the files that
			// we are going to use to process.
			if (is_array($cssFiles[0])) {
				$cssFiles = $cssFiles[0];
			}

			$cacheKey = md5(serialize($cssFiles));

			// Let's generate the path to the cache file.
			$cacheFile = $this->cssCachePath . 'combined.' . $cacheKey . '.css';

			$this->File = new File($cacheFile);

			// Oh. Look. It appears we already have a cached version of the combined
			// file. This means we'll have to see when it last modified, and ensure
			// that the cached version hasn't yet expired. If it has, then we can
			// just return the URL to it straight away.

			if ($this->isCacheFileValid($cacheFile)) {
				$cacheFile = $this->convertToUrl($cacheFile);
				return $this->Html->css($cacheFile);
			}

			// Let's generate the HTML that would normally be returned, and strip
			// out the URLs.

			$cssData = $this->_getCssFiles($cssFiles);

			// If we can cache the file, then we can return the URL to the file.
			if ($this->File->write($cssData)) {
				return $this->Html->css($this->convertToUrl($cacheFile));
			}

			// Otherwise, we'll have to trigger an error, and pass the handling of the
			// CSS files to the HTML Helper.
			trigger_error("Cannot combine CSS files to {$cacheFile}. Please ensure this directory is writable.", E_USER_WARNING);
			return $this->Html->css($cssFiles);
		}

		protected function _getCssFiles($cssFiles) {
			$urlMatches = $cssData = array();

			$_setting = Configure::read('Assets.timestamp');
			Configure::write('Assets.timestamp', false);
			$links = $this->Html->css($cssFiles, 'import');
			Configure::write('Assets.timestamp', $_setting);

			preg_match_all('#\(([^\)]+)\)#i', $links, $urlMatches);
			$urlMatches = isset($urlMatches[1]) ? $urlMatches[1] : array();

			$cssData = array();
			foreach ($urlMatches as $urlMatch) {
				$cssPath = str_replace(array('/', '\\'), DS, WWW_ROOT . ltrim(Router::normalize($urlMatch), '/'));
				if (is_file($cssPath)) {
					$css = file_get_contents($cssPath);
					if(strstr($css, '../')) {
						$parts = explode('/', str_replace(APP . 'webroot' . DS, '', $cssPath));
						$css = str_replace('../', '/' .$parts[0] . '/', $css);
					}
					
					$cssData[] = $css;
					unset($parts, $css);
				}
			}

			$cssData = implode(Configure::read('Assets.fileSeparator'), $cssData);

			if (Configure::read('Assets.compressCss')) {
				$cssData = $this->compressCss($cssData);
			}

			return $cssData;
		}

		public function script() {
			$args = func_get_args();
			
			if(!empty($args)) {
				$tmp = array();
				foreach($args[0] as $k => &$v) {
					if(strstr(strtolower($v), 'wysiwyg')) {
						$tmp[] = $v;
						unset($args[0][$k]);
					}
				}
				return call_user_func_array(array($this, 'js'), $args) .
					$this->Html->script($tmp);
				
			}
			
			else {
				return $this->js();
			}
		}

		/**
		 * Receives a number of Javascript files, and combines all of them together.
		 * @param mixed [$url1,$url2,...] Either an array of files to combine, or multiple arguments of filenames.
		 * @access public
		 *
		 * @return string The HTML &lt;script /&gt; to either the combined file,
		 *	or to the multiple Javascript files if the combined file could not be cached.
		 */
		public function js() {
			$jsFiles = func_get_args();

			if (empty($jsFiles)) {
				return '';
			}

			if (!$this->enabled) {
				return $this->Html->script($jsFiles);
			}

			if (is_array($jsFiles[0])) {
				$jsFiles = $jsFiles[0];
			}
			$cacheKey = md5(serialize($jsFiles));

			$cacheFile = $this->jsCachePath . 'combined.' . $cacheKey . '.js';
			$this->File = new File($cacheFile);

			if ($this->isCacheFileValid($cacheFile)) {
				return $this->Html->script($this->convertToUrl($cacheFile));
			}

			$jsData = $this->_getJsFiles($jsFiles);
			if ($this->File->write($jsData)) {
				return $this->Html->script($this->convertToUrl($cacheFile));
			}
			
			CakeLog::write('assets', 'Unable to write combined js file');
			
			$this->Html->script($jsFiles);
		}

		protected function _getJsFiles($jsFiles) {
			$urlMatches = $jsData = array();

			$_setting = Configure::read('Asset.timestamp');
			Configure::read('Assets.timestamp', false);
			$jsLinks = $this->Html->script($jsFiles);
			Configure::read('Assets.timestamp', $_setting);

			preg_match_all('/src="([^"]+)"/i', $jsLinks, $urlMatches);
			$urlMatches = isset($urlMatches[1]) ? array_unique($urlMatches[1]) : array();

			foreach ($urlMatches as $urlMatch) {
				$jsPath = str_replace(array('/', '\\'), DS, WWW_ROOT . ltrim(Router::normalize($urlMatch), '/'));
				if (is_file($jsPath)) {
					$jsData[] = file_get_contents($jsPath);
				}
			}

			$jsData = implode(Configure::read('Assets.fileSeparator'), $jsData);

			if (Configure::read('Assets.compressJs')) {
				$jsData = $this->compressJs($jsData);
			}

			$jsData = "/** \r\n * Files included \r\n * " . implode("\r\n * ", $urlMatches) . "\r\n */ \r\n" . $jsData;

			return $jsData;
		}

		/**
		 * Indicates whether the supplied cached file's cache life has expired or not.
		 * Returns a boolean value indicating this.
		 *
		 * @param string $cacheFile The path to the cached file to check.
		 * @access private
		 *
		 * @return bool Flag indicating whether the file has expired or not.
		 */
		private function isCacheFileValid($cacheFile) {
			if (is_file($cacheFile) && $this->cacheLength > 0) {
				$lastModified = filemtime($cacheFile);
				$timeNow = time();
				if (($timeNow - $lastModified) < $this->cacheLength) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Receives the path to a given file, and strips the webroot off the file, returning
		 * a URL path that is relative to the webroot (WWW_ROOT).
		 *
		 * @param string $filePath The path to the file.
		 * @access private
		 *
		 * @return string The path to the file, relative to WWW_ROOT (webroot).
		*/
		private function convertToUrl($filePath) {
			$___path = Set::filter(explode(DS, $filePath));
			$___root = Set::filter(explode(DS, WWW_ROOT));
			$webroot = array_diff_assoc($___root, $___path);

			$webrootPaths = array_diff_assoc($___path, $___root);
			return ('/' . implode('/', $webrootPaths));
		}

		/**
		 * Receives the CSS data to compress, and compresses it. Doesn't apply any encoding
		 * to it (such as GZIP), but merely strips out unnecessary whitespace.
		 *
		 * @param string $cssData CSS data to be compressed.
		 * @access private
		 * @return string Compressed CSS data.
		 */
		private function compressCss($cssData) {
			// let's remove all the comments from the css code.
			$cssData = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $cssData);

			// let's remove all tabs and line breaks.
			$cssData = str_replace(array("\r\n", "\r", "\n", "\t"), '', $cssData);

			// remove trailing semicolons just before closing brace.
			$cssData = preg_replace('/;\s*}/i', '}', $cssData);

			// remove any whitespace between element selector and opening brace.
			$cssData = preg_replace('/[\t\s]*{[\t\s]*/i', '{', $cssData);

			// remove whitespace between style declarations and their values.
			$cssData = preg_replace('/[\t\s]*:[\t\s]*/i', ':', $cssData);

			// remove whitespace between sizes and their measurements.
			$cssData = preg_replace('/(\d)[\s\t]+(em|px|%)/i', '$1$2', $cssData);

			// remove any spaces between background image "url" and the opening "(".
			$cssData = preg_replace('/url[\s\t]+\(/i', 'url(', $cssData);

			return $cssData;
		}

		/**
		 * Compresses the supplied Javascript data, removing extra whitespaces, as well
		 * as any comments found.
		 *
		 * @todo Implement reliable Javascript compression without use of a 3rd party.
		 *
		 * @param string $jsData The Javascript data to be compressed.
		 * @access private
		 *
		 * @return string The compressed Javascript data.
		 */
		private function compressJs($jsData) {
			App::uses('JSMin', 'Assets.Lib');
			
			return JSMin::minify($jsData);
		}
	}
