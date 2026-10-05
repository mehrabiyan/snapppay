<?php
declare(strict_types=1);
if (!defined('WHMCS')) {
    exit('Direct access denied');
}
require_once __DIR__.'/snapppay/bootstrap.php';

function snapppay_MetaData(): array
{
    return ['DisplayName'=>'SnappPay BNPL','APIVersion'=>'1.1','DisableLocalCreditCardInput'=>true,'TokenisedStorage'=>false];
}

function snapppay_config(): array
{
    return ['FriendlyName'=>['Type'=>'System','Value'=>'SnappPay BNPL'],
        'environment'=>['FriendlyName'=>'Environment','Type'=>'dropdown','Options'=>'stage,production','Default'=>'stage'],
        'apiUrl'=>['FriendlyName'=>'API origin','Type'=>'text','Size'=>'60','Description'=>'Exact HTTPS origin supplied by SnappPay. No path.'],
        'paymentHosts'=>['FriendlyName'=>'Hosted checkout hosts','Type'=>'text','Size'=>'60','Description'=>'Exact comma-separated hostnames supplied by SnappPay. No wildcards.'],
        'clientId'=>['FriendlyName'=>'Client ID','Type'=>'text','Size'=>'40'],
        'clientSecret'=>['FriendlyName'=>'Client secret','Type'=>'password','Size'=>'40'],
        'username'=>['FriendlyName'=>'API username','Type'=>'text','Size'=>'40'],
        'password'=>['FriendlyName'=>'API password','Type'=>'password','Size'=>'40'],
        'methods'=>['FriendlyName'=>'Payment methods','Type'=>'dropdown','Options'=>[''=>'All enabled methods','INSTALLMENT'=>'Installment','POSTPAID'=>'Postpaid','FINANCING'=>'Financing','INSTALLMENT,FINANCING'=>'Installment + Financing'],'Description'=>'Blank permits all. Forced methods require SnappPay enablement.'],
        'category'=>['FriendlyName'=>'Default category','Type'=>'text','Default'=>'Services'],
        'commission'=>['FriendlyName'=>'Commission type','Type'=>'text','Default'=>'100'],
        'feeType'=>['FriendlyName'=>'Customer fee','Type'=>'dropdown','Options'=>[''=>'No fee','fixed'=>'Fixed amount','percent'=>'Percentage of payable amount'],'Description'=>'Added to the invoice as a separate line while SnappPay is its payment method. Confirm surcharging is permitted by your SnappPay contract.'],
        'feeValue'=>['FriendlyName'=>'Fee value','Type'=>'text','Size'=>'15','Description'=>'Fixed: whole Rials (IRR), also for toman invoices. Percentage: 0–100, up to two decimals, e.g. 2.5'],
        'feeDescription'=>['FriendlyName'=>'Fee invoice line','Type'=>'text','Size'=>'40','Default'=>'SnappPay payment fee','Description'=>'Invoice item description shown to the client.'],
        'allowRevert'=>['FriendlyName'=>'Approved revert support','Type'=>'yesno','Description'=>'Enable only when SnappPay support requests it.'],
        'language'=>['FriendlyName'=>'Interface language','Type'=>'dropdown','Options'=>'auto,en,fa','Default'=>'auto'],
        'certified'=>['FriendlyName'=>'Production acceptance completed','Type'=>'yesno','Description'=>'Enable after licensed WHMCS staging and SnappPay pre-demo/demo pass.']];
}

function snapppay_link(array $params): string
{
    try {
        $runtime=new \SnappPay\Runtime($params);
        $id=(int)$params['invoiceid'];
        $client=\SnappPay\Runtime::clientId();
        $invoice=$runtime->billing->invoice($id);
        if ((int)$invoice['userid']!==$client || $invoice['status']!=='Unpaid' || $invoice['paymentmethod']!=='snapppay') {
            return '';
        }
        $quote=$runtime->service->quote($invoice);
        if ($quote['total']<=0) {
            return '';
        }
        $offer=$runtime->api->eligible($quote['total']);
        return \SnappPay\View::card($offer,$id,\SnappPay\Runtime::base($params),(string)($params['clientdetails']['phonenumber']??''),
            \SnappPay\View::persian($params),$quote['fee'],$quote['total']);
    } catch (\Throwable $e) {
        \SnappPay\Runtime::log($e,'render');
        return '';
    }
}

function snapppay_refund(array $params): array
{
    try {
        // This function is called inside WHMCS's authorized native Refund workflow.
        $admin=\SnappPay\Runtime::adminId(false);
        $runtime=new \SnappPay\Runtime($params);
        $id=(string)$params['transid'];
        $row=$runtime->store->get($id);
        if ((int)$params['invoiceid']!==$row['invoice_id'] || $params['currency']!==$row['currency']) {
            throw new \SnappPay\Failure('refund_invoice_mismatch');
        }
        $result=$runtime->service->refund($id,\SnappPay\Money::rials((string)$params['amount'],$row['currency']),$admin);
        return ['status'=>'success','transid'=>$result['id'],'fees'=>'0.00','rawdata'=>['transactionId'=>$id,'operation'=>'refund','outcome'=>'provider_done']];
    } catch (\Throwable $e) {
        return ['status'=>'error','rawdata'=>['error'=>\SnappPay\Security::reason($e)]];
    }
}
