<?php

	Class extension_JIT_Image_Manipulation implements iExtension{

		public function about(){
			return (object)array(
				'name' => 'JIT Image Manipulation',
				'version' => '2.0.0',
				'release-date' => '2010-05-25',
				'author' => (object)array(
					'name' => 'Alistair Kearney',
					'website' => 'http://alistairkearney.com',
					'email' => 'hi@alistairkearney.com'
				)
			);
		}
		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/settings/extensions/',
					'delegate' => 'AddSettingsFieldsets',
					'callback' => 'cbAppendPreferences'
				),
						
				array(
					'page' => '/system/settings/extensions/',
					'delegate' => 'CustomSaveActions',
					'callback' => 'cbSavePreferences'
				),
			);
		}
		
		public function cbSavePreferences($context){
			Symphony::Configuration()->jit()->{'trusted-external-sites'} =
				preg_split('/[\r\n]+/', stripslashes($_POST['jit']['trusted-external-sites']), -1, PREG_SPLIT_NO_EMPTY);
				
			Symphony::Configuration()->jit()->save();
		}
		
		public function cbAppendPreferences($context){

			$group = Administration::instance()->Page->createElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(Administration::instance()->Page->createElement('h3', __('JIT Image Manipulation')));
			
			$label = Widget::Label(__('Trusted Sites'));
			$label->appendChild(Widget::Textarea(
				'jit[trusted-external-sites]', 
				implode("\n", (array)Symphony::Configuration()->jit()->{'trusted-external-sites'}), 
				array('rows' => 10, 'cols' => 50)
			));
			
			$group->appendChild($label);
						
			$group->appendChild(Administration::instance()->Page->createElement(
				'p', __('Leave empty to disable external linking. Single rule per line. Add * at end for wild card matching.'), 
				array('class' => 'help')
			));

			$context['fieldsets'][] = $group;
						
		}
		
		public function enable(){
			return $this->install();
		}
		
		public function disable(){
			$htaccess = @file_get_contents(DOCROOT . '/.htaccess');
			
			if($htaccess === false) return false;
			
			$htaccess = self::__removeImageRules($htaccess);
			$htaccess = preg_replace('/### IMAGE RULES/', NULL, $htaccess);
			
			return @file_put_contents(DOCROOT . '/.htaccess', $htaccess);
		}
		
		public function install(){
			
			Symphony::Configuration()->jit()->cache = 'enabled';
			Symphony::Configuration()->jit()->quality = '90';
			Symphony::Configuration()->jit()->{'trusted-external-sites'} = NULL;
			Symphony::Configuration()->jit()->save();
			
			$htaccess = @file_get_contents(DOCROOT . '/.htaccess');
			
			if($htaccess === false) return false;
			
			## Cannot use $1 in a preg_replace replacement string, so using a token instead
			$token = md5(time());
			
			$rule = "
	### IMAGE RULES	
	RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))$ index.php?symphony-renderer=extensions/jit_image_manipulation/lib/image.php&symphony-page={$token}&%{QUERY_STRING}	[NC,L]\n\n";
			
			## Remove existing the rules
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
			
			if(file_exists(CONF . '/jit.xml')) unlink(CONF . '/jit.xml');
			
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
	
	return 'extension_JIT_Image_Manipulation';