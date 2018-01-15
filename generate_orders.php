<?php
require_once 'abstract.php';

class Sha_Shell_Order_Generator extends Mage_Shell_Abstract
{

	/* Store stores for fast access */
	protected $stores;

	/* Default value for orders per day */
	protected $ordersPerDay = 1;

	/* Default value for products in order */
	protected $productsPerOrder = 1;

	/*
	 * Build stores array
	 */
	public function _construct()
    {
		$stores = Mage::app()->getStores();
		foreach ($stores as $store) {
			$this->stores[$store->getStoreId()] = $store->getWebsiteId();
		}
    }

	public function createOrder($storeId, $customer, $createdAt) {
		$products = Mage::getModel('catalog/product')->getCollection()
			->addAttributeToSelect('*')
			->addStoreFilter($storeId)
			->addWebsiteFilter($this->stores[$storeId])
			->addFieldToFilter('type_id', array('eq' => 'simple'))
			->joinField(
					'is_in_stock',
					'cataloginventory/stock_item',
					'is_in_stock',
					'product_id=entity_id',
					'{{table}}.stock_id=1',
					'left'
			)
			->addAttributeToFilter('is_in_stock', array('eq' => 1))
			->joinField('qty',
					 'cataloginventory/stock_item',
					 'qty',
					 'product_id=entity_id',
					 '{{table}}.stock_id=1',
					 'left')
			->addAttributeToFilter('qty', array("gt" => 0));
			
		$products->getSelect()->order('RAND()');
		$products->getSelect()->limit($this->productsPerOrder);
		
		if ($products->getSize() < $this->productsPerOrder) {
			return;
		}

		$websiteId = $this->stores[$storeId];

		// Start New Sales Order Quote
		$quote = Mage::getModel('sales/quote')->setStoreId($storeId);

		// Set Sales Order Quote Currency
		$quote->setCurrency($order->AdjustmentAmount->currencyID);

		// Assign Customer To Sales Order Quote
		$quote->assignCustomer($customer);

		// Configure Notification
		$quote->setSendConfirmation(1);
		foreach ($products as $product)
		{
			$product = Mage::getModel('catalog/product')->load($product->getId());
			$quote->addProduct($product, new Varien_Object(array('qty' => 1)));
		}

		// Set Sales Order Billing Address
		$billingAddress = $quote->getBillingAddress()->addData($customer->getPrimaryBillingAddress());

		// Set Sales Order Shipping Address
		$shippingAddress = $quote->getShippingAddress()->addData($customer->getPrimaryShippingAddress());
		
		// Collect Rates and Set Shipping & Payment Method to free shipping and chash on delivery
		$shippingAddress->setCollectShippingRates(true)
				->collectShippingRates()
				->setShippingMethod('freeshipping_freeshipping')
				->setPaymentMethod('checkmo');

		// Set Sales Order Payment
		$quote->getPayment()->importData(array('method' => 'checkmo'));

		// Collect Totals & Save Quote
		$quote->collectTotals()->save();

		try {
			// Create Order From Quote
			$service = Mage::getModel('sales/service_quote', $quote);
			$service->submitAll();
		} catch (Exception $ex) {
			echo $ex->getMessage();
			die;
		} catch (Mage_Core_Exception $e) {
			echo $e->getMessage();
			die;
		}
		
		if (!($incrementId = $service->getOrder()->getRealOrderId())) {
			$incrementId = null;
		}
		
		// Resource Clean-Up
		$quote = $customer = $service = null;

		// Finished
		return $incrementId;
	}

	public function run() {
		$stores = $storeIds = $orderDates = array();
		$from = $to = $ordersPerDay = null;
		
		//Show help
		if ($this->getArg('help')) {
			echo $this->usageHelp();
			die;
		}
		
		//Generate store ID's
		if ($storeIds = $this->getArg('stores')) {
			$storeIds = explode(',', $storeIds);
			foreach($storeIds as $storeId) {
				if (isset($this->stores[$storeId])) {
					$stores[$storeId] = $this->stores[$storeId];
				}
			}
		} else {
			$stores = $this->stores;
		}

		//Generate interval for orders
		if (!$from = $this->getArg('from')) {
			echo $this->usageHelp();
			die;
		}

		if (!$to = $this->getArg('to')) {
			$to = date('d-m-Y', strtotime('TODAY'));
		} else {
			$to = date('m/d/Y', strtotime(trim($to) . '+1 day'));
		}
		
		//Split date ranges to a single dates
		$begin = new DateTime($from);
		$end = new DateTime($to);
		$interval = new DateInterval('P1D');
		$dateRange = new DatePeriod($begin, $interval, $end);
		
		foreach ($dateRange as $date) {
			$orderDates[] = $date->format('j-n-Y');
		}

		//Generate orders per day
		if ($ordersPerDay = $this->getArg('orders_per_day')) {
			$ordersPerDay = (int)$ordersPerDay;
		} else {
			$ordersPerDay = $this->ordersPerDay;
		}

		//Generate products per order
		if ($productsPerOrder = $this->getArg('products_per_order')) {
			$productsPerOrder = (int)$productsPerOrder;
		} else {
			$productsPerOrder = $this->productsPerOrder;
		}
				
		//Get customer, shipping and billing address
		if ($customerEmail = $this->getArg('customer_email')) {
			$customer = Mage::getModel('customer/customer')
				->setWebsiteId(0)
				->loadByEmail($customerEmail);
			if (!$customer->getId()) {
				die('This user does not exist');
			}
		} else {
			die('Please, specify customer email (must be admin)');
		}
		
		foreach ($stores as $storeId => $websiteId) {
			foreach ($orderDates as $orderDate) {
				for ($i = 0; $i<$this->ordersPerDay; $i++) {
					if ($orderId = $this->createOrder($storeId, $customer, $orderDate)) {
						$order = Mage::getModel('sales/order')->load($orderId, 'increment_id');
						$order->setCreatedAt(date('Y-m-d H:i:s', strtotime($orderDate)))->save();
						echo 'Order was created. Increment Id: ' . $orderId . "\n";
					} else {
						echo 'Order creation failed.' . "\n";
						die;
					}
				}
			}
			echo 'Orders for store ' . $storeId . ' was generated' . "\n";
		}
	}

    /**
     * Retrieve Usage Help Message
     *
     */
    public function usageHelp()
    {
        return <<<USAGE

  Usage:  php -f generate_orders.php -- [options]

  customer_email      (required) Customer email. 
  from                (required) Date from (dd-mm-yyyy)

  stores              (optional) Store ID's, separated by comma. Default - all stores
  to                  (optional) Date to (dd-mm-yyyy). Default - today
  orders_per_day      (optional) Number of orders, generated per each day. Default - 1
  products_per_order  (optional) Number of products per orders. Default - 1
  help                This help
  
  Example: php -f generate_orders.php -- --stores 1,2,3 --from 01-01-2017 --to 31-12-2017 --customer_email admin@yoursite.com


USAGE;
    }
}

$shell = new Sha_Shell_Order_Generator();
$shell->run();
