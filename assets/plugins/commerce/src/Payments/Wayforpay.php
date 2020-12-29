<?php

namespace Commerce\Payments;

use Commerce\Carts\OrderCart;
use Commerce\Processors\OrdersProcessor;
use WayForPay\SDK\Collection\ProductCollection;
use WayForPay\SDK\Credential\AccountSecretCredential;
use WayForPay\SDK\Credential\AccountSecretTestCredential;
use WayForPay\SDK\Domain\Product;
use WayForPay\SDK\Domain\TransactionBase;
use WayForPay\SDK\Handler\ServiceUrlHandler;
use WayForPay\SDK\Wizard\PurchaseWizard;

class Wayforpay extends Payment implements \Commerce\Interfaces\Payment
{
    /** @var $commerce \Commerce\Commerce */
    private $commerce;

    /** @var $commerce \DocumentParser */
    protected $modx;
    /**
     * @var AccountSecretCredential
     */
    private $credential;
    /**
     * @var bool
     */
    private $testMode;

    /** @var OrdersProcessor $processor */
    private $processor;


    public function __construct(\DocumentParser $modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->commerce = $modx->commerce;
        $this->modx = $modx;
        $this->lang = $this->commerce->getUserLanguage('wayforpay');

        $this->testMode = intval($this->getSetting('testMode')) === 1;
        $this->processor = $this->modx->commerce->loadProcessor();


        if($this->testMode === true){
            $this->credential  = new AccountSecretTestCredential();
        }
        else{
            $this->credential  = new AccountSecretCredential($this->getSetting('merchantAccount'),$this->getSetting('merchantSecretKey'));;
        }


    }


    public function getPaymentMarkup()
    {
        $order   = $this->processor->getOrder();

        /** @var OrderCart $cart */
        $cart = $this->processor->getCart();
        $items = $cart->getItems();

        $currency  = ci()->currency->getCurrency($order['currency']);
        $payment   = $this->createPayment($order['id'], ci()->currency->convertToDefault($order['amount'], $currency['code']));


        $productCollection = new ProductCollection();
        foreach ($items as $item) {
            $itemPrice = number_format(ci()->currency->convertToDefault($item['price'], $currency['code']), 2, '.', '');

            $productCollection->add(
                new Product($item['name'], $itemPrice, $item['count'])
            );
        }

        $purchaseForm = PurchaseWizard::get($this->credential)
            ->setMerchantTransactionType('SALE')
            ->setOrderReference($payment['hash'])
            ->setAmount(number_format($payment['amount'], 2, '.', ''))
            ->setCurrency(ci()->currency->getDefaultCurrencyCode())
            ->setOrderDate(new \DateTime())
            ->setMerchantDomainName($this->getSetting('merchantDomainName'))
            ->setProducts($productCollection)
            ->setReturnUrl($this->modx->getConfig('site_url') . 'commerce/wayforpay/payment-process/?type=returnUrl')
            ->setServiceUrl($this->modx->getConfig('site_url') . 'commerce/wayforpay/payment-process/?type=serviceUrl')
            ->getForm();


        $view = new \Commerce\Module\Renderer($this->modx, null, [
            'path' => 'assets/plugins/commerce/templates/front/',
        ]);


        return $view->render('payment_form.tpl', [
            'url'  => $purchaseForm->getEndpoint()->getUrl(),
            'data' => array_filter($purchaseForm->getData()),
            'method' => $purchaseForm->getEndpoint()->getMethod(),
        ]);

    }
    public function getRequestPaymentHash()
    {
        if (isset($_REQUEST['paymentHash']) && is_scalar($_REQUEST['paymentHash'])) {
            return $_REQUEST['paymentHash'];
        }

        return null;
    }


    public function handleCallback()
    {

        try {
            $payment = $this->tryProcessedPayment();
            return $this->successResponse($payment);
        } catch (\Exception $e) {
            $this->modx->logEvent(1,3,$e->getMessage(),'WayForPay');
            return $this->errorResponse();
        }
    }
    private function tryProcessedPayment()
    {
        $this->modx->logEvent(1,1,json_encode([
            'request'=>$_REQUEST,
            'input'=>file_get_contents('php://input')
        ]),'Wayforpay');
        $transaction = $this->getVerifiedSuccessTransaction();
        $payment = $this->getPaymentByHash($transaction->getOrderReference());

        if($payment['paid'] !==1){
            $this->processor->processPayment($payment['id'], floatval($transaction->getAmount()));
        }
        return $payment;
    }


    private function getVerifiedSuccessTransaction()
    {
        $handler = new ServiceUrlHandler($this->credential);
        $response = $handler->parseRequestFromGlobals();
        $transaction = $response->getTransaction();

        if($transaction->getStatus() !== TransactionBase::STATUS_APPROVED && $this->testMode === false){
            throw new \Exception('Payment is not approved');
        }

        return $transaction;
    }

    private function getPaymentByHash($paymentHash)
    {
        $payment = $this->processor->loadPaymentByHash($paymentHash);

        if(empty($payment)){
            throw new \Exception("Payment by hash $paymentHash not found");
        }

        return $payment;
    }

    private function successResponse($payment)
    {
        if($_REQUEST['type'] == 'returnUrl'){
            $redirect = MODX_SITE_URL . 'commerce/wayforpay/payment-success?paymentHash=' . $payment['hash'];
            $this->modx->sendRedirect($redirect);
        }
        return false;
    }

    private function errorResponse()
    {
        if($_REQUEST['type'] == 'returnUrl'){
            $this->modx->sendRedirect(MODX_SITE_URL . 'commerce/wayforpay/payment-failed');
        }
        return false;
    }


}
