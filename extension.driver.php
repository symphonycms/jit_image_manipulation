<?php

	Class extension_JIT_Image_Manipulation extends Extension{

		public function about(){
			return array('name' => 'JIT Image Manipulation',
						 'version' => '1.0',
						 'release-date' => '2009-03-03',
						 'author' => array('name' => 'Alistair Kearney',
										   'website' => 'http://pointybeard.com',
										   'email' => 'alistair@pointybeard.com')
				 		);
		}

		public function install(){
			
			$htaccess = @file_get_contents(DOCROOT . '/.htaccess');
			
			if($htaccess === false) return false;
			
			## Find out if the rewrite base is another other than /
			$rewrite_base = NULL;
			if(preg_match('/RewriteBase\s+([^\s]+)/i', $htaccess, $match)){
				$rewrite_base = trim($match[1], '/') . '/';
			}
			
			## Cannot use $1 in a preg_replace replacement string, so using a token instead
			$token = md5(time());
			
			$rule = "
	### IMAGE RULES	
	RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))\$ /{$rewrite_base}extensions/jit_image_manipulation/lib/image.php?param={$token} [L,NC]\n\n";
			
			## Add/Replace the rule
			$htaccess = self::__removeImageRules($htaccess);
			
			if(preg_match('/### IMAGE RULES/', $htaccess)){
				$htaccess = preg_replace('/### IMAGE RULES/', $rule, $htaccess);
			}
			else{
				$htaccess = preg_replace('/RewriteRule .\* - \[S=14\]\s*/i', "RewriteRule .* - [S=14]\n{$rule}\t", $htaccess);
			}
			
			## Replace the token with the real value
			$htaccess = str_replace($token, '$1', $htaccess);

			return @file_put_contents(DOCROOT . '/.htaccess', $htaccess);

		}
		
		public function uninstall(){
			$htaccess = @file_get_contents(DOCROOT . '/.htaccess');
			
			if($htaccess === false) return false;
			
			$htaccess = self::__removeImageRules($htaccess);
			$htaccess = preg_replace('/### IMAGE RULES/', NULL, $htaccess);
			
			return @file_put_contents(DOCROOT . '/.htaccess', $htaccess);
		}
		
		private static function __removeImageRules($string){
			return preg_replace('/RewriteRule \^image[^\r\n]+[\r\n]?/i', NULL, $string);	
		}

	}