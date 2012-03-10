<?php

	Class extension_JIT_Image_Manipulation extends Extension{

		public function about(){
			return array(
				'name' => 'JIT Image Manipulation',
				'version' => '1.15',
				'release-date' => '2012-03-10',
				'author' => array(
					array(
						'name' => 'Alistair Kearney',
						'website' => 'http://pointybeard.com',
						'email' => 'alistair@pointybeard.com'
					),
					array(
						'name' => 'Symphony Team',
						'website' => 'http://symphony-cms.com/',
						'email' => 'team@symphony-cms.com'
					)
				)
			);
		}

		public function getSubscribedDelegates(){
			return array(
				array(
					'page' => '/system/preferences/',
					'delegate' => 'AddCustomPreferenceFieldsets',
					'callback' => 'appendPreferences'
				),
				array(
					'page' => '/system/preferences/',
					'delegate' => 'Save',
					'callback' => '__SavePreferences'
				)
			);
		}

		public function install() {
			try {
				$htaccess = file_get_contents(DOCROOT . '/.htaccess');

				// Cannot use $1 in a preg_replace replacement string, so using a token instead
				$token = md5(time());

				$rule = "
	### IMAGE RULES
	RewriteRule ^image\/(.+\.(jpg|gif|jpeg|png|bmp))\$ extensions/jit_image_manipulation/lib/image.php?param={$token} [L,NC]\n\n";

				// Remove existing the rules
				$htaccess = self::__removeImageRules($htaccess);

				if(preg_match('/### IMAGE RULES/', $htaccess)){
					$htaccess = preg_replace('/### IMAGE RULES/', $rule, $htaccess);
				}
				else{
					$htaccess = preg_replace('/RewriteRule .\* - \[S=14\]\s*/i', "RewriteRule .* - [S=14]\n{$rule}\t", $htaccess);
				}

				// Replace the token with the real value
				$htaccess = str_replace($token, '$1', $htaccess);

				if(file_put_contents(DOCROOT . '/.htaccess', $htaccess)) {
					// Now add Configuration values
					Symphony::Configuration()->set('cache', '1', 'image');
					Symphony::Configuration()->set('quality', '90', 'image');

					// Create workspace directory
					General::realiseDirectory(WORKSPACE . '/jit-image-manipulation', Symphony::Configuration()->get('write_mode', 'directory'));

					if(method_exists('Configuration', 'write')) {
						return Symphony::Configuration()->write();
					}
					else {
						return Administration::instance()->saveConfig();
					}
				}
				else return false;
			}
			catch (Exception $ex) {
				$extension = $this->about();
				Administration::instance()->Page->pageAlert(__('An error occurred while installing %s. %s', array($extension['name'], $ex->getMessage())), Alert::ERROR);
				return false;
			}
		}

		public function enable(){
			return $this->install();
		}

		public function update($previousVersion = false){
			if(version_compare($previousVersion, '1.15', '<')) {
				// Move /manifest/jit-trusted-sites into /workspace/jit-image-manipulation
				if (General::realiseDirectory(WORKSPACE . '/jit-image-manipulation', Symphony::Configuration()->get('write_mode', 'directory')) && file_exists(MANIFEST . '/jit-trusted-sites')) {
					rename(MANIFEST . '/jit-trusted-sites', WORKSPACE . '/jit-image-manipulation/trusted-sites');
				}
			}
		}

		public function uninstall(){
			General::deleteDirectory(WORKSPACE . '/jit-image-manipulation');

			return $this->disable();
		}

		public function disable() {
			try {
				$htaccess = file_get_contents(DOCROOT . '/.htaccess');

				$htaccess = self::__removeImageRules($htaccess);
				$htaccess = preg_replace('/### IMAGE RULES/', NULL, $htaccess);

				return file_put_contents(DOCROOT . '/.htaccess', $htaccess);
			}
			catch (Exception $ex) {
				$extension = $this->about();
				Administration::instance()->Page->pageAlert(__('An error occurred while installing %s. %s', array($extension['name'], $ex->getMessage())), Alert::ERROR);
				return false;
			}
		}

	/*-------------------------------------------------------------------------
		Utilities:
	-------------------------------------------------------------------------*/

		public function trusted(){
			return is_readable(WORKSPACE . '/jit-image-manipulation/trusted-sites')
				? file_get_contents(WORKSPACE . '/jit-image-manipulation/trusted-sites')
				: NULL;
		}

		public function saveTrusted($string){
			return General::writeFile(WORKSPACE . '/jit-image-manipulation/trusted-sites', $string, Symphony::Configuration()->get('write_mode', 'file'));
		}

		public function saveRecipes($recipes){

			$string = "<?php\n";

			$string .= "\n\t\$recipes = array(";
			
			if (is_array($recipes) && !empty($recipes)) {
				foreach($recipes as $recipe => $data){
					$string .= "\r\n\r\n\r\n\t\t########";
					$string .= "\r\n\t\tarray(";
					foreach($data as $key => $value){
						$string .= "\r\n\t\t\t'$key' => ".(strlen($value) > 0 ? "'".addslashes($value)."'" : 'NULL').",";
					}
					$string .= "\r\n\t\t),";
					$string .= "\r\n\t\t########";
				}
			}
			$string .= "\r\n\t);\n\n";

			return General::writeFile(WORKSPACE . '/jit-image-manipulation/recipes.php', $string, Symphony::Configuration()->get('write_mode', 'file'));

		}

		private static function __removeImageRules($string){
			return preg_replace('/RewriteRule \^image[^\r\n]+[\r\n]?/i', NULL, $string);
		}

		public function createRecipeDuplicatorTemplate($mode = '0', $position = '-1', $values = array()){
			$modes = array(
				'0' => __('Direct display'),
				'1' => __('Resize'),
				'2' => __('Crop to Fill'),
				'3' => __('Resize Canvas'),
				'4' => __('Resize to Fit'),
				'regex' => __('Custom')
			);

			$referencePositions = array(
				__('Top left'),
				__('Top center'),
				__('Top right'),
				__('Center left'),
				__('Center'),
				__('Center right'),
				__('Bottom left'),
				__('Bottom center'),
				__('Bottom right'),
			);
			$positionOptions = array();
			foreach ($referencePositions as $i => $p) {
				$positionOptions[] = array($i + 1, $i + 1 == $values['position'] ? true : false, $p);
			}

			// general template settings
			$li = new XMLElement('li');
			$li->setAttribute('class', $position >= 0 ? 'instance expanded' : 'template');
			$li->setAttribute('data-type', 'mode-' . $mode);
			$header = new XMLElement('header', NULL, array('data-name' => $modes[$mode]));
			$label = (!empty($values['name'])) ? $values['name'] : __('New Recipe');
			$header->appendChild(new XMLElement('h4', '<strong>' . $label . '</strong> <span class="type">' . $modes[$mode] . '</span>'));
			$li->appendChild($header);

			$li->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][mode]", $mode, 'hidden'));

			$group = new XMLElement('div');
			$group->setAttribute('class', 'two columns');

			$label = Widget::Label(__('Name'), null, 'column');
			$label->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][name]", $values['name']));
			$group->appendChild($label);

			$label_text = $mode === 'regex' ? __('Regular Expression') : __('Handle') . '<i>e.g. /image/{handle}/my-image.jpg</i>';
			$label = Widget::Label(__($label_text), null, 'column');
			$label->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][url-parameter]", $values['url-parameter']));
			$group->appendChild($label);

			$li->appendChild($group);

			// width and height for modes 1, 2, 3 and 4
			if ($mode === '1' || $mode === '2' || $mode === '3' || $mode === '4') {
				$group = new XMLElement('div');
				$group->setAttribute('class', 'two columns');
				$label = Widget::Label(__('Width'), null, 'column');
				$label->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][width]", $values['width']));
				$group->appendChild($label);
				$label = Widget::Label(__('Height'), null, 'column');
				$label->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][height]", $values['height']));
				$group->appendChild($label);
				$li->appendChild($group);
			}

			// position and background for mode 2 and 3
			if ($mode === '2' || $mode === '3') {
				$group = new XMLElement('div');
				$group->setAttribute('class', 'two columns');
				$label = Widget::Label(__('Position'), null, 'column');
				$label->appendChild(Widget::Select("jit_image_manipulation[recipes][{$position}][position]", $positionOptions));
				$group->appendChild($label);
				$label = Widget::Label(__('Background Color'), null, 'column');
				$label->appendChild(new XMLElement('i', __('Optional')));
				$label->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][background]", $values['background']));
				$group->appendChild($label);
				$li->appendChild($group);
			}

			// regex mode
			if ($mode === 'regex') {
				$label = Widget::Label(__('JIT Parameter'));
				$label->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][jit-parameter]", $values['jit-parameter']));
				$li->appendChild($label);
			}

			// more general settings, except external image for regex mode
			$group = new XMLElement('div');
			$group->setAttribute('class', 'two columns');
			$label = Widget::Label(__('Image quality'), null, 'column');
			$label->appendChild(new XMLElement('i', __('Optional')));
			$label->appendChild(Widget::Input("jit_image_manipulation[recipes][{$position}][quality]", $values['quality']));
			$group->appendChild($label);
			if ($mode !== 'regex') {
				$label = Widget::Label();
				$label->setAttribute('class', 'column justified');
				$hidden = Widget::Input("jit_image_manipulation[recipes][{$position}][external]", '0', 'hidden');
				$input = Widget::Input("jit_image_manipulation[recipes][{$position}][external]", '1', 'checkbox');
				if($values['external'] == '1') $input->setAttribute('checked', 'checked');
				$label->setValue($input->generate() . ' ' . __('External Image'));
				$group->appendChild($hidden);
				$group->appendChild($label);
			}

			$li->appendChild($group);
			
			return $li;
		}
		

	/*-------------------------------------------------------------------------
		Delegates:
	-------------------------------------------------------------------------*/

		public function appendPreferences($context){
			// JavaScript for recipes duplicator
			Symphony::Engine()->Page->addScriptToHead(URL . '/extensions/jit_image_manipulation/assets/jit_image_manipulation.preferences.js', 3134);

			$group = new XMLElement('fieldset');
			$group->setAttribute('class', 'settings');
			$group->appendChild(new XMLElement('legend', __('JIT Image Manipulation')));

			// recipes duplicator
			$group->appendChild(new XMLElement('p', __('Recipes'), array('class' => 'label')));
			$div = new XMLElement('div', null, array('class' => 'frame'));
			$duplicator = new XMLElement('ol');
			$duplicator->setAttribute('class', 'jit-duplicator');
			$duplicator->setAttribute('data-add', __('Add recipe'));
			$duplicator->setAttribute('data-remove', __('Remove recipe'));

			$duplicator->appendChild(self::createRecipeDuplicatorTemplate('0'));
			$duplicator->appendChild(self::createRecipeDuplicatorTemplate('1'));
			$duplicator->appendChild(self::createRecipeDuplicatorTemplate('2'));
			$duplicator->appendChild(self::createRecipeDuplicatorTemplate('3'));
			$duplicator->appendChild(self::createRecipeDuplicatorTemplate('4'));
			$duplicator->appendChild(self::createRecipeDuplicatorTemplate('regex'));

			if(file_exists(WORKSPACE . '/jit-image-manipulation/recipes.php')) include(WORKSPACE . '/jit-image-manipulation/recipes.php');
			if (is_array($recipes) && !empty($recipes)) {
				foreach($recipes as $position => $recipe) {
					$duplicator->appendChild(self::createRecipeDuplicatorTemplate($recipe['mode'], $position, $recipe));
				}
			}

			$div->appendChild($duplicator);
			$group->appendChild($div);

			// checkbox to disable regular rules
			$label = Widget::Label();
			$input = Widget::Input('settings[image][disable_regular_rules]', 'yes', 'checkbox');
			if(Symphony::Configuration()->get('disable_regular_rules', 'image') == 'yes') $input->setAttribute('checked', 'checked');
			$label->setValue($input->generate() . ' ' . __('Disable dynamic URLs and use named recipes only'));

			$group->appendChild($label);

			// textarea for trusted sites
			$label = Widget::Label(__('Trusted Sites'));
			$label->appendChild(Widget::Textarea('jit_image_manipulation[trusted_external_sites]', 5, 50, $this->trusted()));

			$group->appendChild($label);

			$group->appendChild(new XMLElement('p', __('Leave empty to disable external linking. Single rule per line. Add * at end for wild card matching.'), array('class' => 'help')));

			$context['wrapper']->appendChild($group);
		}

		public function __SavePreferences($context){
			if(!isset($context['settings']['image']['disable_regular_rules'])){
				$context['settings']['image']['disable_regular_rules'] = 'no';
			}

			$this->saveTrusted(stripslashes($_POST['jit_image_manipulation']['trusted_external_sites']));
			$this->saveRecipes($_POST['jit_image_manipulation']['recipes']);
		}
	}
