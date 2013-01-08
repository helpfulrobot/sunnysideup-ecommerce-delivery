<?php

/**
 * @author Nicolaas [at] sunnysideup.co.nz
 * @package: ecommerce
 * @sub-package: ecommerce_delivery
 * @description: Shipping calculation scheme based on SimpleShippingModifier.
 * It lets you set fixed shipping costs, or a fixed
 * cost for each region you're delivering to.
 */
class PickUpOrDeliveryModifier extends OrderModifier {

// ######################################## *** model defining static variables (e.g. $db, $has_one)

	public static $db = array(
		"TotalWeight" => "Double",
		"RegionAndCountry" => "Varchar",
		"SerializedCalculationObject" => "Text",
		"DebugString" => "HTMLText",
		"SubTotalAmount" => "Currency"
	);

	public static $has_one = array(
		"Option" => "PickUpOrDeliveryModifierOptions"
	);

	public static $singular_name = "Pickup / Delivery Charge";
		function i18n_singular_name() { return _t("PickUpOrDeliveryModifier.DELIVERYCHARGE", "Delivery / Pick-up Charge");}

	public static $plural_name = "Pickup / Delivery Charges";
		function i18n_plural_name() { return _t("PickUpOrDeliveryModifier.DELIVERYCHARGES", "Delivery / Pick-up Charges");}

	protected static $include_form_in_order_table = true;
		static function set_include_form_in_order_table($b) {self::$include_form_in_order_table = $b;}
		static function get_include_form_in_order_table() {return self::$include_form_in_order_table;}


// ######################################## *** cms variables + functions (e.g. getCMSFields, $searchableFields)

	function getCMSFields() {
		$fields = parent::getCMSFields();
		$fields->replaceField("CountryCode", new DropDownField("CountryCode", self::$field_labels["CountryCode"], Geoip::getCountryDropDown()));
		//debug fields
		$fields->removeByName("TotalWeight");
		$fields->addFieldToTab("Root.Debug", new ReadonlyField("TotalWeightShown", "total weight used for calculation", $this->TotalWeight));
		$fields->removeByName("SubTotalAmount");
		$fields->addFieldToTab("Root.Debug", new ReadonlyField("SubTotalAmountShown", "sub-total amount used for calculation", $this->SubTotalAmount));
		$fields->removeByName("SerializedCalculationObject");
		$fields->addFieldToTab("Root.Debug", new ReadonlyField("SerializedCalculationObjectShown", "debug data", unserialize($this->SerializedCalculationObject)));
		$fields->removeByName("DebugString");
		$fields->addFieldToTab("Root.Debug", new ReadonlyField("DebugStringShown", "steps taken", $this->DebugString));
		return $fields;
	}


// ######################################## *** other (non) static variables (e.g. protected static $special_name_for_something, protected $order)

	/**
	 *@var String $weight_field - the field used in the Buyable to work out the weight.
	 *
	 */
	protected static $weight_field = 'Weight';
		static function set_weight_field($s) {self::$weight_field = $s;}
		static function get_weight_field() {return self::$weight_field;}

	/**
	 * @var Float $total_weight
	 * the total amount of weight for the order
	 * this variable is used for internal purposes only.
	 *
	 */
	protected static $total_weight = null;

	protected static $actual_charges = 0;

	protected static $calculations_done = false;

	protected $debugMessage = "";

// ######################################## *** CRUD functions (e.g. canEdit)

	function canEdit() {
		return true;
	}
// ######################################## *** init and update functions



	public  function setOption($optionID) {
		$optionID = intval($optionID);
		$this->OptionID = $optionID;
		$this->write();
	}
	/**
	 * updates database fields
	 * @param Bool $force - run it, even if it has run already
	 * @return void
	 */
	public function runUpdate($force = true) {
		if (isset($_GET['debug_profile'])) Profiler::mark('PickupOrDeliveryModifier::runUpdate');
		$this->checkField("OptionID");
		$this->checkField("TotalWeight");
		$this->checkField("SubTotalAmount");
		$this->checkField("RegionAndCountry");
		$this->checkField("DebugString");
		if (isset($_GET['debug_profile'])) Profiler::unmark('PickupOrDeliveryModifier::runUpdate');
		parent::runUpdate($force);
	}



// ######################################## *** form functions (e. g. Showform and getform)



	/**
	 * @return Boolean
	 */
	public function ShowForm() {
		return $this->Order()->Items() ? true : false;
	}

	/**
	 * Should the form be included in the editable form
	 * on the checkout page?
	 * @return Boolean
	 */
	public function ShowFormInEditableOrderTable() {
		return ($this->ShowForm() && self::$include_form_in_order_table) ? true : false;
	}

	function getModifierForm($optionalController = null, $optionalValidator = null) {
		Requirements::themedCSS("PickUpOrDeliveryModifier");
		Requirements::javascript(THIRDPARTY_DIR."/jquery/jquery.js");
		//Requirements::block(THIRDPARTY_DIR."/jquery/jquery.js");
		//Requirements::javascript(Director::protocol()."ajax.googleapis.com/ajax/libs/jquery/1.7.2/jquery.min.js");
		Requirements::javascript(THIRDPARTY_DIR."/jquery-form/jquery.form.js");
		Requirements::javascript("ecommerce_delivery/javascript/PickUpOrDeliveryModifier.js");
		$array = PickUpOrDeliveryModifierOptions::get_all_as_country_array();
		if($array && is_array($array) && count($array)) {
			$js = '';
			foreach($array as $key => $option) {
				if($option && is_array($option) && count($option)) {
					$js .= 'PickUpOrDeliveryModifier.addAvailableCountriesItem("'.$key.'",new Array("'.implode('","', $option).'")); ';
				}
			}
			if($js) {
				Requirements::customScript($js, "PickupOrDeliveryModifier");
			}
		}
		$fields = new FieldSet();
		$fields->push($this->headingField());
		$fields->push($this->descriptionField());
		$options = $this->LiveOptions()->map('ID', 'Name');//$this->getOptionListForDropDown();
		$optionID = $this->LiveOptionID();
		$fields->push(new DropdownField('PickupOrDeliveryType', 'Preference', $options, $optionID));
		$actions = new FieldSet(
			new FormAction_WithoutLabel('processOrderModifier', 'Update Pickup / Delivery Option')
		);
		return new PickUpOrDeliveryModifier_Form($optionalController, 'PickUpOrDeliveryModifier', $fields, $actions, $optionalValidator);
	}

	/**
	 * Returns the available delivery options based on the current order country and region settings.
	 * @return DataObjectSet
	 */
	protected function LiveOptions() {
		$countryID = EcommerceCountry::get_country_id();
		$regionID = EcommerceRegion::get_region();
		$weight = $this->LiveTotalWeight();

		$options = DataObject::get('PickUpOrDeliveryModifierOptions');
		if($options) {
			foreach($options as $option) {

				if($countryID) {
					$optionCountries = $option->AvailableInCountries();
					if($optionCountries->Count() > 0 && ! $optionCountries->find('ID', $countryID)) { // Invalid
						continue;
					}
				}

				if($regionID) {
					$optionRegions = $option->AvailableInRegions();
					if($optionRegions->Count() > 0 && ! $optionRegions->find('ID', $regionID)) { // Invalid
						continue;
					}
				}

				$weightBrackets = $option->WeightBrackets();
				if($weightBrackets->Count()) {
					$found = false;
					foreach($weightBrackets as $weightBracket) {
						if($weightBracket->MinimumWeight <= $weight && $weight <= $weightBracket->MaximumWeight) {
							$found = true;
							break;
						}
					}
					if(! $found) {
						continue;
					}
				}

				$result[] = $option;
			}
		}

		if(! isset($result)) {
			$result[] = PickUpOrDeliveryModifierOptions::default_object();
		}

		return new DataObjectSet($result);
	}

// ######################################## *** template functions (e.g. ShowInTable, TableTitle, etc...) ... USES DB VALUES

	public function ShowInTable() {
		return true;
	}
	public function CanBeRemoved() {
		return false;
	}
	/**
	 * NOTE: the function below is  HACK and needs fixing proper.
	 *
	 */

	public function CartValue() {return $this->getCartValue();}
	public function getCartValue() {
		return $this->LiveCalculatedTotal();
	}

// ######################################## ***  inner calculations.... USES CALCULATED VALUES


	protected function LiveOptionObject() {
		return DataObject::get_by_id('PickUpOrDeliveryModifierOptions', $this->LiveOptionID());
	}

// ######################################## *** calculate database fields: protected function Live[field name]  ... USES CALCULATED VALUES

	/**
	 * Precondition : There are always options available.
	 */
	protected function LiveOptionID() {
		$options = $this->LiveOptions();

		if($options->find('ID', $this->OptionID)) {
			return $this->OptionID;
		}

		$option = $options->find('IsDefault', 1);
		if(! $option) {
			$option = $options->First();
		}

		return $option->ID;
	}

	/*protected function LiveOptionID() {
		$optionID = $this->OptionID;
		$defaultOption = null;
		if(!$optionID) {
			$defaultOption = PickUpOrDeliveryModifierOptions::default_object();
			$optionID = $defaultOption->ID;
		}
		if($optionID) {
			$optionArray = $this->getOptionListForDropDown();
			if(is_array($optionArray)) {
				if(!isset($optionArray[$optionID])){
					if(!$defaultOption) {
						$defaultOption = PickUpOrDeliveryModifierOptions::default_object();
					}
					if($defaultOption) {
						if(in_array($defaultOption->ID, $optionArray)) {
							return $defaultOption->ID;
						}
					}
					if(count($optionArray)) {
						foreach($optionArray as $id => $title) {
							return $id;
						}
					}
					$optionID = 0;
				}
			}
		}
		return $optionID;
	}*/

	/**
	*@return string
	**/

	protected function LiveTableValue() {
		return $this->LiveCalculatedTotal();
	}

	protected function LiveName() {
		$obj = $this->LiveOptionObject();
		if(is_object($obj)) {
			$v = $obj->Name;
			if($obj->ExplanationPageID) {
				$page = $obj->ExplanationPage();
				if($page) {
					$v .= '<div id="PickUpOrDeliveryModifierExplanationLink"><a href="'.$page->Link().'" class="externalLink">'.$page->Title.'</a></div>';
				}
			}
			return $v;
		}
		return _t("PickUpOrDeliveryModifier.POSTAGEANDHANDLING", "Postage and Handling");
	}

	protected function LiveSubTotalAmount() {
		$order = $this->Order();
		return $order->SubTotal();
	}

	protected function LiveRegionAndCountry() {
		$details = array();
		$option = $this->Option();
		if($option) {
			$regionID = EcommerceRegion::get_region();
			if($regionID) {
				$region = DataObject::get_one("EcommerceRegion", $regionID);
				if($region) {
					$details[] = $region->Name;
				}
			}
			$countryID = EcommerceCountry::get_country_id();
			if($countryID) {
				$country = DataObject::get_by_id("EcommerceCountry", $countryID);
				if($country) {
					$details[] = $country->Name;
				}
			}
		}
		else {
			return _t("PickUpOrDeliveryModifier.NOTSELECTED", "No delivery option has been selected");
		}
		if(count($details)) {
			return implode(", ", $details);
		}
	}

	/**
	*@return currency
	**/



	protected function LiveCalculatedTotal() {
		$amount = 0;
		$obj = $this->LiveOptionObject();
		self::$actual_charges = 0;
		if($items = $this->Order()->Items()) {
			$amount = $this->LiveSubTotalAmount();
			if(($amount-0) == 0){
				self::$actual_charges = 0;
				$this->debugMessage .= "<hr />sub total amount is 0";
			}
			else {
				if( is_object($obj) && $obj->exists()) {
					// no need to charge, order is big enough
					$this->debugMessage .= "<hr />option selected ".$obj->Title;
					$minForZeroRate = floatval($obj->MinimumOrderAmountForZeroRate);
					if($minForZeroRate > 0 && $minForZeroRate < $amount) {
						self::$actual_charges =  0;
						$this->debugMessage .= "<hr />MinimumOrderAmountForZeroRate: ".$obj->MinimumOrderAmountForZeroRate." is lower than amount ".self::$actual_charges;
					}
					else {
						// add weight based shipping
						$weight = $this->LiveTotalWeight();
						$this->debugMessage .= "<hr />actual weight:".$weight." multiplier = ".$obj->WeightMultiplier." weight unit = ".$obj->WeightUnit." ";
						//legacy fix
						if(!$obj->WeightUnit) { $obj->WeightUnit = 1;}
						if($weight && $obj->WeightMultiplier && $obj->WeightUnit ) {
							$units = ceil($weight / $obj->WeightUnit);
							self::$actual_charges += $units * $obj->WeightMultiplier;
							$this->debugMessage .= "<hr />weight charge: ".self::$actual_charges;
						}
						// add percentage
						if($obj->Percentage) {
							self::$actual_charges += $amount * $obj->Percentage;
							$this->debugMessage .= "<hr />percentage charge: ".$amount * $obj->Percentage;
						}
						// add fixed price
						if($obj->FixedCost) {
							self::$actual_charges += $obj->FixedCost;
							$this->debugMessage .= "<hr />fixed charge: ". $obj->FixedCost;
						}
						//is it enough?
						if(self::$actual_charges < $obj->MinimumDeliveryCharge && $obj->MinimumDeliveryCharge > 0) {
							$oldActualCharge = self::$actual_charges;
							self::$actual_charges = $obj->MinimumDeliveryCharge;
							$this->debugMessage .= "<hr />too little: actual charge: ".$oldActualCharge.", minimum delivery charge: ".$obj->MinimumDeliveryCharge;
						}
						// is it too much
						if(self::$actual_charges > $obj->MaximumDeliveryCharge  && $obj->MaximumDeliveryCharge > 0) {
							self::$actual_charges = $obj->MaximumDeliveryCharge;
							$this->debugMessage .= "<hr />too much".self::$actual_charges;
						}
					}
				}
				else {
					//do nothing
					$this->debugMessage .= "<hr />default";
				}
			}
		}
		else {
			self::$actual_charges = 0;
			$this->debugMessage .= "<hr />no action";
		}
		$this->debugMessage .= "<hr />final score: ".self::$actual_charges;
		if(isset($_GET["debug"])) {
			print_r($this->debugMessage);
		}
		return self::$actual_charges;
	}


	protected function LiveTotalWeight() {
		if(self::get_weight_field()) {
			if(self::$total_weight === null) {
				$items = ShoppingCart::get_items();
				//get index numbers for bonus products - this can only be done now once they have actually been added
				if($items) {
					foreach($items as $itemIndex => $item) {
						if($product = $item->Buyable()) {
							$fieldName = self::get_weight_field();
						// Calculate the total weight of the order
							if(!empty($product->$fieldName) && $item->Quantity) {
								self::$total_weight += intva($product->$fieldName) * $item->Quantity;
							}
							elseif(!$product->Weight)  {
								$this->debugMessage .= "<hr />product without weight: ".$product->Weight;
							}
							elseif(!$item->Quantity) {
								$this->debugMessage .= "<hr />item without uc quanty: ".$item->Quantity;
								if($this->quanty) {
									$this->debugMessage .= "<hr />item does have lc quanty: ".$item->quanty;
								}
							}
						}
					}
				}
			}
		}
		return self::$total_weight;
	}

	protected function LiveDebugString() {
		return $this->debugMessage;
	}


// ######################################## *** Type Functions (IsChargeable, IsDeductable, IsNoChange, IsRemoved)

	public function IsChargeable () {
		return true;
	}

// ######################################## *** standard database related functions (e.g. onBeforeWrite, onAfterWrite, etc...)

	function requireDefaultRecords() {
		parent::requireDefaultRecords();
		// we must check for individual database types here because each deals with schema in a none standard way
		$db = DB::getConn();
		if( $db instanceof PostgreSQLDatabase ){
      $exist = DB::query("SELECT column_name FROM information_schema.columns WHERE table_name ='PickUpOrDeliveryModifier' AND column_name = 'PickupOrDeliveryType'")->numRecords();
		}
		else{
			// default is MySQL - broken for others, each database conn type supported must be checked for!
      $exist = DB::query("SHOW COLUMNS FROM \"PickUpOrDeliveryModifier\" LIKE 'PickupOrDeliveryType'")->numRecords();
		}
 		if($exist > 0) {
 			if($modifiers = DataObject::get('PickUpOrDeliveryModifier')) {
				$defaultOption = DataObject::get_one("PickUpOrDeliveryModifierOptions", "\"IsDefault\" = 1");
				foreach($modifiers as $modifier) {
					if(!isset($modifier->OptionID) || !$modifier->OptionID) {
						$option = DataObject::get_one("PickUpOrDeliveryModifierOptions", "\"Code\" = '".$modifier->Code."'");
						if(!$option) {
							$option = $defaultOption;
						}
						$modifier->OptionID = $option->ID;
						// USING QUERY TO UPDATE
						DB::query("UPDATE \"PickUpOrDeliveryModifier\" SET \"OptionID\" = ".$option->ID." WHERE \"PickUpOrDeliveryModifier\".\"ID\" = ".$modifier->ID);
						DB::alteration_message('Updated modifier from code to option ID', 'edited');
					}
				}
			}
		}
	}

// ######################################## *** AJAX related functions
	/**
	 *
	 * @param Array $js javascript array
	 * @return Array for AJAX JSON
	 **/
	function updateForAjax(array &$js) {
		parent::updateForAjax($js);
		$options = $this->LiveOptions()->map('ID', 'Name');
		foreach($options as $id => $name) {
			$jsonOptions[] = array('id' => $id, 'name' => $name);
		}
		$js[] = array(
			't' => 'dropdown',
			's' => 'PickupOrDeliveryType',
			'p' => $this->LiveOptionID(),
			'v' => $jsonOptions
		);
	}

// ######################################## *** debug functions

}

class PickUpOrDeliveryModifier_Form extends OrderModifierForm {

	function processOrderModifier($data, $form = null) {
		if(isset($data['PickupOrDeliveryType'])) {
			$newOption = intval($data['PickupOrDeliveryType']);
			if(DataObject::get_by_id("PickUpOrDeliveryModifierOptions", $newOption)) {
				$order = ShoppingCart::current_order();
				if($order) {
					if($modifiers = $order->Modifiers("PickUpOrDeliveryModifier")) {
						foreach($modifiers as $modifier) {
							$modifier->setOption($newOption);
							$modifier->runUpdate();
						}
						return ShoppingCart::singleton()->setMessageAndReturn(_t("PickUpOrDeliveryModifier.UPDATED", "Delivery option updated"), "good");
					}
				}
			}
		}
		return ShoppingCart::singleton()->setMessageAndReturn( _t("PickUpOrDeliveryModifier.UPDATED", "Delivery option could NOT be updated"), "bad");
	}

}
