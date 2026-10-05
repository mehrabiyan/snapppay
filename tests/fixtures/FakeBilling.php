<?php
declare(strict_types=1);

class FakeBilling implements \SnappPay\Billing
{
    public array $data;
    public array $payments=[];
    public array $refunds=[];
    public int $credits=0;
    public int $feeWrites=0;
    public bool $crashBefore=false;
    public bool $crashAfter=false;
    public function __construct(array $data) { $this->data=$data; }
    public function invoice(int $id): array { return $this->data; }
    public function paymentExists(array $row): bool { return isset($this->payments[$row['transaction_id']]); }
    public function credit(array $row): void
    {
        if ($this->crashBefore) { throw new \RuntimeException('Simulated WHMCS failure'); }
        $this->payments[$row['transaction_id']]=$row['original_amount'];++$this->credits;
        $this->data['status']='Paid';$this->data['balance']='0.00';
        if ($this->crashAfter) { throw new \RuntimeException('Simulated post-credit crash'); }
    }
    public function setFee(array $invoice,?string $amount,string $description): void
    {
        // Mirrors WHMCS updateInvoiceTotal: total and balance move by the fee delta.
        $items=$this->data['items']['item']??[];$delta=0;
        foreach ($items as $i=>$item) { if (($item['type']??'')===self::FEE_ITEM) { $delta-=\SnappPay\Money::rials((string)$item['amount'],$this->data['currency']);unset($items[$i]); } }
        if ($amount!==null) { $items[]=['id'=>99,'type'=>self::FEE_ITEM,'description'=>$description,'amount'=>$amount];$delta+=\SnappPay\Money::rials($amount,$this->data['currency']); }
        $this->data['items']['item']=array_values($items);
        foreach (['total','balance'] as $key) { $this->data[$key]=\SnappPay\Money::decimal(\SnappPay\Money::rials($this->data[$key],$this->data['currency'])+$delta,$this->data['currency']); }
        ++$this->feeWrites;
    }
    public function refundExists(string $refundId,array $row,int $amount): bool { return ($this->refunds[$refundId]??null)===$amount; }
}
