<?php
declare(strict_types=1);

class FakeProvider implements \SnappPay\Transport
{
    public array $calls=[];
    public array $payments=[];
    public bool $buyerPaid=false;
    public bool $eligible=true;
    public array $faults=[];
    public array $afterFaults=[];
    public ?array $statusOverride=null;
    public string $paymentHost='pay.snapppay.test';
    public string $tokenPrefix='pt';

    public function send(string $method,string $url,array $headers,?string $body): array
    {
        $op=basename((string)parse_url($url,PHP_URL_PATH));
        $payload=[];
        if ($method==='GET') { parse_str((string)parse_url($url,PHP_URL_QUERY),$payload); }
        elseif ($op==='token' && str_contains($url,'/oauth/')) { $op='oauth'; parse_str($body??'',$payload); }
        else { $payload=json_decode($body??'{}',true,32,JSON_THROW_ON_ERROR); }
        $this->calls[]=['op'=>$op,'method'=>$method,'url'=>$url,'headers'=>$headers,'payload'=>$payload];
        if (!empty($this->faults[$op])) {
            $reason=array_shift($this->faults[$op]);
            throw new \SnappPay\Failure($reason);
        }
        if ($op==='oauth') {
            return ['access_token'=>'fixture.jwt','token_type'=>'bearer','expires_in'=>3600];
        }
        if ($op==='eligible') {
            $response=['eligible'=>$this->eligible,'title_message'=>'پرداخت اقساطی','description'=>'Provider dynamic terms <script>alert(1)</script>'];
        } elseif ($op==='token') {
            $token=$this->tokenPrefix.count($this->payments);
            $this->payments[$token]=['transactionId'=>$payload['transactionId'],'amount'=>$payload['amount'],'status'=>'PENDING','cartList'=>$payload['cartList']];
            $response=['paymentToken'=>$token,'paymentPageUrl'=>'https://'.$this->paymentHost.'/checkout/'.$token];
        } else {
            $token=$payload['paymentToken'];
            if (!isset($this->payments[$token])) { throw new \SnappPay\Failure('provider_rejected'); }
            $p=&$this->payments[$token];
            if ($op==='status') {
                $response=$this->statusOverride??['transactionId'=>$p['transactionId'],'amount'=>$p['amount'],'status'=>$p['status']];
            } else {
                if ($op==='verify') {
                    if (!$this->buyerPaid) { throw new \SnappPay\Failure('provider_rejected'); }
                    $p['status']='VERIFY';
                } elseif ($op==='settle') { $p['status']='SETTLE'; }
                elseif ($op==='cancel') { $p['status']='CANCEL'; }
                elseif ($op==='revert') { $p['status']='REVERT'; }
                elseif ($op==='update') { $p['amount']=$payload['amount'];$p['cartList']=$payload['cartList']; }
                else { throw new \LogicException('Unknown fake op'); }
                $response=['transactionId'=>$p['transactionId']];
            }
        }
        if (!empty($this->afterFaults[$op])) {
            throw new \SnappPay\Failure(array_shift($this->afterFaults[$op]));
        }
        return ['successful'=>true,'response'=>$response];
    }
    public function count(string $op): int
    {
        return count(array_filter($this->calls,static fn(array $v): bool=>$v['op']===$op));
    }
}
