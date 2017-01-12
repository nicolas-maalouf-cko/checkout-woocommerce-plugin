<?php
include_once ('class-wc-gateway-checkout-pci-request.php');

/**
 * Class WC_Checkout_Pci_Web_Hook
 */
class WC_Checkout_Pci_Web_Hook extends WC_Checkout_Pci_Request
{
    const EVENT_TYPE_CHARGE_SUCCEEDED   = 'charge.succeeded';
    const EVENT_TYPE_CHARGE_CAPTURED    = 'charge.captured';
    const EVENT_TYPE_CHARGE_REFUNDED    = 'charge.refunded';
    const EVENT_TYPE_CHARGE_VOIDED      = 'charge.voided';

    /**
     * Constructor
     *
     * WC_Checkout_Pci_Web_Hook constructor.
     * @param WC_Checkout_Pci $gateway
     *
     * @version 20160317
     */
    public function __construct(WC_Checkout_Pci $gateway) {
        parent::__construct($gateway);
    }


    public function authorisedOrder($response){
        $orderId        = (string)$response->message->trackId;
        $order          = new WC_Order($orderId);
        $transactionId  = (string)$response->message->id;
        $transactionIndicator = $response->message->transactionIndicator;
        $responseCode = $response->message->responseCode;
        $responseStatus = $response->message->status;

        // transactionIndicator available only in Auth webhook
        if ($transactionIndicator == 2 && !is_null($previousRecurringDate)) {
            if(preg_match('/^1[0-9]+$/', $responseCode) && $responseStatus == 'Authorised' || $responseStatus == 'flagged' ){
                $recurringCountLeft = $response->message->customerPaymentPlans[0]->recurringCountLeft;
                if($recurringCountLeft == 0){
                    $message = 'Webhook received, subscription payment for initial OrderID: ' . $orderId. '. Charge Status :'.$responseStatus.'. Charge ID: '.$transactionId;
                    $order->add_order_note(__($message,  WC_Subscriptions::$text_domain));
                    WC_Subscriptions_Manager::expire_subscriptions_for_order( $order );
                } else {
                    $message = 'Webhook received, subscription payment for initial OrderID: ' . $orderId. '. Charge Status :'.$responseStatus.'. Charge ID: '.$transactionId;
                    $order->add_order_note(__($message, 'woocommerce-subscriptions'));
                    WC_Subscriptions_Manager::process_subscription_payments_on_order($order);
                }
            } else {
                $message = 'Webhook received, subscription payment for initial OrderID: ' . $orderId. '. Charge Status :'.$responseStatus.'. Charge ID: '.$transactionId. '. ResponseCode : '.$responseCode;
                $order->add_order_note(__($message, 'woocommerce-subscriptions'));
                WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);
            }
        }

        return true;
    }

    /**
     * Fail order from web hook
     *
     * @param WC_Order $response
     * @return bool
     *
     * @version 20160902
     */
    public function failOrder($response){
        $orderId        = (string)$response->message->trackId;

        $order = new WC_Order($orderId);

        if(empty($order->post)){
            WC_Checkout_Non_Pci::log('Missing order id : '.$orderId);
            WC_Checkout_Non_Pci::log($response);
            return false;
        }

        $transactionId  = (string)$response->message->id;
        $transactionIndicator = $response->message->transactionIndicator;
        $responseCode = $response->message->responseCode;
        $responseStatus = $response->message->status;
        $responseMessage = $response->message->responseMessage;


        if ($transactionIndicator == 2) {

            $message = 'Webhook received, subscription payment for initial OrderID: ' . $orderId. '. Charge Status :'.$responseStatus.'. Charge ID: '.$transactionId. '. ResponseCode : '.$responseCode.
             ' Response message :'.$responseMessage;

            $order->add_order_note(__($message, 'woocommerce-subscriptions'));
            WC_Subscriptions_Manager::process_subscription_payment_failure_on_order($order);

        }

        return true;

    }

    /**
     * Capture order from web hook
     *
     * @param WC_Order $response
     * @return bool
     *
     * @version 20160321
     */
    public function captureOrder($response) {
        $orderId        = (string)$response->message->trackId;
        $order          = new WC_Order($orderId);
        $transactionId  = (string)$response->message->id;

        if (!is_object($order)) {
            WC_Checkout_Pci::log("Cannot capture an order. Order ID - {$orderId}");
            return false;
        }

        $trackIdList            = get_post_meta($orderId, '_transaction_id');
        $storedTransactionId    = end($trackIdList);

        if ($storedTransactionId === $transactionId) {
            return false;
        }

        update_post_meta($orderId, '_transaction_id', $transactionId);

        $order->add_order_note(__("Checkout.com Capture Charge Approved (Transaction ID - {$transactionId}, Parent ID - {$storedTransactionId})", 'woocommerce-checkout-pci'));

        if (function_exists('WC')) {
            $order->payment_complete();
        } else {
            // Record the sales
            $order->record_product_sales();

            // Increase coupon usage counts
            $order->increase_coupon_usage_counts();

            wp_set_post_terms($order->id, 'processing', 'shop_order_status', false);
            $order->add_order_note(sprintf( __( 'Order status changed from %s to %s.', 'woocommerce' ), __( $order->status, 'woocommerce' ), __('processing', 'woocommerce')));

            do_action('woocommerce_payment_complete', $order->id);

        }

        return true;
    }

    /**
     * Refund order from web hook
     *
     * @param WC_Order $response
     * @return bool
     *
     * @version 20160321
     */
    public function refundOrder($response) {
        $orderId        = (string)$response->message->trackId;
        $order          = new WC_Order($orderId);
        $transactionId  = (string)$response->message->id;

        if (!is_object($order)) {
            WC_Checkout_Pci::log("Cannot capture an order. Order ID - {$orderId}");
            return false;
        }

        $trackIdList            = get_post_meta($orderId, '_transaction_id');
        $storedTransactionId    = end($trackIdList);

        if ($storedTransactionId === $transactionId) {
            return false;
        }

        $Api         = CheckoutApi_Api::getApi(array('mode' => $this->_getEndpointMode()));
        $totalAmount = $order->get_total();

        $amountDecimal = $response->message->value;
        $amount        = $Api->decimalToValue($amountDecimal, $this->getOrderCurrency($order));

        wc_create_refund(array(
            'amount'    => $amount,
            'order_id'  => $orderId
        ));

        update_post_meta($orderId, '_transaction_id', $transactionId);
        $successMessage = __("Checkout.com Refund Charge Approved (Transaction ID - {$transactionId}, Parent ID - {$storedTransactionId})", 'woocommerce-checkout-pci');
        
        if($totalAmount == $amount || $order->get_total_refunded() == $totalAmount){
            $order->update_status('refunded', $successMessage);
        } else {
            $order->add_order_note( sprintf($successMessage) );
        }

        return true;
    }

    /**
     * Void order from web hook
     *
     * @param WC_Order $response
     * @return bool
     *
     * @version 20160321
     */
    public function voidOrder($response) {
        $orderId        = (string)$response->message->trackId;
        $order          = new WC_Order($orderId);
        $transactionId  = (string)$response->message->id;

        if (!is_object($order)) {
            WC_Checkout_Pci::log("Cannot capture an order. Order ID - {$orderId}");
            return false;
        }

        $trackIdList            = get_post_meta($orderId, '_transaction_id');
        $storedTransactionId    = end($trackIdList);

        if ($storedTransactionId === $transactionId) {
            return false;
        }

        update_post_meta($orderId, '_transaction_id', $storedTransactionId);

        $successMessage = __("Checkout.com Void Charge Approved (Transaction ID - {$transactionId}, Parent ID - {$storedTransactionId})", 'woocommerce-checkout-pci');

        if (!$this->getVoidOrderStatus()) {
            $order->add_order_note($successMessage);
        } else {
            $order->update_status('cancelled', $successMessage);
        }

        return true;
    }
}