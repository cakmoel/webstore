<?php

class CheckoutController extends Controller
{
	public $layout;


	/**
	 * Our form model that we save, cache and retrieve
	 *
	 * @var MultiCheckoutForm
	 */

	public $checkoutForm;


	/**
	 * Global errors array to facilitate usage in any function
	 *
	 * @var array
	 */

	public $errors = null;



	public function init()
	{
		parent::init();
	}

	public function beforeAction($action)
	{

		$defaultErrorMsg = Yii::t('cart', 'Oops, you cannot checkout. ');
		$modulesErrors = $this->_checkAllModulesConfigured();
		if (count($modulesErrors) > 0)
		{
			foreach ($modulesErrors as $errorMsg)
			{
				Yii::app()->user->setFlash('error', $defaultErrorMsg . Yii::t('cart', $errorMsg));
			}
			$this->redirect($this->createAbsoluteUrl("/", array(), 'http'));
		}
		// We shouldn't be in this controller if we don't have any products in our cart
		// except if we are viewing the receipt
		if (!Yii::app()->shoppingcart->itemCount && $action->getId() != 'thankyou')
		{
			Yii::log(
				'Attempted to check out with no cart items, Cart ID: ' . Yii::app()->shoppingcart->id,
				'info',
				'application.'.__CLASS__.".".__FUNCTION__
			);

			Yii::app()->user->setFlash('warning', $defaultErrorMsg . Yii::t('cart','You have no items in your cart.'));

			$this->redirect($this->createAbsoluteUrl('site/index', array(), 'http'));
		}

		// Switch domain to secure version if available
		if (Yii::app()->hasCommonSSL && Yii::app()->isCommonSSL === false && $action->getId() !== 'thankyou')
		{
			$this->redirect($this->getCommonSSLRedirectUrl(), true);
			return true;
		}



		$this->widget('ext.wsadvcheckout.wsadvcheckout');

		if ($this->checkoutForm instanceof MultiCheckoutForm === false)
		{
			$this->checkoutForm = MultiCheckoutForm::loadFromSessionOrNew();
		}

		return true;
	}


	/**
	 * Function to test the jumper page view
	 *
	 * @return void
	 */

	public function actionJumpertest()
	{
		$this->layout = '/layouts/checkout-column2';
		$form = '';
		$this->render('jumper', array('form' => $form));
	}


	/**
	 * Checkout as a guest or as an existing user
	 *
	 * @return void
	 */

	public function actionIndex()
	{
		$this->loadForm();

		// did user leave checkout and come back?
		$returnRoute = $this->getCheckoutPoint();
		if (is_null($returnRoute) === false)
		{
			// send user to correct checkout point
			$this->redirect($this->createAbsoluteUrl($returnRoute));
		}

		// if the user is already logged in take them straight to shipping
		if (!Yii::app()->user->isGuest)
		{
			$objCustomer = Customer::GetCurrent();
			$this->checkoutForm->contactEmail = $this->checkoutForm->contactEmail_repeat = $objCustomer->email;
			$this->saveForm();

			// set cart customer if missing
			$objCart = Yii::app()->shoppingcart;
			if ($objCart->customer_id !== $objCustomer->id)
			{
				$objCart->customer_id = $objCustomer->id;
				$objCart->save();
			}

			$this->redirect($this->createAbsoluteUrl('/checkout/shippingaddress'));
		}

		$this->publishJS('index');
		$this->layout = '/layouts/checkout-column2';
		$model = new LoginForm;
		$error = null;

		// collect user input data
		if(isset($_POST['LoginForm']))
		{
			$model->attributes = $_POST['LoginForm'];

			// validate user input and continue if valid
			if ($model->guest == 0)
			{
				$success = $model->validate() && $model->login();
			}
			else
			{
				$model->setScenario('Guest');
				$success = $model->validate();
			}

			if ($success)
			{
				$this->checkoutForm->passedScenario = $model->getScenario();
				$this->checkoutForm->contactEmail = strtolower($model->email);
				$this->checkoutForm->contactEmail_repeat = strtolower($model->email);
				$this->saveForm();

				if ($this->checkoutForm->validate())
					if ($model->guest)
					{
						$this->redirect($this->createAbsoluteUrl('/checkout/shipping'));
					}
					else
					{
						$this->redirect($this->createAbsoluteUrl("/checkout/shippingaddress"));
					}
			}

			$error = $this->formatErrors($model->getErrors());
		}

		$blnShowLogin = false;

		if (isset($_SESSION['checkoutform.cache']))
		{
			$model->email = $_SESSION['checkoutform.cache']['contactEmail'];
		}

		if (isset($_GET['showLogin']))
		{
			$blnShowLogin = $_GET['showLogin'];
		}

		// display the login form
		$this->render('index', array('model' => $model, 'error' => $error, 'blnShowLogin' => $blnShowLogin));
	}

	/**
	 * Display a form for the user to choose Store Pickup or
	 * enter a shipping address and process the input
	 *
	 * @return void
	 */

	public function actionShipping()
	{
		$this->publishJS('shipping');
		$this->layout = '/layouts/checkout';

		$this->loadForm();
		$error = null;

		if (isset($_POST['MultiCheckoutForm']))
		{
			$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];
			$this->checkoutForm->setScenario('Shipping');

			// store pickup checkbox is checked
			if (isset($_POST['storePickupCheckBox']) && $_POST['storePickupCheckBox'] == 1)
			{
				$this->_fillFieldsForStorePickup();
				$this->checkoutForm->setScenario('StorePickup');

				if ($this->checkoutForm->validate() && $this->updateShipping() && $this->updateAddressId())
				{
					// save the passed scenario
					$this->checkoutForm->passedScenario = $this->checkoutForm->getScenario();
					$this->saveForm();

					// Go straight to payment
					$this->redirect($this->createUrl('/checkout/final'));
				}
				else
				{
					$error = $this->formatErrors($this->errors);
				}
			}

			// shipping address is entered
			else
			{
				$arrName = explode(' ', $this->checkoutForm->recipientName);
				$this->checkoutForm->contactFirstName = $this->checkoutForm->shippingFirstName = $arrName[0];
				$this->checkoutForm->contactLastName = $this->checkoutForm->shippingLastName = end($arrName);
				$this->checkoutForm->shippingPostal = strtoupper($this->checkoutForm->shippingPostal);
			}

			// validate before we can progress
			if ($this->checkoutForm->validate())
			{
				$this->saveForm();

				// update the cart
				if ($this->updateAddressId('shipping'))
				{
					// save the passed scenario
					$this->checkoutForm->passedScenario = $this->checkoutForm->getScenario();
					$this->saveForm();

					$this->redirect($this->createUrl('/checkout/shippingoptions'));
				}
				else
				{
					$error = $this->formatErrors($this->errors);
				}

			}

			else
			{
				$error = $this->formatErrors();
			}

		}
		elseif (isset($_GET['address_id']))
		{
			$this->_fetchCustomerShippingAddress($_GET['address_id']);
		}

		// to handle user going back to change input
		if (is_numeric($this->checkoutForm->shippingState))
		{
			$this->checkoutForm->shippingState = State::CodeById($this->checkoutForm->shippingState);
		}

		$this->saveForm();

		$this->render('shipping', array('model' => $this->checkoutForm, 'error' => $error));

	}

	public function actionShippingAddress()
	{
		$this->publishJS('shipping');
		$this->layout = '/layouts/checkout';
		$error = null;

		$this->loadForm();

		$arr = CustomerAddress::getActiveAddresses();

		// if the logged in customer has no addresses saved on file
		// take them to the page where they can enter an address
		if (count($arr) < 1)
		{
			$this->redirect($this->createAbsoluteUrl('/checkout/shipping'));
		}

		$arrFirst = array();
		$objCart = Yii::app()->shoppingcart;

		// if the logged in user has a default shipping address
		// make it appear first
		foreach ($arr as $key => $address)
		{
			if ($address->id == $objCart->customer->default_shipping_id)
			{
				$arrFirst[] = $address;
				unset($arr[$key]);
			}
		}

		$this->checkoutForm->objAddresses = $arrFirst + $arr;

		// populate our form with some default values in case the user
		// was logged in already and bypassed checkout login
		if (!isset($this->checkoutForm->contactEmail))
		{
			$this->checkoutForm->contactEmail = $objCart->customer->email;
		}
		if (!isset($this->checkoutForm->contactEmail_repeat))
		{
			$this->checkoutForm->contactEmail_repeat = $objCart->customer->email;
		}

		if (isset($_POST['MultiCheckoutForm']))
		{
			$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];
		}

		if (isset($_POST['storePickupCheckBox']) && $_POST['storePickupCheckBox'] == 1)
		{
			// store pickup is chosen
			$this->_fillFieldsForStorePickup();
			$this->checkoutForm->setScenario('StorePickup');

			if ($this->checkoutForm->validate() && $this->updateShipping() && $this->updateAddressId())
			{
				// save the passed scenario
				$this->checkoutForm->passedScenario = $this->checkoutForm->getScenario();
				$this->saveForm();

				$this->redirect($this->createUrl('/checkout/final'));
			}
			else
			{
				$error = $this->formatErrors($this->errors);
			}
		}

		elseif (isset($_POST['Address_id']))
		{
			// an existing shipping address is chosen
			$this->_fetchCustomerShippingAddress($_POST['Address_id']);
			$this->checkoutForm->setScenario('Shipping');

			// update cart and validate before we can progress
			if ($this->updateAddressId('shipping') && $this->checkoutForm->validate())
			{
				// save the passed scenario
				$this->checkoutForm->passedScenario = $this->checkoutForm->getScenario();
				$this->saveForm();

				$this->redirect($this->createUrl('/checkout/shippingoptions'));
			}
			else
			{
				$error = $this->formatErrors($this->errors);
			}
		}

		$this->saveForm();
		$this->render('shippingaddress', array('model' => $this->checkoutForm, 'error' => $error));

	}


	/**
	 * Display a radio button list of shipping options
	 * to the end user and process the chosen option
	 *
	 * @return void
	 */

	public function actionShippingOptions()
	{
		$this->publishJS('shipping');
		$this->layout = '/layouts/checkout';
		$this->loadForm();
		$error = null;

		// get the shipping priority
		if (isset($_POST['MultiCheckoutForm']))
		{
			$checkoutForm = $_POST['MultiCheckoutForm'];

			if (isset($checkoutForm['shippingProvider']) && isset($checkoutForm['shippingPriority']))
			{
				$this->checkoutForm->shippingProvider = $checkoutForm['shippingProvider'];
				$this->checkoutForm->shippingPriority = $checkoutForm['shippingPriority'];
				MultiCheckoutForm::saveToSession($this->checkoutForm);
			}

			$this->checkoutForm->setScenario('ShippingOptions');

			// validate before we can progress
			if ($this->checkoutForm->validate())
			{
				if ($this->updateShipping())
				{
					// save the passed scenario
					$this->checkoutForm->passedScenario = $this->checkoutForm->getScenario();
					$this->saveForm();

					$this->redirect($this->createUrl('/checkout/final'));
				}
				else
				{
					$error = $this->formatErrors($this->errors);
				}
			}
			else
			{
				Yii::log(
					print_r($this->checkoutForm->getErrors(), true),
					'error',
					'application.'.__CLASS__.'.'.__FUNCTION__
				);
				$error = $this->formatErrors($this->errors);
			}
		}

		try {
			$arrCartScenario = Shipping::getCartScenarios($this->checkoutForm);
		} catch (Exception $e) {
			Yii::log('Unable to get cart scenarios: ' . $e->getMessage(), 'error', 'application.'.__CLASS__.".".__FUNCTION__);
			$arrCartScenario = null;
		}

		if ($arrCartScenario !== null)
		{
			Shipping::saveCartScenariosToSession($arrCartScenario);
		}

		$this->render(
			'shippingoptions',
			array(
				'model' => $this->checkoutForm,
				'arrCartScenario' => $arrCartScenario,
				'error' => $error
			)
		);
	}


	/**
	 * If a Web Store has no AIM payment methods enabled
	 * this is the action that is executed.
	 *
	 * @return void
	 */

	public function actionPaymentSimple()
	{
		$this->publishJS('payment');
		$this->layout = '/layouts/checkout';
		$this->loadForm();
		$error = null;
		$arrCheckbox = array();
		$objCart = Yii::app()->shoppingcart;

		// handle when a user clicks change billing address on confirmation page
		if (isset($this->checkoutForm->intBillingAddress) && $this->checkoutForm->intBillingAddress !== $this->checkoutForm->intShippingAddress)
		{
			$arrCheckbox = array(
				'id' => $this->checkoutForm->intBillingAddress,
				'name' => 'MultiCheckoutForm[intBillingAddress]',
				'label' => 'Use this as my billing address',
				'address' => $this->checkoutForm->strBillingAddress
			);
		}

		else
		{
			$arrCheckbox = array(
				'id' => 1,
				'name' => 'MultiCheckoutForm[billingSameAsShipping]',
				'label' => 'Use my shipping address as my billing address',
				'address' => $this->checkoutForm->strShippingAddress
			);
		}

		$this->checkoutForm->objAddresses = CustomerAddress::getActiveAddresses();

		// is an existing user choosing a different existing address for billing?
		if (isset($_POST['BillingAddress']) && !isset($_POST['MultiCheckoutForm']['intBillingAddress']))
		{
			$val = $_POST['BillingAddress'];
			if (is_numeric($val))
			{
				if (isset($_POST['MultiCheckoutForm']))
				{
					$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];
				}

				$this->checkoutForm->intBillingAddress = $val;
				$this->checkoutForm->fillAddressFields($val);
				$arrCheckbox['id'] = $val;
				$arrCheckbox['name'] = 'MultiCheckoutForm[intBillingAddress]';
				$arrCheckbox['label'] = 'Use this as my billing address';
				$arrCheckbox['address'] = $this->checkoutForm->strBillingAddress;
			}
		}

		elseif (isset($_POST['Payment']) || isset($_POST['Paypal']))
		{
			//end user is ready for confirmatuon
			if (isset($_POST['MultiCheckoutForm']))
			{
				$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];
				$this->saveForm();

				$simModules = $this->checkoutForm->getSimPaymentModulesNoCard();
				$blnBillAddressHandled = true;

				if (array_key_exists($this->checkoutForm->paymentProvider, $simModules) || isset($_POST['Paypal']))
				{
					// non cc method is chosed
					if (isset($_POST['Paypal']))
					{
						$this->checkoutForm->paymentProvider = $_POST['Paypal'];
					}

					// set scenario
					if ($objCart->shipping->isStorePickup)
					{
						$this->checkoutForm->setScenario('PaymentStorePickup');
					}
					else
					{
						$this->checkoutForm->setScenario('PaymentSim');
						$blnBillAddressHandled = $this->updateAddressId('billing');
					}
				}
				else
				{
					// cc method chosen

					if ($objCart->shipping->isStorePickup === true)
					{
						// ensure the cart gets the correct info when updateaddressid is executed
						$this->checkoutForm->intBillingAddress = null;
						$this->checkoutForm->billingSameAsShipping = null;
						$this->saveForm();
						$this->checkoutForm->setScenario('PaymentStorePickupSimCC');  // billing address required
					}
					else
					{
						$this->checkoutForm->setScenario('PaymentSim');
					}

					$blnBillAddressHandled = $this->updateAddressId('billing');
				}

				// validate form
				if ($blnBillAddressHandled && $this->checkoutForm->validate() && $this->updatePaymentId())
				{
					// save the passed scenario
					$this->checkoutForm->passedScenario = $this->checkoutForm->getScenario();
					$this->saveForm();

					$this->redirect($this->createAbsoluteUrl('/checkout/confirmation'));
				}
				else
				{
					$error = $this->formatErrors($this->errors);
				}
			}
		}

		$this->saveForm();

		if ($this->checkoutForm->objAddresses)
		{
			// we only want this to run the first time the user gets in here
			// we have code to handle if a new address is chosen
			if ($objCart->shipping->isStorePickup === true && isset($_POST['BillingAddress']) === false)
			{
				$arrAddresses = $this->checkoutForm->objAddresses;
				$arrFirst = array();

				$arrCheckbox = array(
					'name' => 'MultiCheckoutForm[intBillingAddress]',
					'label' => 'Use this as my billing address',
				);

				if (isset($objCart->customer->default_billing_id) === true)
				{
					foreach ($arrAddresses as $key => $objAddress)
					{
						if ($objAddress->id === $objCart->customer->default_billing_id)
						{
							$arrFirst[] = $objAddress;
							$arrCheckbox['id'] = $objAddress->id;
							$arrCheckbox['address'] = _xls_string_address($objAddress);
							unset($arrAddresses[$key]);
						}
					}

					$this->checkoutForm->objAddresses = $arrFirst + $arrAddresses;
				}
				else
				{
					// just use the first one
					$arrCheckbox['id'] = $arrAddresses[0]->id;
					$arrCheckbox['address'] = _xls_string_address($arrAddresses[0]);
					$this->checkoutForm->intBillingAddress = $arrAddresses[0]->id;
					$this->checkoutForm->fillAddressFields($arrAddresses[0]->id);
				}

				$this->saveForm();
			}

			$this->render('paymentsimpleaddress', array('model' => $this->checkoutForm, 'checkbox' => $arrCheckbox, 'error' => $error));
		}
		else
		{
			$this->render('paymentsimple', array('model' => $this->checkoutForm, 'error' => $error));
		}
	}


	public function actionPaymentAddress()
	{
		$this->publishJS('payment');
		$this->layout = '/layouts/checkout';

		$this->loadForm();

		$this->checkoutForm->objAddresses = CustomerAddress::getActiveAddresses();

		if (isset($_POST['CheckoutForm']))
		{
			$this->checkoutForm->attributes = $_POST['CheckoutForm'];
		}

		$this->saveForm();

		$this->render('paymentaddress', array('model' => $this->checkoutForm));

	}


	/**
	 * Get payment choice and have end user confirm and place order.
	 * A user can choose a simple integration method which will redirect
	 * them to the confirmation page. If they put their cc details directly
	 * in the form to checkout with an advanced method, the confirmation
	 * page is immediately rendered.
	 *
	 * @return void
	 */

	public function actionFinal()
	{
		$this->loadForm();
		$error = null;
		$arrCheckbox = array(
			'id' => 1,
			'name' => 'MultiCheckoutForm[billingSameAsShipping]',
			'label' => 'Use my shipping address as my billing address',
			'address' => $this->checkoutForm->strShippingAddress
		);

		$objCart = Yii::app()->shoppingcart;

		// check to see if we have any advanced methods and if not redirect to the simple payment action
		$arr = $this->checkoutForm->getPaymentModulesThatUseCard();
		if (count($arr) < 1)
		{
			$this->redirect($this->createAbsoluteUrl('/checkout/paymentsimple'));
		}

		// set cardholder name to default i.e. contact name
		if (!$this->checkoutForm->cardNameOnCard)
		{
			if ($this->checkoutForm->recipientName)
			{
				$this->checkoutForm->cardNameOnCard = $this->checkoutForm->recipientName;
			}
			else
			{
				$this->checkoutForm->cardNameOnCard = $this->checkoutForm->contactFirstName . ' ' . $this->checkoutForm->contactLastName;
			}
		}

		// is an existing user changing their billing address?
		if (isset($_POST['BillingAddress']) && !isset($_POST['MultiCheckoutForm']['intBillingAddress']))
		{
			$val = $_POST['BillingAddress'];
			if (is_numeric($val))
			{
				if (isset($_POST['MultiCheckoutForm']))
				{
					$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];
				}

				$this->checkoutForm->intBillingAddress = $val;
				$this->checkoutForm->fillAddressFields($val);
				$arrCheckbox['id'] = $val;
				$arrCheckbox['name'] = 'MultiCheckoutForm[intBillingAddress]';
				$arrCheckbox['label'] = 'Use this as my billing address';
				$arrCheckbox['address'] = $this->checkoutForm->strBillingAddress;

				$this->layout = '/layouts/checkout';
				$this->render('paymentaddress', array('model' => $this->checkoutForm, 'checkbox' => $arrCheckbox, 'error' => $error));
			}
		}

		// end user is ready for confirmation page
		elseif (isset($_POST['Payment']) || isset($_POST['Paypal']))
		{
			if (isset($_POST['MultiCheckoutForm']))
			{
				$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];

				$simModules = $this->checkoutForm->getSimPaymentModulesNoCard();

				if (array_key_exists($this->checkoutForm->paymentProvider, $simModules) || isset($_POST['Paypal']))
				{
					// user has chosen a SIM method so redirect

					// clear sensitive data just in case
					$this->_clearCCdata();

					$blnBillAddressHandled = true;

					// user chose paypal
					if (isset($_POST['Paypal']))
					{
						$this->checkoutForm->paymentProvider = $_POST['Paypal'];
					}

					$this->saveForm();

					// set scenario
					if ($objCart->shipping->isStorePickup)
					{
						$this->checkoutForm->setScenario('PaymentStorePickup'); // no customer addresses required
					}
					else
					{
						$this->checkoutForm->setScenario('PaymentSim'); // shipping address is required
						$blnBillAddressHandled = $this->updateAddressId('billing'); // set billing address to shipping address to pass validation
					}

					// validate and update payment
					if ($blnBillAddressHandled && $this->checkoutForm->validate() && $this->updatePaymentId())
					{
						// save the passed scenario
						$this->checkoutForm->passedScenario = $this->checkoutForm->getScenario();
						$this->saveForm();

						$this->redirect($this->createAbsoluteUrl('/checkout/confirmation'));
					}
					else
					{
						$error = $this->formatErrors($this->errors);
						$this->layout = '/layouts/checkout';
						$this->render('payment', array('model' => $this->checkoutForm, 'error' => $error));
					}
				}

				// if we are here, the end user has entered their card details directly (AIM)

				// ensure form is populated with billing address
				if (isset($this->checkoutForm->intBillingAddress))
				{
					$this->checkoutForm->fillAddressFields($this->checkoutForm->intBillingAddress);
					$this->checkoutForm->billingSameAsShipping = null;
				}

				// remove whitespace from the cardnumber
				$this->checkoutForm->cardNumber = _xls_number_only($this->checkoutForm->cardNumber);
				$this->checkoutForm->cardNumberLast4 = substr($this->checkoutForm->cardNumber, -4);  // only the last 4 digits

				// to counter code in client side operations
				if ($this->checkoutForm->cardType == 'AMEX')
				{
					$this->checkoutForm->cardType = 'AMERICAN_EXPRESS';
				}

				// prevent an exception if cardExpiry is left blank
				if (isset($this->checkoutForm->cardExpiry) && $this->checkoutForm->cardExpiry !== '')
				{
					$arrCardExpiry = explode('/', $this->checkoutForm->cardExpiry);
					$this->checkoutForm->cardExpiryMonth = $arrCardExpiry[0];
					$this->checkoutForm->cardExpiryYear = $arrCardExpiry[1] + 2000;
				}

				// set scenario
				if ($objCart->shipping->isStorePickup)
				{
					$this->checkoutForm->setScenario('PaymentStorePickupCC'); // only billing address required
				}
				else
				{
					$this->checkoutForm->setScenario('Payment'); // shipping and billing address required
				}

				// validate the form
				if ($this->updateAddressId('billing') && $this->checkoutForm->validate() && $this->updatePaymentId())
				{
					$this->publishJS('confirmation');
					$this->layout = '/layouts/checkout-confirmation';
					$this->render('confirmation', array('model' => $this->checkoutForm, 'cart' => Yii::app()->shoppingcart, 'error' => $error));
				}

				else
				{
					$error = $this->formatErrors($this->errors);

					// clear sensitive data and force user to re-enter them
					$this->_clearCCdata();

					$this->publishJS('payment');
					$this->layout = '/layouts/checkout';

					if (count($this->checkoutForm->objAddresses) > 0)
					{
						$this->render('paymentaddress', array('model' => $this->checkoutForm, 'checkbox' => $arrCheckbox, 'error' => $error));
					}
					else
					{
						$this->render('payment', array('model' => $this->checkoutForm, 'error' => $error));
					}
				}
			}
		}

		// end user has clicked Place Order button on confirmation page
		elseif (isset($_POST['Confirmation']))
		{
			if (isset($_POST['MultiCheckoutForm']))
			{
				$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];

				if ($objCart->shipping->isStorePickup)
				{
					$this->checkoutForm->setScenario('ConfirmationStorePickupCC'); // only billing address required
				}
				else
				{
					$this->checkoutForm->setScenario('Confirmation'); // shipping and billing address required
				}

				// validate form and cart
				if ($this->updateCartCustomerId() && $this->checkoutForm->validate())
				{
					$result = $this->executeCheckoutProcess();
					if (isset($result['success']) && isset($result['cartlink']))
					{
						// send user to receipt
						$this->redirect($this->createAbsoluteUrl("/checkout/thankyou/".$result['cartlink']));
					}
					else
					{
						$error = $this->formatErrors($this->errors);
					}
				}
				else
				{
					$error = $this->formatErrors($this->errors);
				}
			}

			$this->layout = '/layouts/checkout-confirmation';
			$this->render('confirmation', array('model' => $this->checkoutForm, 'cart' => Yii::app()->shoppingcart, 'error' => $error));
		}

		// end user has progressed here after choosing a shipping option or has
		// chosen to change payment details via link on the confirmation page
		else
		{
			$this->layout = '/layouts/checkout';

			// clear sensitive data
			$this->_clearCCdata();

			// existing user with existing addresses
			if (count($this->checkoutForm->objAddresses) > 0)
			{
				$arrCheckbox = array(
					'name' => 'MultiCheckoutForm[intBillingAddress]',
					'label' => 'Use this as my billing address',
				);

				// set the current shipping address to be first in the array
				// so that it becomes the first billing address option
				$arrAddresses = $this->checkoutForm->objAddresses;
				$arrFirst = array();
				$addressIdFirst = null;

				if ($objCart->shipping->isStorePickup === true && isset($_POST['BillingAddress']) === false)
				{
					if (isset($objCart->customer->default_billing_id) === true)
					{
						$addressIdFirst = $objCart->customer->default_billing_id;
					}
					else
					{
						// just use the first one
						$addressIdFirst = $arrAddresses[0]->id;
					}
				}
				else
				{
					$addressIdFirst = $this->checkoutForm->intShippingAddress;
				}

				foreach ($arrAddresses as $key => $objAddress)
				{
					if ($objAddress->id === $addressIdFirst)
					{
						$arrFirst[] = $objAddress;
						$arrCheckbox['id'] = $objAddress->id;
						$arrCheckbox['address'] = _xls_string_address($objAddress);
						unset($arrAddresses[$key]);
					}
				}

				$this->checkoutForm->objAddresses = $arrFirst + $arrAddresses;
				$this->render('paymentaddress', array('model' => $this->checkoutForm, 'checkbox' => $arrCheckbox, 'error' => $error));
			}

			// existing user with no addresses or guest user
			else
			{
				$this->publishJS('payment');
				if (isset($this->checkoutForm->billingState) && is_numeric($this->checkoutForm->billingState))
				{
					$this->checkoutForm->billingState = State::CodeById($this->checkoutForm->billingState);
				}

				$this->render('payment', array('model' => $this->checkoutForm, 'error' => $error));
			}
		}
	}


	public function actionConfirmation()
	{
		// we should only be in here if someone has chosen a SIM
		$this->loadForm();
		$this->layout = '/layouts/checkout-confirmation';
		$this->publishJS('confirmation');
		$objCart = Yii::app()->shoppingcart;
		$error = null;

		if (isset($_POST['MultiCheckoutForm']))
		{
			$this->checkoutForm->attributes = $_POST['MultiCheckoutForm'];

			if ($objCart->shipping->isStorePickup)
			{
				$this->checkoutForm->setScenario('ConfirmationStorePickup');
			}
			else
			{
				$this->checkoutForm->setScenario('ConfirmationSim');
			}

			if ($this->updateCartCustomerId() && $this->checkoutForm->validate())
			{
				$this->executeCheckoutProcessInit();
				$this->runPaymentSim();
			}
			else
			{
				$error = $this->formatErrors($this->errors);
				$this->layout = '/layouts/checkout-confirmation';
				$this->publishJS('confirmation');
				$this->render('confirmation', array('model' => $this->checkoutForm, 'cart' => $objCart, 'error' => $error));
			}
		}

		else
		{
			$orderId = Yii::app()->getRequest()->getQuery('orderId');
			$errorNote = Yii::app()->getRequest()->getQuery('errorNote');

			if (isset($errorNote) && isset($orderId))
			{
				// Cancelled/Declined simple integration transaction
				$objCart = Cart::LoadByIdStr($orderId);
				if (stripos($errorNote, 'cancel') !== false)
				{
					// cancelled
					$translatedErrorNote = Yii::t('checkout', 'You <strong>cancelled</strong> your payment.');
				}
				else
				{
					// declined
					$translatedErrorNote = Yii::t('checkout', 'There was an issue with your payment.') . '<br><br><strong>' . $errorNote . '</strong><br><br>';
					$translatedErrorNote .= Yii::t(
						'checkout',
						'Try re-entering your payment details or contact us at {email} or {phone}',
						array(
							'{email}' => _xls_get_conf('EMAIL_FROM'),
							'{phone}' => _xls_get_conf('STORE_PHONE')
						)
					);
				}

				$arrErrors = array($translatedErrorNote);
				$error = $this->formatErrors($arrErrors);
			}

			$this->render('confirmation', array('model' => $this->checkoutForm, 'cart' => $objCart, 'error' => $error));
		}
	}


	/**
	 * Our new receipt page action.
	 *
	 * @return void
	 * @throws CHttpException
	 */

	public function actionThankyou()
	{
		$strLink = Yii::app()->getRequest()->getQuery('linkid');

		// redirect to homepage if there is no link id
		if (empty($strLink))
			$this->redirect($this->createAbsoluteUrl("/", array(), 'http'));

		$objCart = Cart::model()->findByAttributes(array('linkid' => $strLink));

		if (!($objCart instanceof Cart))
			throw new CHttpException(404,'The requested page does not exist.');

		// send any emails we may still have
		$this->sendEmails($objCart->id);

		$this->layout = '/layouts/checkout-confirmation';
		$this->render('thankyou', array('cart' => $objCart, 'model' => $this->checkoutForm));
	}


	/**
	 * The new checkout views display errors in a specific way.
	 *
	 * We take the errors that the framework generates,
	 * create an html string for sending to the view.
	 *
	 * @param string[] $arr - array of errors
	 * @return string - html
	 */

	protected function formatErrors($arrErrors = null)
	{
		if ($arrErrors !== null && is_array($arrErrors))
		{
			$arrAllErrors = array($arrErrors) + $this->checkoutForm->getErrors();
		}
		else
		{
			$arrAllErrors = $this->checkoutForm->getErrors();
		}

		// $arrAllErrors is an array of array of errors.
		$strErrors = _xls_convert_errors_display(_xls_convert_errors($arrAllErrors));

		// Format errors for html display.
		$strErrors = str_replace("\n", "<br>", $strErrors);

		// Remove first break from string.
		$strErrors = preg_replace("/<br>/", "", $strErrors, 1);

		return sprintf(
			'<div class="form-error"><p>%s</p></div>',
			$strErrors
		);
	}


	/**
	 * Different actions require various javascript wizardry.
	 * Instead of including this javascript directly in the view, we
	 * save them to exclusive files within the extension and
	 * publish each file only when we actually need it.
	 *
	 * @param $strFileName
	 * @return void
	 */

	protected function publishJS($strFileName)
	{
		$assetsPath = Yii::getPathOfAlias('ext').'/wsadvcheckout/assets';

		if (file_exists($assetsPath.'/'.$strFileName.'.js'))
		{
			$cs = Yii::app()->clientScript;
			$assets = Yii::app()->getAssetManager()->publish($assetsPath);
			$cs->registerScriptFile($assets . '/'.$strFileName.'.js', CClientScript::POS_END);
		}
	}

	/**
	 * TODO: WS-2647 TUC customize the new checkout
	 *
	 * This is the function meant to handle admin panel css config options.
	 * The css is created from various theme admin form variables and published as an asset.
	 *
	 * @return void
	 */

	protected function publishCSS()
	{

	}

	/**
	 * Cache needed information: checkoutform, shipping rates
	 *
	 * @return void
	 */

	protected function saveForm()
	{
		MultiCheckoutForm::saveToSession($this->checkoutForm);
	}


	/**
	 * Get the cached checkoutform in order to pass it to the next view
	 *
	 * @return void
	 */

	protected function loadForm()
	{
		$this->checkoutForm = MultiCheckoutForm::loadFromSessionOrNew();
	}

	/**
	 * Very specific function meant to return a specifically structured array.
	 * When called, it will populate the <option> values in the country dropdown
	 * with a 'code' attribute that will be read by jquery and used in the api
	 * request for the dynamic city and state population feature.
	 *
	 * @return mixed
	 */

	public function getCountryCodes()
	{
		$models = Country::model()->findAll();

		foreach ($models as $model)
			$arr[$model->id] = array('code' => $model->code);

		return $arr;

	}

	/**
	 * Perform the final steps needed to finish an AIM web order
	 *
	 * @return bool
	 */

	public function executeCheckoutProcess()
	{
		$this->executeCheckoutProcessInit();

		$objCart = Yii::app()->shoppingcart;

		// save ids that we need for after finalization
		$cartlink = $objCart->linkid;
		$id = $objCart->id;

		// run payment and finalize order
		if (!$this->runPayment($objCart->payment_id))
			return false;

		// send emails
		$this->sendEmails($id);

		// awesome.
		return array('success' => true, 'cartlink' => $cartlink);
	}


	/**
	 * Perform initial checkout process steps
	 *
	 * @return void
	 */

	protected function executeCheckoutProcessInit()
	{
		Yii::log(
			"All form validation passed, attempting to complete checkout...",
			'info',
			'application.'.__CLASS__.".".__FUNCTION__
		);

		$objCart = Yii::app()->shoppingcart;

		// set the currency
		if (trim($objCart->currency) == '')
			$objCart->currency = _xls_get_conf('CURRENCY_DEFAULT', 'USD');
		$objCart->save();

		// set the web order id
		$objCart->SetIdStr();
		Yii::log("Order assigned ".$objCart->id_str, 'info', 'application.'.__CLASS__.".".__FUNCTION__);

		// set the link id if we need to
		if (is_null($objCart->linkid))
			$objCart->linkid = $objCart->GenerateLink();

		$objCart->save();
	}


	/**
	 * Add/Update cart customer
	 *
	 * @return bool
	 */

	protected function updateCartCustomerId()
	{
		$objCart = Yii::app()->shoppingcart;
		$model = $this->checkoutForm;
		$intCustomerId = $objCart->customer_id;

		if (Yii::app()->user->isGuest && is_null($intCustomerId))
		{
			// Guest - not logged in

			Yii::log(
				"Creating Guest account to complete checkout",
				'info',
				'application.'.__CLASS__.".".__FUNCTION__
			);


			//create a new guest ID
			$identity = new GuestIdentity();
			Yii::app()->user->login($identity, 300);
			$intCustomerId = $identity->getId();
			$objCustomer = Customer::model()->findByPk($intCustomerId);
			$objCustomer->first_name = $model->contactFirstName;
			$objCustomer->last_name = $model->contactLastName;
			$objCustomer->mainphone = $model->contactPhone;
			$objCustomer->email = $model->contactEmail;
			if ($objCart->shipaddress)
			{
				if ($objCart->shipping->isStorePickup)
				{
					$objCustomer->default_shipping_id = null;
				}
				else
				{
					$objCustomer->default_shipping_id = $objCart->shipaddress->id;
				}
			}
			if ($objCart->billaddress)
			{
				$objCustomer->default_billing_id = $objCart->billaddress->id;
			}

			if (!$objCustomer->save())
			{
				Yii::log("Error saving Guest:\n" . print_r($objCustomer->getErrors(), true), 'error', 'application.'.__CLASS__.'.'.__FUNCTION__);
				foreach ($objCustomer->getErrors() as $error)
					$this->errors[] = $error;

				return false;
			}

		}

		elseif (!is_null($intCustomerId))
		{
			$objCustomer = Customer::model()->findByPk($intCustomerId);

			// is this a registered customer or a guest
			if ($objCustomer->record_type == Customer::GUEST)
			{
				// 'logged in' guest
				// update information in case it was changed
				$objCustomer->first_name = $model->contactFirstName;
				$objCustomer->last_name = $model->contactLastName;
				$objCustomer->mainphone = $model->contactPhone;
				$objCustomer->email = $model->contactEmail;
				if ($objCart->shipaddress)
				{
					if ($objCart->shipping->isStorePickup)
					{
						$objCustomer->default_shipping_id = null;
					}
					else
					{
						$objCustomer->default_shipping_id = $objCart->shipaddress->id;
					}
				}
				if ($objCart->billaddress)
				{
					$objCustomer->default_billing_id = $objCart->billaddress->id;
				}

				if (!$objCustomer->save())
				{
					Yii::log("Error updating Guest:\n" . print_r($objCustomer->getErrors(), true), 'error', 'application.'.__CLASS__.'.'.__FUNCTION__);
					foreach ($objCustomer->getErrors() as $error)
						$this->errors[] = $error;

					return false;
				}
			}

		}


		if ($objCart->shipaddress && is_null($objCart->shipaddress->customer_id))
		{
			$objCart->shipaddress->customer_id = $intCustomerId;
			$objCart->shipaddress->save();
		}

		if ($objCart->billaddress && is_null($objCart->billaddress->customer_id))
		{
			$objCart->billaddress->customer_id = $intCustomerId;
			$objCart->billaddress->save();
		}


		$objCart->assignCustomer($intCustomerId);

		return true;

	}


	/**
	 * Update the cart shipping address or billing address.
	 * Create address if required.
	 *
	 * @param string $str
	 * @return bool
	 */

	protected function updateAddressId($str = 'shipping')
	{
		$objAddress = null;
		$intAddressId = null;

		$objCart = Yii::app()->shoppingcart;
		$model = $this->checkoutForm;

		switch ($str)
		{
			case 'shipping':
				if (!is_null($model->intShippingAddress) && $model->intShippingAddress != 0)
				{
					$intAddressId = $model->intShippingAddress;
					$this->checkoutForm->fillAddressFields($intAddressId, 'shipping');
				}

				else
				{
					$objAddress = CustomerAddress::model()->findByPk($objCart->shipaddress_id);

					if (!$objAddress instanceof CustomerAddress)
					{
						Yii::log(
							'Creating new Shipping address',
							'info',
							'application.'.__CLASS__.".".__FUNCTION__
						);

						$objAddress = new CustomerAddress;
					}

					$objAddress->customer_id = $objCart->customer_id;
					$objAddress->address_label = $model->shippingLabel ? $model->shippingLabel : Yii::t('global','Unlabeled Address');
					$objAddress->first_name = $model->shippingFirstName;
					$objAddress->last_name = $model->shippingLastName;
					$objAddress->address1 = $model->shippingAddress1;
					$objAddress->address2 = $model->shippingAddress2;
					$objAddress->city = $model->shippingCity;
					$objAddress->postal = $model->shippingPostal;
					$objAddress->country_id = isset($model->shippingCountry) ? Country::IdByCode($model->shippingCountry) : null;
					$objAddress->state_id = isset($model->shippingCountry) && isset($model->shippingState) ? State::IdByCode($model->shippingState, $objAddress->country_id) : null;
					$objAddress->residential = $model->shippingResidential;
					$objAddress->phone = $model->shippingPhone;
					$objAddress->store_pickup_email = $model->pickupPersonEmail ? $model->pickupPersonEmail : $model->contactEmail;
				}

				break;

			case 'billing':
				if ($model->billingSameAsShipping == 1)
				{
					// we should always have a shipping id before we have a billing id
					$intAddressId = $objCart->shipaddress_id;
				}
				elseif (!is_null($model->intBillingAddress) && $model->intBillingAddress != 0)
				{
					$intAddressId = $model->intBillingAddress;
				}
				else
				{
					$objAddress = CustomerAddress::model()->findByPk($objCart->billaddress_id);

					if (!$objAddress instanceof CustomerAddress)
					{
						Yii::log(
							'Creating new Billing address',
							'info',
							'application.'.__CLASS__.".".__FUNCTION__
						);

						$objAddress = new CustomerAddress;
					}

					$objAddress->customer_id = $objCart->customer_id;
					$objAddress->address_label = $model->billingLabel ? $model->billingLabel : Yii::t('global','Unlabeled Address');
					$objAddress->first_name = isset($model->contactFirstName) ? $model->contactFirstName : $model->shippingFirstName;
					$objAddress->last_name = isset($model->contactLastName) ? $model->contactLastName : $model->shippingLastName;
					$objAddress->address1 = $model->billingAddress1;
					$objAddress->address2 = $model->billingAddress2;
					$objAddress->city = $model->billingCity;
					$objAddress->postal = $model->billingPostal;
					$objAddress->country_id = Country::IdByCode($model->billingCountry);
					$objAddress->state_id = State::IdByCode($model->billingState, $objAddress->country_id);
					$objAddress->residential = $model->billingResidential;
				}

				break;

		}

		if ($objAddress instanceof CustomerAddress)
		{
			if ($this->checkoutForm->scenario === 'StorePickup')
			{
				$objAddress->setScenario('StorePickup');
			}

			if (!$objAddress->save())
			{
				foreach ($objAddress->getErrors() as $error)
					$this->errors[] = $error;

				Yii::log("Error creating $str address\n" . print_r($objAddress->getErrors(),true), 'error', 'application.'.__CLASS__.".".__FUNCTION__);
				return false;
			}

			$intAddressId = $objAddress->id;
		}

		switch ($str)
		{
			case 'shipping':
				$objCart->shipaddress_id = $intAddressId;
				break;

			case 'billing':
				$objCart->billaddress_id = $intAddressId;
				$this->checkoutForm->fillAddressFields($intAddressId);
				break;
		}

		if (!$objCart->save())
			Yii::log("Error saving Cart:\n".print_r($objCart->getErrors()), 'error', 'application.'.__CLASS__.'.'.__FUNCTION__);
		else
		{
			$objCart->UpdateCart();
		}

		return true;
	}


	/**
	 * Update the Cart's Tax Code id
	 *
	 * @return void
	 */

	public function updateTaxCodeId()
	{
		$objCart = Yii::app()->shoppingcart;

		if ($objCart->shipping->isStorePickup)
		{
			if (isset($this->checkoutForm->shippingAddress1))
			{
				// if we are in here, the end user chose store pickup
				// and then changed their minds, went back and entered
				// a shipping address
				$objCart->shipping->shipping_module = null;
				$objCart->shipping->save();
				$this->updateTaxCodeId();
			}
			else
			{
				$objCart->tax_code_id = TaxCode::getDefaultCode();
			}
		}
		else
		{
			Yii::log(
				sprintf(
					"Attempting to match with a defined Destination to Country/State/Postal: %s/%s/%s",
					$this->checkoutForm->shippingCountry,
					$this->checkoutForm->shippingState,
					$this->checkoutForm->shippingPostal
				),
				'info',
				'application.'.__CLASS__.".".__FUNCTION__
			);

			$objDestination = Destination::LoadMatching(
				$this->checkoutForm->shippingCountry,
				$this->checkoutForm->shippingState,
				$this->checkoutForm->shippingPostal
			);

			if ($objDestination instanceof Destination)
			{
				Yii::log('Matched Destination '.$objDestination->id.' tax code '.$objDestination->taxcode, 'info', 'application.'.__CLASS__.'.'.__FUNCTION__);
				$objCart->tax_code_id = $objDestination->taxcode;
			}
			else
			{
				Yii::log('Destination not matched, going with default (Any/Any)', 'info', 'application.'.__CLASS__.'.'.__FUNCTION__);
				$objDestination = Destination::LoadDefault();
				$objCart->tax_code_id = $objDestination->taxcode;
			}
		}
	}
	
	
	/**
	 * Add/Update cart shipping
	 *
	 * @return bool
	 */

	protected function updateShipping()
	{
		$selectedCartScenario = Shipping::getSelectedCartScenarioFromSession();
		if ($selectedCartScenario === null)
		{
			// user did not use estimator

			// prevent an exception in case user chose store pickup
			if (isset($this->checkoutForm->shippingCountry) === false)
			{
				// set to default country
				$this->checkoutForm->shippingCountry = _xls_country();
			}

			$arrCartScenarios = Shipping::getCartScenarios($this->checkoutForm);
			Shipping::saveCartScenariosToSession($arrCartScenarios);
			$selectedCartScenario = Shipping::getSelectedCartScenarioFromSession();

			if ($selectedCartScenario === null)
			{
				Yii::log('Cannot update shipping, no scenario selected', 'error', 'application.'.__CLASS__.".".__FUNCTION__);
				$this->errors[] = Yii::t('cart', 'No shipping option selected.');
				return false;
			}
		}

		Yii::log("Shipping Product " . $selectedCartScenario['shippingProduct'], 'info', 'application.'.__CLASS__.".".__FUNCTION__);

		// If we have a shipping object already, update it, otherwise create it
		if (Yii::app()->shoppingcart->shipping_id !== null)
		{
			$objShipping = CartShipping::model()->findByPk(Yii::app()->shoppingcart->shipping_id);
		}
		else
		{
			$objShipping = new CartShipping;
			if (!$objShipping->save())
			{
				Yii::log(
					"Error saving Cart Shipping:\n" . print_r($objShipping->getErrors()),
					'error',
					'application.'.__CLASS__.'.'.__FUNCTION__.'.'.__LINE__
				);


				foreach ($objShipping->getErrors() as $error)
				{
					$this->errors[] = $error;
				}

				return false;
			}
		}

		// Populate the shipping object with default data.
		$objShipping->shipping_method = $selectedCartScenario['shippingProduct'];
		$objShipping->shipping_module = $selectedCartScenario['module'];
		$objShipping->shipping_data = $selectedCartScenario['shippingLabel'];
		$objShipping->shipping_cost = $selectedCartScenario['shippingPrice'];
		$objShipping->shipping_sell = $selectedCartScenario['shippingPrice'];

		// TODO Store tax on shipping in the $selectedCartScenario object.
		$objShipping->shipping_sell_taxed = $selectedCartScenario['shippingPrice'];
		$objShipping->shipping_taxable = 0;

		if ($objShipping->save() === false)
		{
			Yii::log(
				"Error saving Cart Shipping:\n" . print_r($objShipping->getErrors()),
				'error',
				'application.'.__CLASS__.'.'.__FUNCTION__.'.'.__LINE__
			);

			foreach ($objShipping->getErrors() as $error)
			{
				$this->errors[] = $error;
			}

			return false;
		}

		Yii::app()->shoppingcart->shipping_id = $objShipping->id;
		if (Yii::app()->shoppingcart->save() === false)
		{
			Yii::log(
				"Error saving Cart Cart:\n" . print_r(Yii::app()->shoppingcart->getErrors()),
				'error',
				'application.'.__CLASS__.'.'.__FUNCTION__.'.'.__LINE__
			);

			foreach (Yii::app()->shoppingcart->getErrors() as $error)
			{
				$this->errors[] = $error;
			}

			return false;
		}

		Yii::app()->shoppingcart->Recalculate();
		return true;
	}


	/**
	 * Add/Update Cart Payment
	 *
	 * @return bool
	 */

	protected function updatePaymentId()
	{
		$objCart = Yii::app()->shoppingcart;

		if (!is_null($objCart->payment_id))
			$objPayment = CartPayment::model()->findByPk($objCart->payment_id);
		else
		{
			$objPayment = new CartPayment;
			if (!$objPayment->save())
			{
				Yii::log("Error saving payment:\n" . print_r($objPayment->getErrors(), true), 'error', 'application.'.__CLASS__.'.'.__FUNCTION__);
				foreach($objPayment->getErrors() as $error)
					$this->errors[] = $error;
				return false;
			}
		}

		$objPaymentModule = Modules::model()->findByPk($this->checkoutForm->paymentProvider);

		$objPayment->payment_module = $objPaymentModule->module;
		$objPayment->payment_method = $objPaymentModule->payment_method;

		// prevent an error with card_digits which expects 4 characters exactly or null
		if (Yii::app()->getComponent($objPayment->payment_module)->advancedMode)
		{
			$objPayment->payment_card = strtolower($this->checkoutForm->cardType);
			$objPayment->card_digits = $this->checkoutForm->cardNumberLast4;
		}
		else
		{
			// in the rare case someone enters credit card details, and
			// then goes back and chooses a SIM method, remove irrelevant
			// values from the cart payment
			$objPayment->payment_card = null;
			$objPayment->card_digits = null;
		}

		if (!$objPayment->save())
		{
			Yii::log("Error saving payment:\n" . print_r($objPayment->getErrors(), true), 'error', 'application.'.__CLASS__.'.'.__FUNCTION__);
			foreach($objPayment->getErrors() as $error)
				$this->errors[] = $error;
			return false;
		}

		$objCart->payment_id = $objPayment->id;
		if (!$objCart->save())
		{
			Yii::log("Error saving Cart:\n" . print_r($objCart->getErrors()), 'error', 'application.'.__CLASS__.'.'.__FUNCTION__);
			foreach ($objCart->getErrors() as $error)
				$this->errors[] = $error;
			return false;
		}

		return true;

	}


	/**
	 * Our AIM payment handling function
	 *
	 * @param $intPaymentId
	 * @return bool
	 */

	protected function runPayment($intPaymentId)
	{
		$objCart = Yii::app()->shoppingcart;
		$objPayment = CartPayment::model()->findByPk($intPaymentId);

		Yii::log("Running payment on ".$objCart->id_str, 'info', 'application.'.__CLASS__.".".__FUNCTION__);

		$arrPaymentResult = Yii::app()->getComponent($objPayment->payment_module)->setCheckoutForm($this->checkoutForm)->run();

		Yii::log("$objPayment->payment_module result:\n".print_r($arrPaymentResult,true), 'info', 'application.'.__CLASS__.'.'.__FUNCTION__);

		return $this->finalizeOrder($arrPaymentResult);
	}


	/**
	 * Our SIM Payment handling function
	 *
	 * @return void
	 * @throws CHttpException
	 */

	public function runPaymentSim()
	{
		Yii::log('Attempting to complete SIM payment... ', 'info', 'application.'.__CLASS__.'.'.__FUNCTION__);

		$objCart = Yii::app()->shoppingcart;
		$objPayment = $objCart->payment;

		if (!$objPayment instanceof CartPayment)
			throw new CHttpException(500, 'Cart Payment missing');

		Yii::log("Running payment on ".$objCart->id_str, 'info', 'application.'.__CLASS__.".".__FUNCTION__);

		// TODO: WS-2750 TUC enter and see purchase order number when paying by purchase order
		//See if we have a subform for our payment module, set that as part of running payment module
//		if(isset($paymentSubformModel))
//			$arrPaymentResult = Yii::app()->getComponent($objPayment->module)->setCheckoutForm($this->checkoutForm)->setSubForm($paymentSubformModel)->run();
//		else
		$arrPaymentResult = Yii::app()->getComponent($objPayment->payment_module)->setCheckoutForm($this->checkoutForm)->run();

		Yii::log(print_r($arrPaymentResult,true), 'info', 'application.'.__CLASS__.'.'.__FUNCTION__);

		// SIM Credit Card method with form? Then render it
		if (isset($arrPaymentResult['jump_form']))
		{
			Yii::log("Using jump FORM: $objPayment->payment_module", 'info', 'application.'.__CLASS__.".".__FUNCTION__);

			$objCart->printed_notes .= $this->checkoutForm->orderNotes;
			$objCart->cart_type = CartType::awaitpayment;
			$objCart->status = OrderStatus::AwaitingPayment;
			$objCart->completeUpdatePromoCode();
			$this->layout = '/layouts/checkout-column2';
			Yii::app()->clientScript->registerScript(
				'submit',
				'$(document).ready(function(){
				$("form:first").submit();
				});'
			);
			$this->render('jumper', array('form' => $arrPaymentResult['jump_form']));

		}

		// SIM Credit Card method with url? Then redirect customer to hosted pay page
		elseif (isset($arrPaymentResult['jump_url']) && $arrPaymentResult['jump_url'])
		{
			Yii::log("Using jump URL: $objPayment->payment_module", 'info', 'application.'.__CLASS__.".".__FUNCTION__);

			$objCart->printed_notes .= $this->checkoutForm->orderNotes;
			$objCart->cart_type = CartType::awaitpayment;
			$objCart->save();
			$objCart->completeUpdatePromoCode();
			$this->redirect($arrPaymentResult['jump_url']);
		}

		else
		{
			// If we are this far then we have a no cc SIM method (COD, Phone Order, Check)
			$linkid = $objCart->linkid;
			$this->finalizeOrder($arrPaymentResult);
			$this->redirect($this->createAbsoluteUrl('/checkout/thankyou/' . $linkid));
		}
	}


	/**
	 * Update cart payment with returned results and complete sale or return an error
	 *
	 * @param $arrPaymentResult
	 * @return bool
	 */

	protected function finalizeOrder($arrPaymentResult)
	{
		$objCart = Yii::app()->shoppingcart;
		$objPayment = $objCart->payment;

		$objPayment->payment_data = $arrPaymentResult['result'];
		$objPayment->payment_amount = $arrPaymentResult['amount_paid'];
		$objPayment->datetime_posted =
			isset($retVal['payment_date']) ?
				date("Y-m-d H:i:s",strtotime($retVal['payment_date'])) : new CDbExpression('NOW()');
		$objPayment->save();

		if (isset($arrPaymentResult['success']) && $arrPaymentResult['success'])
		{
			Yii::log("Payment Success! Wrapping up processing", 'info', 'application.'.__CLASS__.".".__FUNCTION__);

			//We have successful payment, so close out the order and show the receipt
			$objCart->printed_notes .= $this->checkoutForm->orderNotes;
			$objCart->completeUpdatePromoCode();
			Checkout::emailReceipts($objCart);
			Checkout::finalizeCheckout($objCart, false, true);
			return true;
		}
		else
		{
			$error = isset($arrPaymentResult['result']) ? $arrPaymentResult['result'] : "UNKNOWN ERROR";
			$this->errors[] = array($error);
			Yii::log("Error executing payment:\n" . $error, 'error', 'application.'.__CLASS__.'.'.__FUNCTION__);
			return false;
		}

	}


	/**
	 * Send any emails that are still pending
	 *
	 * @param $intCartid
	 * @return void
	 */

	protected static function sendEmails($intCartid)
	{
		$objEmails = EmailQueue::model()->findAllByAttributes(array('cart_id' => $intCartid));

		Yii::log(count($objEmails)." emails to be sent", 'info', 'application.'.__CLASS__.".".__FUNCTION__);

		foreach ($objEmails as $objEmail)
			_xls_send_email($objEmail->id, true);
	}

	/**
	 * This method gets the user's shipping address based on the addressId param.
	 * Gives the ability to modify the users shipping information for that specific
	 * address. It also modifies the checkoutForm model for that specific user address.
	 *
	 * @param $addressId
	 * @return void
	 */
	private function _fetchCustomerShippingAddress($addressId)
	{
		$selectedAddress = CustomerAddress::model()->findByAttributes(
			array(
				'customer_id' => Yii::app()->user->id,
				'id' => $addressId
			)
		);

		if (!($selectedAddress instanceof CustomerAddress))
			$this->redirect($this->createAbsoluteUrl("/checkout/shippingaddress"));

		$this->checkoutForm->intShippingAddress = $selectedAddress->id;
		$this->checkoutForm->intBillingAddress = $selectedAddress->id;
		$this->checkoutForm->fillAddressFields($selectedAddress->id, 'shipping');
		if (isset($this->checkoutForm->shippingFirstName))
		{
			$this->checkoutForm->contactFirstName = $this->checkoutForm->shippingFirstName;
		}
		else
		{
			$this->checkoutForm->contactFirstName = $this->checkoutForm->shippingFirstName = $selectedAddress->first_name;
		}

		if (isset($this->checkoutForm->shippingLastName))
		{
			$this->checkoutForm->contactLastName = $this->checkoutForm->shippingLastName;
		}
		else
		{
			$this->checkoutForm->contactLastName = $this->checkoutForm->shippingLastName = $selectedAddress->last_name;
		}
		$this->checkoutForm->recipientName = $this->checkoutForm->shippingFirstName . ' ' . $this->checkoutForm->shippingLastName;
		$this->checkoutForm->pickupPerson = $this->checkoutForm->recipientName;
		$this->saveForm();
	}

	/**
	 * Clear form of sensitive credit card data
	 *
	 * @return void
	 */

	private function _clearCCdata()
	{
		$this->checkoutForm->cardNumber = null;
		$this->checkoutForm->cardNumberLast4 = null;
		$this->checkoutForm->cardCVV = null;
		$this->checkoutForm->cardExpiryMonth = null;
		$this->checkoutForm->cardExpiryYear = null;
		$this->checkoutForm->cardType = null;
		$this->saveForm();
	}

	private function _fillFieldsForStorePickup()
	{
		// clear any existing shipping address info from the form
		$this->checkoutForm->intShippingAddress = null;
		$this->checkoutForm->shippingAddress1 = null;
		$this->checkoutForm->shippingAddress2 = null;
		$this->checkoutForm->shippingCity = null;
		$this->checkoutForm->shippingState = null;
		$this->checkoutForm->shippingCountry = null;
		$this->checkoutForm->shippingPostal = null;

		$this->checkoutForm->recipientName = $this->checkoutForm->pickupPerson;
		$arrName = explode(' ', $this->checkoutForm->pickupPerson);
		$this->checkoutForm->shippingFirstName = $arrName[0];
		$this->checkoutForm->shippingLastName = end($arrName);
		$this->checkoutForm->shippingPhone = $this->checkoutForm->pickupPersonPhone;

		$obj = Modules::LoadByName('storepickup');
		$data = unserialize($obj->configuration);
		$this->checkoutForm->shippingProvider = $obj->id;
		$this->checkoutForm->shippingPriority = $data['offerservices'];

		$this->saveForm();
	}


	/**
	 * Build and return common ssl url
	 *
	 * @return string
	 */

	protected function getCommonSSLRedirectUrl()
	{
		$url = $this->createAbsoluteUrl("commonssl/checkout", array('action' => $this->getAction()->id), 'http');
		$strCustomUrl = Yii::app()->params['LIGHTSPEED_HOSTING_CUSTOM_URL'];
		$strLightSpeedUrl = Yii::app()->params['LIGHTSPEED_HOSTING_LIGHTSPEED_URL'];
		$url = str_replace(
			$strLightSpeedUrl,
			$strCustomUrl,
			$url
		);

		return $url;
	}


	/**
	 * Check if the necessary checkout modules are active.
	 *
	 * @return array $errors - messages of the errors based on the module.
	 */
	private function _checkAllModulesConfigured()
	{
		$errors = array();
		if  (count(Modules::getModulesByCategory(true, 'payment')) === 0)
		{
			array_push($errors, 'There are no active payment methods.');
		}

		if (count(Modules::getModulesByCategory(true, 'shipping')) === 0)
		{
			array_push($errors, 'There are no active shipping methods.');
		}

		if (count(Destination::model()->findAll()) === 0)
		{
			array_push($errors, 'Destinations are not configured.');
		}

		return $errors;
	}

	/**
	 * Formats an array of cart scenarios as required by the shipping options
	 * page on the checkout.
	 * @param array[] $arrCartScenario An array of cart scenarios. @see
	 * Shipping::getCartScenarios.
	 * @return array[] A formatted array of cart scenarios.
	 */
	protected static function formatCartScenarios($arrCartScenario)
	{
		$arrShippingOption = array();
		foreach ($arrCartScenario as $cartScenario)
		{
			// We exclude in store pickup from this list.
			if ($cartScenario['module'] === 'storepickup')
			{
				continue;
			}

			$shippingOptionPriceLabel = sprintf(
				'%s %s',
				$cartScenario['formattedShippingPrice'],
				$cartScenario['shippingLabel']
			);

			$arrShippingOption[] = array(
				'formattedShippingPrice' => $cartScenario['formattedShippingPrice'],
				'formattedCartTotal' => $cartScenario['formattedCartTotal'],
				'module' => $cartScenario['module'],
				'priorityIndex' => $cartScenario['priorityIndex'],
				'priorityLabel' => $cartScenario['priorityLabel'],
				'providerId' => $cartScenario['providerId'],
				'providerLabel' => $cartScenario['providerLabel'],
				'shippingLabel' => $cartScenario['shippingLabel'],
				'shippingOptionPriceLabel' => $shippingOptionPriceLabel,
				'shippingPrice' => $cartScenario['shippingPrice'],
				'shippingProduct' => $cartScenario['shippingProduct']
			);
		}

		return $arrShippingOption;
	}

	/**
	 * Get the correct route for end user returning
	 * to checkout within same session. We also
	 * re-populate the cart depending on the scenario
	 * in the rare event that Web Store initializes
	 * a new cart.
	 *
	 * @return null|string
	 */

	protected function getCheckoutPoint()
	{
		$route = null;
		$this->checkoutForm->setScenario($this->checkoutForm->passedScenario);

		switch ($this->checkoutForm->passedScenario)
		{
			// we passed Login -> shipping
			case 'Guest':
				$route = '/checkout/shipping';
				break;

			case 'Existing':
				$route = '/checkout/shippingaddress';
				break;

			// we passed Shipping -> shipping options
			case 'Shipping':
				if ($this->checkoutForm->validate())
				{
					$this->updateAddressId('shipping');
					$route = '/checkout/shippingoptions';
				}
				else
					$route = null;
				break;

			// we passed shipping options / store pickup -> payments
			case 'ShippingOptions':
			case 'StorePickup':
				if ($this->checkoutForm->validate())
				{
					$this->updateAddressId('shipping');
					$this->updateShipping();
					$route = '/checkout/final';
				}
				else
					$route = null;
				break;


			// we passed SIM payments -> confirmation
			case 'PaymentStorePickup':
				if ($this->checkoutForm->validate())
				{
					$this->updateAddressId('shipping');
					$this->updateShipping();
					$this->updatePaymentId();
					$route = '/checkout/confirmation';
				}
				else
					$route = null;
				break;

			case 'PaymentSim':
			case 'PaymentSimCC':
			case 'PaymentStorePickupSimCC':
				if ($this->checkoutForm->validate())
				{
					$this->updateAddressId('shipping');
					$this->updateShipping();
					$this->updateAddressId('billing');
					$this->updatePaymentId();
					$route = '/checkout/confirmation';
				}
				else
					$route = null;
				break;

			default:
				// send to index/login
				$route = null;
		}

		return $route;
	}
}