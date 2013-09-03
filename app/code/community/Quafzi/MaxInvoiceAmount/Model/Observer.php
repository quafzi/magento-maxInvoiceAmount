<?php
/**
 * @category   Sales
 * @package    Quafzi_MaxInvoiceAmount
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 * @author     Thomas Birke <tbirke@netextreme.de>
 */
class Quafzi_MaxInvoiceAmount_Model_Observer
{
    protected function getCountryLimits()
    {
        $config = explode("\n", Mage::getStoreConfig(
            'shipping/option/countriesWithLimitedInvoiceAmounts'
        ));
        $limits = array();
        foreach ($config as $line) {
            $line = trim($line);
            list($countryId, $limit) = explode(':', $line);
            $countryId = trim($countryId);
            $limit     = trim($limit);
            if (2 !== strlen($countryId) || !is_numeric($limit)) {
                continue;
            }
            $limits[$countryId] = (float)$limit;
        }
        return $limits;
    }

    protected function getLimit($countryId)
    {
        $limit = 9999999999;
        $limits = $this->getCountryLimits();
        if (isset($limits[$countryId])) {
            // no limit for this country
            $limit = $limits[$countryId];
        }
        return $limit;
    }

    public function sales_order_shipment_save_before($event)
    {
        $shipment = $event->getShipment();
        if (1 === $shipment->getItemsCollection()->count()) {
            // no splitting possible
            return;
        }
        $countryId = $shipment->getShippingAddress()->getCountryId();
        $limit = $this->getLimit($countryId);
        if ($limit < $this->getShipmentGrandTotal($shipment)) {
            $this->_setValidationFailure(
                Mage::helper('maxinvoiceamount')->__(
                    'For this destination country you have to split your shipping into chunks cheaper than %s.',
                    $limit
                )
            );
        }
    }

    public function getShipmentGrandTotal($shipment)
    {
        $amount = 0;
        foreach ($shipment->getItemsCollection()->getItems() as $item) {
            $basePriceInclTax = (float)$item->getOrderItem()->getBasePriceInclTax();
            $qty              = (float)$item->getQty();
            $amount += $basePriceInclTax * $qty;
        }
        $order = $shipment->getOrder();
        // add shipping amount and maybe some other fees
        $amount += $order->getBaseGrandTotal() - $order->getBaseSubtotal() - $order->getBaseTaxAmount();
        return $amount;
    }

    public function sales_order_invoice_save_before($event)
    {
        $invoice = $event->getInvoice();
        if (1 === $invoice->getItemsCollection()->count()) {
            // no splitting possible
            return;
        }
        $countryId = $invoice->getShippingAddress()->getCountryId();
        $limit = $this->getLimit($countryId);
        if ($limit < $invoice->getBaseGrandTotal()) {
            $this->_setValidationFailure(
                Mage::helper('maxinvoiceamount')->__(
                    'For this destination country you have to split your invoice into chunks cheaper than %s.',
                    $limit
                )
            );
        }
    }

    /**
     * Clear previous success messages and add error message.
     * This needs to be done as in CE 1.6.2.0 a success message
     * is added even before the shipment is actually saved.
     * As session messages are already translated, there is no
     * reliable way to remove only the "shipment has been created"
     * message. Therefore all previous success messages get
     * deleted here.
     *
     * @param string $errorMessage
     */
    protected function _setValidationFailure($errorMessage)
    {
        $this->_validationFails = true;

        /* @var $messageCollection Mage_Core_Model_Message_Collection */
        $messageCollection = Mage::getSingleton('adminhtml/session')
            ->getMessages();

        $messages = $messageCollection->getItemsByType(
            Mage_Core_Model_Message::SUCCESS
        );
        /* @var $message Mage_Core_Model_Message_Abstract */
        foreach ($messages as $message) {
            $messageCollection->deleteMessageByIdentifier(
                $message->getIdentifier()
            );
        }

        // avoid further handling by DHL extension
        Mage::unregister('current_shipment');

        Mage::throwException($errorMessage);
    }
}
