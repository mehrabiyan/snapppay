<?php
declare(strict_types=1);

class FakeBilling implements \SnappPay\Billing
{
    public array $data;
    public array $payments=[];
    public array $refunds=[];
    public int $credits=0;
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
    public function refundExists(string $refundId,array $row,int $amount): bool { return ($this->refunds[$refundId]??null)===$amount; }
}
