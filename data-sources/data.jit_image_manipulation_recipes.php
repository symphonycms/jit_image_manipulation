<?php

	require_once(TOOLKIT . '/class.datasource.php');

	Class datasourcejit_image_manipulation_recipes extends Datasource{

		public $dsParamROOTELEMENT = 'jit-image-manipulation-recipes';

		public function __construct(array $env = null, $process_params=true){
			parent::__construct($env, $process_params);
		}

		public function about(){
			return array(
				'name' => 'JIT Image Manipulation Recipes',
				'version' => '1.16',
				'release-date' => '2012-05-17',
				'author' => array(
					'name' => 'Symphony Team',
					'website' => 'http://getsymphony.com/',
					'email' => 'team@getsymphony.com'
				)
			);
		}

		public function grab(array &$param_pool=NULL){
			$result = new XMLElement($this->dsParamROOTELEMENT);

			if (file_exists(WORKSPACE . '/jit-image-manipulation/recipes.php')) include(WORKSPACE . '/jit-image-manipulation/recipes.php');
			// Add recipes array as XML
			if (is_array($recipes) && !empty($recipes)) {
				foreach($recipes as $position => $recipe) {
					$recipe_xml = new XMLElement('recipe', null, $recipe);
					$result->appendChild($recipe_xml);
				}
			}
			// No recipes set or recipes.php not readable
			else {
				$result = $this->emptyXMLSet();
			}

			return $result;
		}
	}