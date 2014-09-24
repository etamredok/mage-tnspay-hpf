<?php
/* Notice of License:
*  The MIT License (MIT)
*
*  Copyright (c) 2014 
*  
*  Permission is hereby granted, free of charge, to any person obtaining a copy
*  of this software and associated documentation files (the "Software"), to deal
*  in the Software without restriction, including without limitation the rights
*  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
*  copies of the Software, and to permit persons to whom the Software is
*  furnished to do so, subject to the following conditions:
*  
*  The above copyright notice and this permission notice shall be included in all
*  copies or substantial portions of the Software.
*  
*  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
*  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
*  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
*  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
*  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
*  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
*  SOFTWARE.
*/

class TNS_HPF_Model_Pay extends Mage_Payment_Model_Method_Cc {
	const ACTION_AUTHORIZE = 'AUTHORIZE';
	const ACTION_PAY = 'PAY';
	protected $_code = 'hpf';
	protected $_formBlockType = 'hpf/form_pay';
	protected $_canAuthorize = true;
	protected $_canCapture = true;
	protected $_canRefund = true;
	protected $_canSaveCc = false; // if true, the actual credit card number and cvv code are stored in database.
	protected $_canUseInternal = true;
	protected $_isGateway = true;

	public function getConfigPaymentAction() {
		if($this->getConfigData('order_operation') == TNS_HPF_Model_Pay::ACTION_AUTHORIZE)
			return Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE;
		else 
			return Mage_Payment_Model_Method_Abstract::ACTION_AUTHORIZE_CAPTURE;
	}
	
	public function createSubsequentTransaction(Varien_Object $payment, $amount, $operation)
	{
		Mage::log($operation);
		$order = $payment->getOrder();
		$originalauth = $payment->getCCTransId();
		$order_id = $order->getIncrementId();
		$transId = time()."-".$operation;
			
		$gateway = Mage::helper('hpf')->getGateway($this->getConfigData('gateway_url') . "/api/nvp/version/17", $this->getConfigData('merchant_id'), $this->getConfigData('integration_password'));
		$result = $gateway->executeSubsequentFintransOperation($payment->getOrder(), $operation, $transId, $amount);
		
		if ($result === false) {
			$errorCode = 'Invalid Data';
			$errorMsg = $this->_getHelper()
			->__('Error Processing the request');
		} else {
			Mage::log($result, null, $this->getCode() . '.log');
			if ($result['status'] == 1) {
				$payment->setIsTransactionClosed(false);
				$payment->setIsTransactionPending(false);
				$payment->setIsTransactionApproved(true);
				$this->setResultData($payment, $result['extendedResults']);
				$order->addStatusToHistory($order->getStatus(), $operation.' Sucessfully Placed with Transaction ID' . $result['transaction_id'], false);
				$order->save();
			} else {
				Mage::throwException($this->_getHelper()
				->__($result['error']));
			}
		}
		if ($errorMsg) {
			Mage::throwException($errorMsg);
		}
		
		return $this;
	}
	public function refund(Varien_Object $payment, $amount) {
		return $this->createSubsequentTransaction($payment, $amount, "REFUND");
	}

	public function capture(Varien_Object $payment, $amount) {
		if($this->getConfigData('order_operation') == TNS_HPF_Model_Pay::ACTION_AUTHORIZE)
			return $this->createSubsequentTransaction($payment, $amount, "CAPTURE");
		else {
			return $this->createInitialTransaction($payment, $amount, TNS_HPF_Model_Pay::ACTION_PAY);
		}
	}
	
	/**
	 * For authorization *
	 */
	private function createInitialTransaction(Varien_Object $payment, $amount, $operation) {
		Mage::log($operation);
		$order = $payment->getOrder();
		$session = Mage::getSingleton('checkout/session');
		
		$order->loadByIncrementId($order->getId());
		
		$transId = time();
		$payment->setTransactionId($transId);
		$payment->setIsTransactionPending(true);
		$order->save();
		
		$gateway = Mage::helper('hpf')->getGateway($this->getConfigData('gateway_url') . "/api/nvp/version/17", $this->getConfigData('merchant_id'), $this->getConfigData('integration_password'));
		$result = $gateway->createOrder($payment->getOrder(), $session->getData("TNSPayToken"), $operation, $transId);
		$errorMsg = '';
		if ($result === false) {
			$errorCode = 'Invalid Data';
			$errorMsg = $this->_getHelper()
			->__('Error Processing the request');
		} else {
			Mage::log($result, null, $this->getCode() . '.log');
			// process result here to check status etc as per payment gateway.
			// if invalid status throw exception
				
			if ($result['status'] == 1) {
				$payment->setIsTransactionClosed(false);
				$payment->setIsTransactionPending(false);
				$payment->setIsTransactionApproved(true);
				$payment->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS, serialize($result['extendedResults']));
				$order->addStatusToHistory($order->getStatus(), $operation.' Sucessfully Placed with Transaction ID' . $result['transaction_id'], false);
				$order->save();
			 
			} else {
				Mage::throwException($this->_getHelper()
				->__($result['error']));
			}
		}
		if ($errorMsg) {
			Mage::throwException($errorMsg);
		}
		
		return $this;
	}
	
	public function authorize(Varien_Object $payment, $amount) {
		return $this->createInitialTransaction($payment, $amount,  $this->getConfigData('order_operation'));
	}

	public function processBeforeRefund($invoice, $payment) {
		return parent::processBeforeRefund($invoice, $payment);
	}

	public function processCreditmemo($creditmemo, $payment) {
		return parent::processCreditmemo($creditmemo, $payment);
	}
	
	public function setResultData($payment, $results) {
		foreach ($results as $k => $v) {
			$payment->setTransactionAdditionalInfo($k,$v);
		}
	}
	

	public function assignData($data) {
		if (!($data instanceof Varien_Object)) {
			$data = new Varien_Object($data);
		}
		$info = $this->getInfoInstance();
		$info->setCcType($data->getCcType())
			->setCcOwner($data->getCcOwner());
		
		$session = Mage::getSingleton('checkout/session');
		$session->setData("TNSPayToken", $data->getHpfSessionId());
		return $this;
	}

	public function validate() {
		/*
		 * calling parent validate function
		 */
		Mage_Payment_Model_Method_Abstract::validate();
		
		$session = Mage::getSingleton('checkout/session');
		
		if (!$session->getData("TNSPayToken")) {
			Mage::throwException("The payment gateway configuration may be incorrect, could not get a HPF session id");
		}
		// This must be after all validation conditions
		if ($this->getIsCentinelValidationEnabled()) {
			$this->getCentinelValidator()
				->validate($this->getCentinelValidationData());
		}
		
		return $this;
	}
}
?>
