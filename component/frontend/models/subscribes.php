<?php
/**
 * @package		akeebasubs
 * @copyright	Copyright (c)2010-2011 Nicholas K. Dionysopoulos / AkeebaBackup.com
 * @license		GNU GPLv3 <http://www.gnu.org/licenses/gpl.html> or later
 */

class ComAkeebasubsModelSubscribes extends KModelAbstract
{
	private $european_states = array('AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'ES', 'FI', 'FR', 'GB', 'GR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK');
	private $paymentForm = '';
	
	/**
	 * We cache the results of all time-consuming operations, e.g. vat validation, subscription membership calculation,
	 * tax calculations, etc into this array, saved in the user's session.
	 * @var array
	 */
	private $_cache = array();
	
	public function __construct(KConfig $config)
	{
		parent::__construct($config);

		// Since we have no table per se, we insert state variables to let
		// Koowa handle the automatic filtering for us
		$this->_state
			->insert('id'				, 'int', 0, true)
			->insert('paymentmethod'	, 'cmd')
			->insert('processorkey'		, 'raw')
			->insert('username'			, 'string')
			->insert('password'			, 'raw')
			->insert('password2'		, 'raw')
			->insert('name'				, 'string')
			->insert('email'			, 'email')
			->insert('address1'			, 'string')
			->insert('address2'			, 'string')
			->insert('country'			, 'cmd')
			->insert('state'			, 'cmd')
			->insert('city'				, 'string')
			->insert('zip'				, 'string')
			->insert('isbusiness'		, 'int')
			->insert('businessname'		, 'string')
			->insert('occupation'		, 'string')
			->insert('vatnumber'		, 'cmd')
			->insert('coupon'			, 'string')
			
			->insert('opt'				, 'cmd')
			;
			
		// Load the cache from the session
		$encodedCacheData = KRequest::get('session.akeebasubs.subscribe.validation.cache.data','raw');
		if(!is_null($encodedCacheData)) {
			$this->_cache = json_decode($encodedCacheData, true);
		}
		
		// Load the state from cache, GET or POST variables
		if(!array_key_exists('state',$this->_cache)) {
			$this->_cache['state'] = array(
				'paymentmethod'	=> '',
				'username'		=> '',
				'password'		=> '',
				'password2'		=> '',
				'name'			=> '',
				'email'			=> '',
				'address1'		=> '',
				'address2'		=> '',
				'country'		=> 'XX',
				'state'			=> '',
				'city'			=> '',
				'zip'			=> '',
				'isbusiness'	=> '',
				'businessname'	=> '',
				'occupation'	=> '',
				'vatnumber'		=> '',
				'coupon'		=> ''
			);
		}
		$rawDataCache = $this->_cache['state'];
		$rawDataPost = JRequest::get('POST', 2);
		$rawDataGet = JRequest::get('GET', 2);
		$rawData = array_merge($rawDataCache, $rawDataGet, $rawDataPost);
		$this->_state->setData($rawData);
		
		// Save the new state data in the cache
		$this->_cache['state'] = $this->_state->getData();
		$encodedCacheData = json_encode($this->_cache);
		KRequest::set('session.akeebasubs.subscribe.validation.cache.data',$encodedCacheData);		
	}
	
	/**
	 * Performs a validation
	 */
	public function getValidation()
	{
		$response = new stdClass();
		
		switch($this->_state->opt)
		{
			case 'username':
				$response->validation = $this->_validateUsername();
				break;
				
			default:
				$response->validation = $this->_validateState();
				$response->validation->username = $this->_validateUsername()->username;
				$response->price = $this->_validatePrice();
				break;
		}
		return $response;
	}
	
	/**
	 * Validates the username for uniqueness
	 */
	private function _validateUsername()
	{
		$ret = (object)array('username' => false);
		$username = $this->_state->username;
		if(empty($username)) return $ret;
		$user = JFactory::getUser($username);
		$ret->username = !is_object($user);
		return $ret;
	}
	
	/**
	 * Validates the state data for completeness
	 */
	private function _validateState()
	{
		// 1. Basic checks
		$ret = array(
			'name'			=> !empty($this->_state->name),
			'email'			=> !empty($this->_state->email),
			'address1'		=> !empty($this->_state->address1),
			'country'		=> !empty($this->_state->country),
			'state'			=> !empty($this->_state->state),
			'city'			=> !empty($this->_state->city),
			'zip'			=> !empty($this->_state->zip),
			'businessname'	=> !empty($this->_state->businessname),
			'occupation'	=> !empty($this->_state->occupation),
			'vatnumber'		=> !empty($this->_state->vatnumber),
			'coupon'		=> !empty($this->_state->coupon)
		);
		
		$ret['rawDataForDebug'] = $this->_state->getData();
		
		// 2. Country validation
		if($ret['country']) {
			$dummy = KFactory::get('admin::com.akeebasubs.template.helper.listbox');
			$ret['country'] = array_key_exists($this->_state->country, ComAkeebasubsTemplateHelperListbox::$countries);
		}
		
		// 3. State validation
		if(in_array($this->_state->country,array('US','CA'))) {
			$dummy = KFactory::get('admin::com.akeebasubs.template.helper.listbox');
			$ret['state'] = array_key_exists($this->_state->state, ComAkeebasubsTemplateHelperListbox::$states);
		} else {
			$ret['state'] = true;
		}
		
		// 4. Business validation
		if(!$this->_state->isbusiness) {
			$ret['businessname'] = true;
			$ret['occupation'] = true;
			$ret['vatnumber'] = false;
		} else {
			// Do I have to check the VAT number?
			if(in_array($this->_state->country, $this->european_states)) {
				// Validate VAT number
				$country = ($this->_state->country == 'GR') ? 'EL' : $this->_state->country;
				$vat = trim(strtoupper($this->_state->vatnumber));
				$url = 'http://isvat.appspot.com/'.$country.'/'.$vat.'/';
				
				// Is the validation already cached?
				$key = $country.$vat;
				$ret['vatnumber'] = null;
				if(array_key_exists('vat', $this->_cache)) {
					if(array_key_exists($key, $this->_cache['vat'])) {
						$ret['vatnumber'] = $this->_cache['vat'][$key];
					}
				}				
				
				if(is_null($ret['vatnumber']))
				{
					$res = @file_get_contents($url);
					if($res === false) {
						$ch = curl_init($url);
						url_setopt($ch, CURLOPT_HEADER, 0);
						url_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
						$res = @curl_exec($ch);
					}
	
					if($res !== false) {
						$res = @json_decode($res);
					}
					
					$ret['vatnumber'] = $res === true;
					
					if(!array_key_exists('vat', $this->_cache)) {
						$this->_cache['vat'] = array();
					}
					$this->_cache['vat'][$key] = $ret['vatnumber'];
					$encodedCacheData = json_encode($this->_cache);
					KRequest::set('session.akeebasubs.subscribe.validation.cache.data',$encodedCacheData);
				}
			}
		}
		
		// 5. Coupon validation
		// FIXME No coupon validation is performed!
		$ret['coupon'] = true;
		
		return (object)$ret;
	}
	
	/**
	 * Calculates the level's price applicable to the specific user and the
	 * actual state information
	 */
	private function _validatePrice()
	{
		// Get the default price value
		$level = KFactory::tmp('site::com.akeebasubs.model.levels')
			->id($this->_state->id)
			->getItem();
		$netPrice = (float)$level->price;

		$couponDiscount = 0;
		// TODO Coupon validation
		
		$autoDiscount = 0;
		// TODO Auto-rule validation
		
		$discount = (float)max($couponDiscount, $autoDiscount);
		
		// Get the applicable tax rule
		$taxRule = $this->_getTaxRule();
		
		return (object)array(
			'net'		=> sprintf('%1.02f',$netPrice),
			'discount'	=> sprintf('%1.02f',$discount),
			'taxrate'	=> sprintf('%1.02f',(float)$taxRule->taxrate),
			'tax'		=> sprintf('%1.02f',0.01 * $taxRule->taxrate * ($netPrice - $discount)),
			'gross'		=> sprintf('%1.02f',($netPrice - $discount) + 0.01 * $taxRule->taxrate * ($netPrice - $discount))
		);
	}
	
	/**
	 * Gets the applicable tax rule based on the state variables
	 */
	private function _getTaxRule()
	{
		// Do we have a VIES registered VAT number?
		$validation = $this->_validateState();
		$isVIES = $validation->vatnumber && in_array($this->_state->country, $this->european_states);
		
		// Load the tax rules
		$taxrules = KFactory::tmp('site::com.akeebasubs.model.taxrules')
			->enabled(1)
			->sort('ordering')
			->direction('ASC')
			->limit(0)
			->offset(0)
			->getList();

		$bestTaxRule = (object)array(
			'match'		=> 0,
			'fuzzy'		=> 0,
			'taxrate'	=> 0
		);
			
		foreach($taxrules as $ruleRow)
		{
			// Pre-condition variables
			$rule = (object)$ruleRow->getData();
			
			// For each rule, get the match and fuzziness rating. The best, least fuzzy and last match wins.
			$match = 0;
			$fuzzy = 0;
			
			if(empty($rule->country)) {
				$match++;
				$fuzzy++;
			} elseif($rule->country == $this->_state->country) {
				$match++;
			}
			
			if(empty($rule->state)) {
				$match++;
				$fuzzy++;
			} elseif($rule->state == $this->_state->state) {
				$match++;
			}
			
			if(empty($rule->city)) {
				$match++;
				$fuzzy++;
			} elseif(strtolower(trim($rule->city)) == strtolower(trim($this->_state->city))) {
				$match++;
			}
			
			if( ($rule->vies && $isVIES) || (!$rule->vies && !$isVIES)) {
				$match++;
			}
			
			if(
				($bestTaxRule->match < $match) ||
				( ($bestTaxRule->match == $match) && ($bestTaxRule->fuzzy > $fuzzy) )
			) {
				if($match == 0) continue;
				$bestTaxRule->match = $match;
				$bestTaxRule->fuzzy = $fuzzy;
				$bestTaxRule->taxrate = $rule->taxrate;
				$bestTaxRule->id = $rule->id;
			}
		}
		return $bestTaxRule;
	}
	
	/**
	 * Gets a list of payment plugins and their titles
	 */
	public function getPaymentPlugins()
	{
		jimport('joomla.plugin.helper');
		JPluginHelper::importPlugin('akpayment');
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentGetIdentity');

		return $jResponse; // name, title
	}

	/**
	 * Processes the form data and creates a new subscription
	 */
	public function createNewSubscription()
	{
		// Step #1. Check the validity of the user supplied information
		// ----------------------------------------------------------------------
		$validation = $this->getValidation();
		
		$isValid = true;
		foreach($validation->validation as $key => $validData)
		{
			// An invalid (not VIES registered) VAT number is not a fatal error
			if($key == 'vatnumber') continue;
			
			$isValid = $isValid && $validData;
			if(!$isValid) {
				if($key == 'username') {
					$user = JFactory::getUser();
					if($user->username == $this->_state->username) {
						$isValid = true;
					} else {
						break;
					}
				}
				break;
			}
		}
		
		if(!$isValid) return false;
		
		// Step #2. Check that the payment plugin exists or return false
		// ----------------------------------------------------------------------
		$plugins = $this->getPaymentPlugins();
		$found = false;
		if(!empty($plugins)) {
			foreach($plugins as $plugin) {
				if($plugin->name == $this->_state->paymentmethod) {
					$found = true;
					break;
				}
			}
		}
		if(!$found) return false;
		
		// Step #3. Create a user record if required and send out the email with user information
		// ----------------------------------------------------------------------
		$user = JFactory::getUser();
		if($user->id == 0) {
			// New user
			$params = array(
				'name'			=> $this->_state->name,
				'username'		=> $this->_state->username,
				'email'			=> $this->_state->email,
				'password'		=> $this->_state->password,
				'password2'		=> $this->_state->password2
			);
			
			$acl =& JFactory::getACL();
			
			jimport('joomla.application.component.helper');
			$usersConfig = &JComponentHelper::getParams( 'com_users' );
			$user = JFactory::getUser(0);
			
			$newUsertype = $usersConfig->get( 'new_usertype' );
			if (!$newUsertype) {
				$newUsertype = 'Registered';
			}
			$params['gid'] = $acl->get_group_id( '', $newUsertype, 'ARO' );
			$params['sendEmail'] = 1;
			
			// We always block the user, so that only a successful payment or
			// clicking on the email link activates his account. This is to
			// prevent spam registrations when the subscription form is abused.
			jimport('joomla.user.helper');
			$params['block'] = 1;
			$params['activation'] = JUtility::getHash( JUserHelper::genRandomPassword() );
			
			$userIsSaved = true;
			if (!$user->bind( $params )) {
				JError::raiseWarning('', JText::_( $user->getError())); // ...raise a Warning
    			$userIsSaved = false;
			} elseif (!$user->save()) { // if the user is NOT saved...
			    JError::raiseWarning('', JText::_( $user->getError())); // ...raise a Warning
			    $userIsSaved = false;
			}
			
			if($userIsSaved) {
				// Send out user registration email
				$this->_sendMail($user, $this->_state->password);
			}
		} else {
			// Update existing user's details
			$params = array(
				'name'			=> $this->_state->name,
				'email'			=> $this->_state->email,
				'password'		=> $this->_state->password,
				'password2'		=> $this->_state->password2
			);
			if (!$user->bind( $params, 'usertype' )) {
				JError::raiseError( 500, $user->getError());
			}
			$userIsSaved = $user->save();
		}
		
		if(!$userIsSaved) return false;
		
		// Step #4. Create or add user extra fields
		// ----------------------------------------------------------------------
		// Find an existing record
		$list = KFactory::tmp('site::com.akeebasubs.model.users')
			->user_id($user->id)
			->getList();
		
		if(!count($list)) {
			$id = 0;
		} else {
			$list->rewind();
			$id = $list->current()->id;
		}
		$data = array(
			'id'			=> $id,
			'user_id'		=> $user->id,
			'isbusiness'	=> $this->_state->isbusiness ? 1 : 0,
			'businessname'	=> $this->_state->businessname,
			'occupation'	=> $this->_state->occupation,
			'vatnumber'		=> $this->_state->vatnumber,
			'viesregistered' => $validation->validation->vatnumber,
			'taxauthority'	=> '', // TODO Ask for tax authority
			'address1'		=> $this->_state->address1,
			'address2'		=> $this->_state->address2,
			'city'			=> $this->_state->city,
			'state'			=> $this->_state->state,
			'zip'			=> $this->_state->zip,
			'country'		=> $this->_state->country
		);
		KFactory::tmp('site::com.akeebasubs.model.users')
			->id($id)
			->getItem()
			->setData($data)
			->save();
		
		// Step #5. Check for existing subscription records and calculate the subscription expiration date
		// ----------------------------------------------------------------------
		$subscriptions = KFactory::tmp('site::com.akeebasubs.model.subscriptions')
			->user_id($user->id)
			->level($this->_state->id)
			->getList();
			
		$jNow = new JDate();
		$now = $jNow->toUnix();
		$mNow = $jNow->toMySQL();
		
		if(empty($subscriptions)) {
			$startDate = $now;
		} else {
			$startDate = $now;
			foreach($subscriptions as $row) {
				// Only take into account active subscriptions
				if(!$row->enabled) continue;
				// Calculate the expiration date
				$jDate = new JDate($row->publish_down);
				$expiryDate = $jDate->toUnix();
				// If the subscription expiration date is earlier than today, ignore it
				if($expiryDate < $now) continue;
				// If the previous subscription's expiration date is later than the current start date,
				// update the start date to be one second after that.
				if($expiryDate > $startDate) {
					$startDate = $expiryDate + 1;
				}
			}
		}
		
		// TODO Step #6. Create a new subscription record
		// ----------------------------------------------------------------------
		$level = KFactory::tmp('site::com.akeebasubs.model.levels')
			->id($this->_state->id)
			->getItem();
		$duration = (int)$level->duration * 3600 * 24;
		$endDate = $startDate + $duration;

		$jStartDate = new JDate($startDate);
		$mStartDate = $jStartDate->toMySQL();
		$jEndDate = new JDate($endDate);
		$mEndDate = $jEndDate->toMySQL();
		
		$data = array(
			'id'					=> null,
			'user_id'				=> $user->id,
			'akeebasubs_level_id'	=> $this->_state->id,
			'publish_up'			=> $mStartDate,
			'publish_down'			=> $mEndDate,
			'notes'					=> '',
			'enabled'				=> 0,
			'processor'				=> $this->_state->paymentmethod,
			'processorkey'			=> '',
			'state'					=> 'N',
			'net_amount'			=> $validation->price->net - $validation->price->discount,
			'tax_amount'			=> $validation->price->tax,
			'gross_amount'			=> $validation->price->gross,
			'created_on'			=> $mNow,
			'params'				=> '',
			'contact_flag'			=> 0,
			'first_contact'			=> '0000-00-00 00:00:00',
			'second_contact'		=> '0000-00-00 00:00:00'
		);
		$subscription = KFactory::tmp('site::com.akeebasubs.model.subscriptions')
			->id(0)
			->getItem();
		$subscription->setData($data)->save();

		// TODO Step #7. Hit the coupon code
		// ----------------------------------------------------------------------
		
		// TODO Step #8. If the price is 0, immediately activate the subscription and redirect to thank you page
		// ----------------------------------------------------------------------
		
		// TODO Step #9. Call the specific plugin's onAKPaymentNew() method and get the redirection URL
		// ----------------------------------------------------------------------
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentNew',array(
			$this->_state->paymentmethod,
			$user,
			$level,
			$subscription
		));
		if(empty($jResponse)) return false;
		
		foreach($jResponse as $response) {
			if($response === false) continue;
			
			$this->paymentForm = $response;
		}
		
		// Return true
		// ----------------------------------------------------------------------
		return true;
	}
	
	public function runCallback()
	{
		$rawDataPost = JRequest::get('POST', 2);
		$rawDataGet = JRequest::get('GET', 2);
		$data = array_merge($rawDataGet, $rawDataPost);
		
		$dummy = $this->getPaymentPlugins();
		
		$app = JFactory::getApplication();
		$jResponse = $app->triggerEvent('onAKPaymentCallback',array(
			$this->_state->paymentmethod,
			$data
		));
		if(empty($jResponse)) return false;
		
		$status = false;
		
		foreach($jResponse as $response)
		{
			$status = $status || $response;
		}
		
		return $status;
	}
	
	/**
	 * Get the form set by the active payment plugin
	 */
	public function getForm()
	{
		return $this->paymentForm;
	}
	
	/**
	 * Returns the state data. Magically retrieves cached data.
	 */
	public function getData()
	{
		return $this->_state->getData();
	}
	
	private function _sendMail(&$user, $password)
	{
		$password = preg_replace('/[\x00-\x1F\x7F]/', '', $password); //Disallow control chars in the email
		
		$mainframe = JFactory::getApplication();
		
		$lang = JFactory::getLanguage();
		$lang->load('com_user',JPATH_SITE);

		$db		=& JFactory::getDBO();

		$name 		= $user->get('name');
		$email 		= $user->get('email');
		$username 	= $user->get('username');

		$usersConfig 	= &JComponentHelper::getParams( 'com_users' );
		$sitename 		= $mainframe->getCfg( 'sitename' );
		$useractivation = $usersConfig->get( 'useractivation' );
		$mailfrom 		= $mainframe->getCfg( 'mailfrom' );
		$fromname 		= $mainframe->getCfg( 'fromname' );
		$siteURL		= JURI::base();

		$subject 	= sprintf ( JText::_( 'Account details for' ), $name, $sitename);
		$subject 	= html_entity_decode($subject, ENT_QUOTES);

		$message = sprintf ( JText::_( 'SEND_MSG_ACTIVATE' ), $name, $sitename, $siteURL."index.php?option=com_user&task=activate&activation=".$user->get('activation'), $siteURL, $username, $password);

		$message = html_entity_decode($message, ENT_QUOTES);

		//get all super administrator
		$query = 'SELECT name, email, sendEmail' .
				' FROM #__users' .
				' WHERE LOWER( usertype ) = "super administrator"';
		$db->setQuery( $query );
		$rows = $db->loadObjectList();

		// Send email to user
		if ( ! $mailfrom  || ! $fromname ) {
			$fromname = $rows[0]->name;
			$mailfrom = $rows[0]->email;
		}

		JUtility::sendMail($mailfrom, $fromname, $email, $subject, $message);

		// Send notification to all administrators
		$subject2 = sprintf ( JText::_( 'Account details for' ), $name, $sitename);
		$subject2 = html_entity_decode($subject2, ENT_QUOTES);

		// get superadministrators id
		foreach ( $rows as $row )
		{
			if ($row->sendEmail)
			{
				$message2 = sprintf ( JText::_( 'SEND_MSG_ADMIN' ), $row->name, $sitename, $name, $email, $username);
				$message2 = html_entity_decode($message2, ENT_QUOTES);
				JUtility::sendMail($mailfrom, $fromname, $row->email, $subject2, $message2);
			}
		}
	}	
}