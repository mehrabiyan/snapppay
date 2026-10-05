<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/init.php';
require_once dirname(__DIR__,3).'/includes/gatewayfunctions.php';
require_once dirname(__DIR__,3).'/includes/invoicefunctions.php';
require_once __DIR__.'/bootstrap.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        http_response_code(405);
        header('Allow: POST');
        throw new \SnappPay\Failure('method_not_allowed');
    }
    \SnappPay\Runtime::csrf();
    $client=\SnappPay\Runtime::clientId();
    $invoice=\SnappPay\Security::id(\SnappPay\Security::scalar($_POST,'invoice',10));
    $nonce=\SnappPay\Security::scalar($_POST,'nonce',64);
    $form=$_SESSION['snapppay_forms'][$nonce]??null;
    if (!$form || $form['invoice']!==$invoice || $form['expires']<time()) {
        throw new \SnappPay\Failure('invalid_form');
    }
    $params=getGatewayVariables('snapppay');
    $runtime=new \SnappPay\Runtime($params);
    $base=\SnappPay\Runtime::base($params);
    $row=$runtime->service->start($invoice,$client,\SnappPay\Security::scalar($_POST,'mobile',24),$nonce,$base.'modules/gateways/callback/snapppay.php');
    $url=\SnappPay\Security::url($row['payment_url'],$runtime->config->values['paymentHosts']);
    header('Location: '.$url,true,303);
} catch (\Throwable $e) {
    if (http_response_code()===200) {
        http_response_code(400);
    }
    \SnappPay\Runtime::log($e,'start');
    echo \SnappPay\View::outcome('review');
}
