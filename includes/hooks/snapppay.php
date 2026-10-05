<?php
declare(strict_types=1);
if (!defined('WHMCS')) {
    exit('Direct access denied');
}
require_once dirname(__DIR__,2).'/modules/gateways/snapppay/bootstrap.php';

add_hook('AfterCronJob',1,static function (): void {
    try {
        require_once ROOTDIR.'/includes/gatewayfunctions.php';
        require_once ROOTDIR.'/includes/invoicefunctions.php';
        $runtime=new \SnappPay\Runtime(getGatewayVariables('snapppay'));
        foreach ($runtime->store->pending() as $id) {
            try {
                $runtime->service->reconcile((string)$id);
            } catch (\Throwable $e) {
                \SnappPay\Runtime::log($e,'cron_reconcile');
            }
        }
    } catch (\Throwable $e) {
        \SnappPay\Runtime::log($e,'cron');
    }
});

// Stock cart uses gateways; Twenty-One invoices use availableGateways.
// Keep paymentmethods support for custom themes that expose that shape.
$filter=static function (array $vars): array {
    $collections=[];
    foreach (['gateways','availableGateways','paymentmethods'] as $key) {
        if (isset($vars[$key]) && is_array($vars[$key])) {
            $collections[$key]=$vars[$key];
        }
    }
    $isSnappPay=static function ($key,$method): bool {
        return $key==='snapppay' || (is_array($method) && ($method['sysname']??$method['module']??'')==='snapppay');
    };
    $present=false;
    foreach ($collections as $methods) {
        foreach ($methods as $key=>$method) {
            $present=$present || $isSnappPay($key,$method);
        }
    }
    if (!$present) {
        return [];
    }
    unset($GLOBALS['snapppay_offer']);
    try {
        require_once ROOTDIR.'/includes/gatewayfunctions.php';
        $runtime=new \SnappPay\Runtime(getGatewayVariables('snapppay'));
        if (isset($vars['invoiceid'])) {
            $invoice=$runtime->billing->invoice((int)$vars['invoiceid']);
            if ((int)$invoice['userid']!==\SnappPay\Runtime::clientId()) {
                throw new \SnappPay\Failure('access_denied');
            }
            $amount=\SnappPay\Money::rials((string)$invoice['balance'],$invoice['currency']);
        } else {
            $total=$vars['rawtotal']??$vars['total']??null;
            if (is_object($total) && method_exists($total,'toNumeric')) {
                $total=$total->toNumeric();
            }
            if (is_float($total)) {
                $total=sprintf('%.2f',$total);
            }
            if (!is_string($total) && !is_int($total)) {
                throw new \SnappPay\Failure('cart_total_unavailable');
            }
            $amount=\SnappPay\Money::rials((string)$total,(string)($vars['currency']['code']??''));
        }
        $offer=$runtime->api->eligible($amount);
        foreach ($collections as &$methods) {
            foreach ($methods as $key=>$method) {
                if ($isSnappPay($key,$method)) {
                    if (!$offer['eligible']) {
                        unset($methods[$key]);
                    } elseif (is_array($method)) {
                        $methods[$key]['name']=\SnappPay\Security::escape($offer['title_message']);
                        $methods[$key]['description']=\SnappPay\Security::escape($offer['description']);
                    } else {
                        $methods[$key]=\SnappPay\Security::escape($offer['title_message']);
                    }
                }
            }
        }
        unset($methods);
        $GLOBALS['snapppay_offer']=$offer;
    } catch (\Throwable $e) {
        foreach ($collections as &$methods) {
            foreach ($methods as $key=>$method) {
                if ($isSnappPay($key,$method)) {
                    unset($methods[$key]);
                }
            }
        }
        unset($methods);
    }
    return $collections;
};
add_hook('ClientAreaPageCart',1,$filter);
add_hook('ClientAreaPageViewInvoice',1,$filter);

add_hook('ClientAreaFooterOutput',1,static function (): string {
    $offer=$GLOBALS['snapppay_offer']??null;
    if (!$offer || !$offer['eligible']) {
        return '';
    }
    $description=json_encode($offer['description'],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR);
    return '<script>(function(){var input=document.querySelector("input[name=paymentmethod][value=snapppay]");if(!input)return;var label=input.closest("label")||document.querySelector("label[for=\""+input.id+"\"]");if(!label||label.querySelector(".sp-offer"))return;var line=document.createElement("small");line.className="sp-offer";line.style.display="block";line.style.whiteSpace="pre-line";line.textContent='.$description.';label.appendChild(line);})();</script>';
});
