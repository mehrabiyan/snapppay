<?php
declare(strict_types=1);
if (!defined('WHMCS')) {
    exit('Direct access denied');
}
require_once dirname(__DIR__,2).'/gateways/snapppay/bootstrap.php';

function snapppay_ops_config(): array
{
    return ['name'=>'SnappPay Operations','description'=>'BNPL payment reconciliation, refund previews and deployment readiness.',
        'version'=>'1.0.0-rc.3','author'=>'SnappPay WHMCS Module','language'=>'english','fields'=>[]];
}

function snapppay_ops_activate(): array
{
    try {
        \SnappPay\Runtime::store()->install();
        return ['status'=>'success','description'=>'Payment and audit tables installed. Configure permitted addon roles and payment gateway.'];
    } catch (\Throwable $e) {
        return ['status'=>'error','description'=>'Schema installation failed. Check database privileges and server error log.'];
    }
}

function snapppay_ops_deactivate(): array
{
    return ['status'=>'success','description'=>'Module disabled. Payment and audit records retained for recovery and accounting.'];
}

function snapppay_ops_output(array $vars): void
{
    try {
        $admin=\SnappPay\Runtime::adminId();
        require_once ROOTDIR.'/includes/gatewayfunctions.php';
        require_once ROOTDIR.'/includes/invoicefunctions.php';
        $params=getGatewayVariables('snapppay');
        $runtime=new \SnappPay\Runtime($params);
        $notice='';
        $preview=null;
        if (($_SERVER['REQUEST_METHOD']??'')==='POST') {
            \SnappPay\Runtime::csrf();
            $action=\SnappPay\Security::scalar($_POST,'action',20);
            $id=\SnappPay\Security::scalar($_POST,'transaction',64);
            if (!preg_match('/^s[a-f0-9]{32}$/D',$id)) {
                throw new \SnappPay\Failure('invalid_transaction');
            }
            if ($action==='status') {
                $status=$runtime->service->inspect($id);
                $notice='Provider status: '.$status['status'].' · '.$status['amount'].' IRR. Inspection only; invoice accounting unchanged.';
            } elseif ($action==='reconcile') {
                $row=$runtime->service->reconcile($id);
                $runtime->store->audit($id,'admin_reconcile',$row['state'],$admin);
                $notice='Reconciliation complete. Current status: '.$row['state'];
            } elseif ($action==='revert') {
                if (($_POST['confirm']??'')!=='yes') {
                    throw new \SnappPay\Failure('confirmation_required');
                }
                $row=$runtime->service->revert($id,$admin);
                $notice='Pre-settlement payment reverted.';
            } elseif ($action==='preview') {
                $row=$runtime->store->get($id);
                $amount=\SnappPay\Money::rials(\SnappPay\Security::scalar($_POST,'refundAmount',20),$row['currency']);
                if ($amount<=0 || $amount>$row['amount']) {
                    throw new \SnappPay\Failure('invalid_refund');
                }
                $preview=['invoice'=>$row['invoice_id'],'currency'=>$row['currency'],'amount'=>$amount,'remaining'=>$row['amount']-$amount,
                    'cart'=>$amount===$row['amount']?[]:\SnappPay\Cart::reduce($row['cart'],$row['amount']-$amount)];
            } else {
                throw new \SnappPay\Failure('invalid_action');
            }
        }
        $page=isset($_GET['page'])?\SnappPay\Security::id(\SnappPay\Security::scalar($_GET,'page',6)):1;
        $filter=!empty($_GET['invoice'])?\SnappPay\Security::id(\SnappPay\Security::scalar($_GET,'invoice',10)):null;
        $rows=$runtime->store->recent($page,$filter);
        $base=\SnappPay\Runtime::base($params);
        $moduleLink=(string)$vars['modulelink'];
        $token=(string)generate_token('plain');
        require_once __DIR__.'/view.php';
        snapppay_ops_render($rows,$base,$runtime,$notice,$preview,$page,$filter,$moduleLink,$token);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-warning">SnappPay operations unavailable: '.\SnappPay\Security::escape(\SnappPay\Security::reason($e)).'. Check gateway setup, addon permissions and installation.</div>';
    }
}
