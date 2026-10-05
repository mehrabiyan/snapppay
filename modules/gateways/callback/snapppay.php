<?php
declare(strict_types=1);
require_once dirname(__DIR__,3).'/init.php';
require_once dirname(__DIR__,3).'/includes/gatewayfunctions.php';
require_once dirname(__DIR__,3).'/includes/invoicefunctions.php';
require_once dirname(__DIR__).'/snapppay/bootstrap.php';
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; base-uri 'none'; frame-ancestors 'none'");
try {
    if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {
        http_response_code(405);
        header('Allow: POST');
        throw new \SnappPay\Failure('method_not_allowed');
    }
    if ((int)($_SERVER['CONTENT_LENGTH']??0)>4096) {
        throw new \SnappPay\Failure('callback_too_large');
    }
    $params=getGatewayVariables('snapppay');
    $runtime=new \SnappPay\Runtime($params);
    $row=$runtime->service->verifyCallback(\SnappPay\Security::scalar($_POST,'transactionId',64),\SnappPay\Security::scalar($_POST,'amount',13),\SnappPay\Security::scalar($_POST,'state',6));
    // Public return displays no invoice ID, client identity, amount or token.
    $url=\SnappPay\Runtime::base($params).'clientarea.php?action=invoices';
    echo \SnappPay\View::outcome($row['state'],$url,\SnappPay\View::persian($params));
} catch (\Throwable $e) {
    if (http_response_code()===200) {
        http_response_code(400);
    }
    \SnappPay\Runtime::log($e,'callback');
    echo \SnappPay\View::outcome('review');
}
