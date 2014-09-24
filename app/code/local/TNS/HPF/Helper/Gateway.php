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

class Gateway {
	private $gatewayUrl = "";
	private $profile = "";
	private $apiPassword = "";
	private $lastResponse;
	const RESULT_APPROVED = 1;
	const RESULT_DECLINED = 0;

	function __construct($url, $profile, $apiPassword) {
		// parent::__construct();
		$this->gatewayUrl = $url;
		$this->profile = $profile;
		$this->apiPassword = $apiPassword;
	}
	
	public function executeSubsequentFintransOperation(Mage_Sales_Model_Order $order, $apiOperation, $transId, $amount )
	{
		$orderId = $order->getIncrementId();
		
		$fields = array('order.id' => $orderId,
				'transaction.id' => $transId,
				'transaction.currency' => $order->getOrderCurrencyCode(),
				'transaction.amount' => $this->formatCurrency($order->getOrderCurrency(), $amount)
				);
		return $this->handleResult($apiOperation, $fields, $transId);				
	}
	
	public function createOrder(Mage_Sales_Model_Order $order, $session, $apiOperation, $transId) {
		$billingaddress = $order->getBillingAddress();
		$shippingAddress = $order->getShippingAddress();
	
		$orderId = $order->getIncrementId();
		
		$fields = array(
				'order.id' => $orderId,
				'sourceOfFunds.session' => $session,
				'transaction.id' => $transId,
				'transaction.currency' => $order->getOrderCurrencyCode(),
				'transaction.amount' => $this->formatCurrency($order->getOrderCurrency(), $order->getGrandTotal()),
				'order.itemAmount' => $this->formatCurrency($order->getOrderCurrency(), $order->getSubtotal()),
				'order.taxAmount' => $this->formatCurrency($order->getOrderCurrency(), $order->getTaxAmount()),
				'order.shippingAndHandlingAmount' => $this->formatCurrency($order->getOrderCurrency(), $order->getShippingAmount()),
				'sourceOfFunds.type' => 'CARD'
		);
		if ($billingaddress) {
			$this->addIfNotEmpty($fields, 'billing.address.street', $billingaddress->getStreet1());
			$this->addIfNotEmpty($fields, 'billing.address.street2', $billingaddress->getStreet2());
			$this->addIfNotEmpty($fields, 'billing.address.city', $billingaddress->getCity());
			$this->addIfNotEmpty($fields, 'billing.address.postcodeZip', $billingaddress->getPostcode());
			$this->addIfNotEmpty($fields, 'billing.address.stateProvince', $billingaddress->getRegion());
			$this->addIfNotEmpty($fields, 'billing.address.country', $billingaddress->getCountryModel()
					->getIso3Code());
			$this->addIfNotEmpty($fields, 'billing.phone', $billingaddress->getTelephone());
			$this->addIfNotEmpty($fields, 'customer.email', $billingaddress->getData('email'));
		}
		if ($shippingAddress) {
			$this->addIfNotEmpty($fields, 'shipping.address.street', $shippingAddress->getStreet1());
			$this->addIfNotEmpty($fields, 'shipping.address.street2', $shippingAddress->getStreet2());
			$this->addIfNotEmpty($fields, 'shipping.address.city', $shippingAddress->getCity());
			$this->addIfNotEmpty($fields, 'shipping.address.postcodeZip', $shippingAddress->getPostcode());
			$this->addIfNotEmpty($fields, 'shipping.address.stateProvince', $shippingAddress->getRegion());
			$this->addIfNotEmpty($fields, 'shipping.address.country', $shippingAddress->getCountryModel()
					->getIso3Code());
			$this->addIfNotEmpty($fields, 'shipping.phone', $shippingAddress->getTelephone());
			$this->addIfNotEmpty($fields, 'shipping.firstName', $shippingAddress->getFirstname());
			$this->addIfNotEmpty($fields, 'shipping.lastName', $shippingAddress->getLastname());
		}
		$this->addLineItems($order, $fields);
	
		return $this->handleResult($apiOperation, $fields, $transId);
	}
	
	private function handleResult($apiOperation, $fields, $transId)
	{
		$response = $this->send($apiOperation, $fields);
		
		$extendedResults = $this->getExtendedResults($response);
		
		if ($response['result'] === 'SUCCESS') {
			
			return array(
					'status' => self::RESULT_APPROVED,
					'transaction_id' => $transId,
					'extendedResults' => $extendedResults
			);
		} else if ($response['result'] === 'ERROR') {
			return array(
					'status' => self::RESULT_DECLINED,
					'transaction_id' => $transId,
					'error' => $response['error_explanation']
			);
		} else {
			Mage::log("Api response = " . $response['response_gatewayCode']);
			$persist_fields = array();
			return array(
					'status' => self::RESULT_DECLINED,
					'transaction_id' => $transId,
					'error' => $response['response_gatewayCode'],
					'extendedResults' => $extendedResults
			);
		}
	}
	
	private function getExtendedResults($response) {
		return array(
				'Receipt' => $response['transaction_receipt'],
				'Terminal' => $response['transaction_terminal'],
				'Acquirer_Id' => $response['transaction_acquirer_id'],
				'Gateway_Code' => $response['response_gatewayCode'],
				'Acquirer_Response_Code' => $response['response_acquirerCode'],
				'Acquirer_Response_Message' => $response['response_acquirerMessage'],
				'Authorization_Code' => $response['transaction_authorizationCode']
		);
	}
	
	private function addLineItems($order, &$fields) {
		Mage::log("Line items  = " . $order->getAllVisibleItems());
		foreach ( $order->getAllVisibleItems() as $item ) {
			Mage::log("Line item  = " . $item->getName());
		}
	
		$idx = 0;
		foreach ( $order->getAllVisibleItems() as $item ) {
				
			$this->addIfNotEmpty($fields, 'order.item[' . $idx . '].name', $item->getName());
			$this->addIfNotEmpty($fields, 'order.item[' . $idx . '].description', $item->getDescription());
			$this->addIfNotEmpty($fields, 'order.item[' . $idx . '].quantity', intval($item->getQtyOrdered()));
			$this->addIfNotEmpty($fields, 'order.item[' . $idx . '].sku', $item->getSku());
			$this->addIfNotEmpty($fields, 'order.item[' . $idx . '].unitPrice', $this->formatCurrency($order->getOrderCurrency(), $item->getBasePrice()));
			$this->addIfNotEmpty($fields, 'order.item[' . $idx . '].unitTaxAmount', $this->formatCurrency($order->getOrderCurrency(), $item->getBaseTaxAmount()));
			$idx++;
		}
	}
	
	private function addIfNotEmpty(&$fields, $property, $value) {
		if (!empty($value)) {
			$fields[$property] = $value;
		}
	}
	
	private function formatCurrency($currency, $amount) {
		return $currency->formatPrecision($amount, null, array(
				'display' => Zend_Currency::NO_SYMBOL
		), false, false);
	}

	function send($apiOperation, $nvp) {
		$tosend = $nvp;
		$tosend["merchant"] = $this->profile;
		$tosend["apiUsername"] = "merchant." . $this->profile;
		$tosend["apiPassword"] = $this->apiPassword;
		$tosend["apiOperation"] = $apiOperation;
		
		$params = http_build_query($tosend);
		
		Mage::log($params);
		
		$ch = curl_init($this->gatewayUrl);
		curl_setopt($ch, CURLOPT_POST, 1);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		
		$response = array();
		$response_string = curl_exec($ch);
		parse_str($response_string, $response);
		$this->lastResponse = $response;
		
		curl_close($ch);
		
		Mage::log($response_string);
		return $response;
	}

	function lastResponse() {
		return $this->lastResponse;
	}
}
?>
