//<?php
/**
 * Payment WayForPay
 *
 * Wayforpay payments processing
 *
 * @category    plugin
 * @version     0.1
 * @author      dzhuryn
 * @internal    @events OnRegisterPayments
 * @internal    @properties &title=Title;text; &merchantAccount=Merchant Account;text; &merchantSecretKey=Merchant Secret Key;text; &merchantDomainName=Merchant Domain Name;text; &testMode=Test access;list;Yes==1||No==0;1 &debug=Debug;list;Yes==1||No==0;1
 * @internal    @modx_category Commerce
 * @internal    @disabled 0
 * @internal    @installset base
*/

if (empty($modx->commerce) && !defined('COMMERCE_INITIALIZED')) {
    return;
}
/** @var $commerce Commerce\Commerce */
$commerce = $modx->commerce;

switch ($modx->event->name) {
    case 'OnRegisterPayments':
    if (empty($params['title'])) {
        $lang = $commerce->getUserLanguage('wayforpay');
        $params['title'] = $lang['wayforpay.caption'];
    }

    $class = new \Commerce\Payments\Wayforpay($modx, $params);
    $modx->commerce->registerPayment('wayforpay', $params['title'], $class);

    break;
}
