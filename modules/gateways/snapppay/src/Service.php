<?php
declare(strict_types=1);
namespace SnappPay;

final class Service
{
    private Config $config;
    private Api $api;
    private Store $store;
    private Billing $billing;
    public function __construct(Config $config, Api $api, Store $store, Billing $billing)
    {
        $this->config = $config;
        $this->api = $api;
        $this->store = $store;
        $this->billing = $billing;
    }

    public function start(int $invoiceId, int $clientId, string $mobile, string $nonce, string $returnUrl): array
    {
        if (!preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            throw new Failure('invalid_nonce');
        }
        $mobile = Security::mobile($mobile);
        Security::url($returnUrl);
        return $this->store->locked($invoiceId, function () use ($invoiceId, $clientId, $mobile, $nonce, $returnUrl): array {
            $invoice = $this->billing->invoice($invoiceId);
            if ((int) $invoice['userid'] !== $clientId || $invoice['status'] !== 'Unpaid' || $invoice['paymentmethod'] !== 'snapppay') {
                throw new Failure('invoice_unavailable');
            }
            // Fee line becomes part of the invoice balance before amount, cart and token are derived.
            $invoice = $this->syncFee($invoiceId);
            $existing = $this->store->nonce($nonce);
            if ($existing) {
                $this->environment($existing);
                if ($existing['invoice_id'] !== $invoiceId || $existing['client_id'] !== $clientId || $existing['state'] !== 'pending') {
                    throw new Failure('submission_used');
                }
                if ($existing['amount'] !== Money::rials((string) $invoice['balance'], $invoice['currency'])) {
                    throw new Failure('invoice_changed');
                }
                return $existing;
            }
            if ($this->store->attempts($invoiceId) >= 10) {
                throw new Failure('rate_limited');
            }
            $amount = Money::rials((string) $invoice['balance'], $invoice['currency']);
            if ($amount <= 0) {
                throw new Failure('invoice_unavailable');
            }
            if (!$this->api->eligible($amount)['eligible']) {
                throw new Failure('not_eligible');
            }
            $v = $this->config->values;
            $cart = Cart::invoice($invoice, $amount, $v['category'], $v['commission']);
            $id = 's' . bin2hex(random_bytes(16));
            $row = $this->store->create(['transaction_id'=>$id,'invoice_id'=>$invoiceId,'client_id'=>$clientId,
                'nonce'=>$nonce,'environment'=>$this->config->fingerprint(),'currency'=>$invoice['currency'],'amount'=>$amount,'cart'=>$cart]);
            $payload = ['amount'=>$amount,'cartList'=>$cart,'discountAmount'=>0,'externalSourceAmount'=>0,
                'mobile'=>$mobile,'returnURL'=>$returnUrl,'transactionId'=>$id];
            if ($v['methods']) {
                $payload['forcedPaymentMethodTypes'] = $v['methods'];
            }
            try {
                $response = $this->api->call('token', $payload);
                $token = $response['paymentToken'] ?? null;
                if (!is_string($token) || $token === '' || strlen($token) > 8192 || preg_match('/[\x00-\x20\x7f]/', $token)) {
                    throw new Failure('invalid_payment_token');
                }
                $url = Security::url((string) ($response['paymentPageUrl'] ?? ''), $v['paymentHosts']);
                $row = $this->store->save($id, ['state'=>'pending','token'=>$token,'token_hash'=>hash('sha256',$token),'payment_url'=>$url]);
                $this->store->audit($id, 'token', 'pending');
                return $row;
            } catch (\Throwable $e) {
                $this->store->save($id, ['state'=>'token_unknown','error'=>Security::reason($e)]);
                throw $e;
            }
        });
    }

    /** Fee quote for an invoice: base excludes any existing fee line; total is what SnappPay would charge. */
    public function quote(array $invoice): array
    {
        $current = 0;
        $combined = false;
        foreach ($invoice['items']['item'] ?? [] as $item) {
            if (($item['type'] ?? '') === Billing::FEE_ITEM) {
                $current += Money::rials((string) $item['amount'], $invoice['currency']);
            }
            // Mass Payment lines already carry each child invoice's own fee.
            $combined = $combined || ($item['type'] ?? '') === 'Invoice';
        }
        $balance = Money::rials((string) $invoice['balance'], $invoice['currency']);
        $base = $balance - $current;
        if ($combined && $base > 0) {
            return ['base'=>$base,'current'=>$current,'fee'=>0,'total'=>$base];
        }
        if ($base <= 0) {
            // Earlier payments already covered part of the fee; never rewrite a settled portion.
            return ['base'=>$base,'current'=>$current,'fee'=>$current,'total'=>$balance];
        }
        $fee = $this->config->fee($base, $invoice['currency']);
        if ($base + $fee > Money::MAX) {
            throw new Failure('amount_overflow');
        }
        return ['base'=>$base,'current'=>$current,'fee'=>$fee,'total'=>$base + $fee];
    }

    /** Add, update or remove the fee line of an unpaid invoice to match its gateway and current settings. */
    public function syncFee(int $invoiceId): array
    {
        return $this->store->locked($invoiceId, function () use ($invoiceId): array {
            $invoice = $this->billing->invoice($invoiceId);
            $hasFee = in_array(Billing::FEE_ITEM, array_map(static fn(array $item): string => (string) ($item['type'] ?? ''), $invoice['items']['item'] ?? []), true);
            if ($invoice['status'] !== 'Unpaid' || ($invoice['paymentmethod'] !== 'snapppay' && !$hasFee)) {
                return $invoice;
            }
            $quote = $this->quote($invoice);
            $fee = $invoice['paymentmethod'] === 'snapppay' ? $quote['fee'] : 0;
            if ($quote['base'] <= 0 || ($fee === $quote['current'] && ($fee > 0 || !$hasFee))) {
                return $invoice;
            }
            $this->billing->setFee($invoice, $fee > 0 ? Money::decimal($fee, $invoice['currency']) : null, $this->config->values['feeDescription']);
            return $this->billing->invoice($invoiceId);
        });
    }

    private function environment(array $row): void
    {
        if (!hash_equals($row['environment'], $this->config->fingerprint())) {
            throw new Failure('environment_mismatch');
        }
    }

    private function status(array $row, ?int $expected = null): array
    {
        $result = $this->api->call('status', ['paymentToken'=>$this->store->token($row)]);
        $amount = $result['amount'] ?? null;
        if (is_string($amount) && ctype_digit($amount) && strlen($amount) <= 13) {
            $amount = (int) $amount;
        }
        if (($result['transactionId'] ?? null) !== $row['transaction_id'] || !is_int($amount) || $amount !== ($expected ?? $row['amount'])
            || !in_array($result['status'] ?? null, ['PENDING','VERIFY','SETTLE','CANCEL','REVERT'], true)) {
            throw new Failure('provider_status_mismatch');
        }
        return $result;
    }

    private function mutate(string $operation, array $row): void
    {
        $result = $this->api->call($operation, ['paymentToken'=>$this->store->token($row)]);
        if (($result['transactionId'] ?? null) !== $row['transaction_id']) {
            throw new Failure('provider_transaction_mismatch');
        }
    }

    /** Read-only provider inspection, including review states; no accounting or payment mutations. */
    public function inspect(string $id): array
    {
        $row=$this->store->get($id);
        $this->environment($row);
        $result=$this->api->call('status',['paymentToken'=>$this->store->token($row)]);
        if (($result['transactionId']??null)!==$id || !in_array($result['status']??null,['PENDING','VERIFY','SETTLE','CANCEL','REVERT'],true)) {
            throw new Failure('provider_status_mismatch');
        }
        $amount=$result['amount']??null;
        if (is_string($amount) && ctype_digit($amount) && strlen($amount)<=13) {
            $amount=(int)$amount;
        }
        if (!is_int($amount) || $amount<0 || $amount>Money::MAX) {
            throw new Failure('provider_status_mismatch');
        }
        return ['status'=>$result['status'],'amount'=>$amount];
    }

    public function callback(string $id, string $amount, string $state): array
    {
        return $this->verifyCallback($id,$amount,$state);
    }

    public function reconcile(string $id): array
    {
        $row = $this->store->get($id);
        return $this->store->locked($row['invoice_id'], function () use ($id): array {
            $row = $this->store->get($id);
            $this->environment($row);
            if (!$row['refund'] && in_array($row['state'], ['paid','cancelled','reverted','review','token_unknown','creating'], true)) {
                return $row;
            }
            try {
                // Rotate bounded cron batches even when buyer has not finished paying.
                $row=$this->store->save($id,[]);
                if ($row['refund']) {
                    return $this->recoverRefund($row);
                }
                $status = $this->status($row)['status'];
                if (in_array($status, ['CANCEL','REVERT'], true)) {
                    return $this->store->save($id, $row['credited'] ? ['state'=>'review','error'=>'external_refund_requires_accounting'] : ['state'=>$status === 'CANCEL' ? 'cancelled' : 'reverted']);
                }
                $invoice = $this->billing->invoice($row['invoice_id']);
                if (!$this->billing->paymentExists($row) && ($invoice['status'] !== 'Unpaid' || $invoice['paymentmethod'] !== 'snapppay'
                    || $invoice['currency'] !== $row['currency'] || Money::rials((string)$invoice['balance'], $row['currency']) !== $row['original_amount'])) {
                    return $this->store->save($id, ['state'=>'review','error'=>'invoice_changed']);
                }
                if ($status === 'PENDING') {
                    // PENDING means buyer not verified yet; only callback OK authorizes initial verify.
                    if ($row['verify_count'] === 0) {
                        return $row;
                    }
                    if ($row['verify_count'] >= 3) {
                        return $this->store->save($id, ['state'=>'review','error'=>'verify_exhausted']);
                    }
                    $row = $this->store->save($id, ['state'=>'verifying','verify_count'=>$row['verify_count']+1]);
                    try {
                        $this->mutate('verify', $row);
                    } catch (Failure $e) {
                        $this->store->audit($id, 'verify', $e->reason);
                    }
                    $status = $this->status($row)['status'];
                }
                if ($status === 'VERIFY') {
                    $row = $this->store->save($id, ['state'=>'settling']);
                    try {
                        $this->mutate('settle', $row);
                    } catch (Failure $e) {
                        $this->store->audit($id, 'settle', $e->reason);
                    }
                    $status = $this->status($row)['status'];
                }
                if ($status === 'SETTLE') {
                    $row = $this->store->save($id, ['state'=>'settled']);
                    if (!$this->billing->paymentExists($row)) {
                        // Other gateways do not participate in our advisory lock. Re-read after HTTP.
                        $latest=$this->billing->invoice($row['invoice_id']);
                        if ($latest['status']!=='Unpaid' || $latest['paymentmethod']!=='snapppay' || $latest['currency']!==$row['currency']
                            || Money::rials((string)$latest['balance'],$row['currency'])!==$row['original_amount']) {
                            return $this->store->save($id,['state'=>'review','error'=>'invoice_changed_after_settle']);
                        }
                        $this->billing->credit($row);
                    }
                    if (!$this->billing->paymentExists($row)) {
                        throw new Failure('credit_not_recorded');
                    }
                    $row = $this->store->save($id, ['state'=>'paid','credited'=>1,'error'=>null]);
                    $this->store->audit($id, 'credit', 'paid');
                }
                return $row;
            } catch (\Throwable $e) {
                $this->store->save($id, ['error'=>Security::reason($e)]);
                throw $e;
            }
        });
    }

    public function verifyCallback(string $id, string $amount, string $state): array
    {
        // Validate callback before any durable intent. All callback entrypoints use this method.
        if (!preg_match('/^s[a-f0-9]{32}$/D', $id) || !ctype_digit($amount) || strlen($amount)>13 || !in_array($state,['OK','FAILED'],true)) {
            throw new Failure('invalid_callback');
        }
        $row = $this->store->get($id);
        if ((int)$amount !== $row['original_amount']) {
            throw new Failure('callback_amount_mismatch');
        }
        return $this->store->locked($row['invoice_id'], function () use ($id,$state): array {
            $row = $this->store->get($id);
            $this->environment($row);
            if ($state === 'OK' && $row['state'] === 'pending' && $row['verify_count'] === 0) {
                // Query authentic status first. PENDING permits verify only with correlated success return.
                $status = $this->status($row)['status'];
                if ($status === 'PENDING') {
                    $invoice = $this->billing->invoice($row['invoice_id']);
                    if ($invoice['status'] !== 'Unpaid' || $invoice['paymentmethod'] !== 'snapppay' || $invoice['currency'] !== $row['currency']
                        || Money::rials((string)$invoice['balance'],$row['currency']) !== $row['amount']) {
                        return $this->store->save($id, ['state'=>'review','error'=>'invoice_changed']);
                    }
                    $row = $this->store->save($id, ['state'=>'verifying','verify_count'=>1]);
                    try {
                        $this->mutate('verify',$row);
                    } catch (Failure $e) {
                        $this->store->audit($id,'verify',$e->reason);
                    }
                }
            }
            return $this->reconcile($id);
        });
    }

    public function refund(string $id, int $refundAmount, int $admin): array
    {
        if ($admin <= 0 || $refundAmount <= 0) {
            throw new Failure('refund_not_authorized');
        }
        $row = $this->store->get($id);
        return $this->store->locked($row['invoice_id'], function () use ($id,$refundAmount,$admin): array {
            $row = $this->store->get($id);
            $this->environment($row);
            if ($row['refund']) {
                $row = $this->recoverRefund($row);
                $refund = $row['refund'];
                if ($refund && $refund['amount'] === $refundAmount && $refund['state'] === 'provider_done') {
                    if (!$this->billing->refundExists($refund['id'],$row,$refundAmount)) {
                        // WHMCS records its refund after this function returns, outside our lock.
                        // Never hand the same result to two overlapping native accounting requests.
                        if (!empty($refund['handed_off'])) {
                            throw new Failure('refund_accounting_pending');
                        }
                        $refund['handed_off']=true;
                        $this->store->save($id,['refund'=>$refund]);
                        return ['id'=>$refund['id'],'amount'=>$refundAmount];
                    }
                    throw new Failure('refund_already_recorded');
                }
                if ($refund) {
                    throw new Failure('refund_accounting_pending');
                }
            }
            if ($row['state'] !== 'paid' || !$row['credited'] || $refundAmount > $row['amount']) {
                throw new Failure('invalid_refund');
            }
            if ($this->status($row)['status'] !== 'SETTLE') {
                throw new Failure('refund_status_invalid');
            }
            $target = $row['amount'] - $refundAmount;
            $cart = $target === 0 ? [] : Cart::reduce($row['cart'],$target);
            $refund = ['id'=>'sr' . bin2hex(random_bytes(16)), 'amount'=>$refundAmount,'target'=>$target,'cart'=>$cart,
                'state'=>'sending','admin'=>$admin];
            $row = $this->store->save($id,['state'=>'refunding','refund'=>$refund]);
            $this->store->audit($id,$target===0?'cancel':'update','intent',$admin);
            try {
                if ($target === 0) {
                    $this->mutate('cancel',$row);
                } else {
                    $result = $this->api->call('update',['paymentToken'=>$this->store->token($row),'amount'=>$target,'cartList'=>$cart,'discountAmount'=>0,'externalSourceAmount'=>0]);
                    if (($result['transactionId']??null)!==$id) {
                        throw new Failure('provider_transaction_mismatch');
                    }
                }
            } catch (Failure $e) {
                $this->store->audit($id,'refund',$e->reason,$admin);
            }
            $row = $this->recoverRefund($row);
            if (($row['refund']['state']??null) !== 'provider_done') {
                throw new Failure('refund_uncertain');
            }
            $intent=$row['refund'];
            $intent['handed_off']=true;
            $this->store->save($id,['refund'=>$intent]);
            return ['id'=>$row['refund']['id'],'amount'=>$refundAmount];
        });
    }

    private function recoverRefund(array $row): array
    {
        $refund = $row['refund'];
        if (!$refund) {
            return $row;
        }
        if ($refund['state'] === 'provider_done') {
            if ($this->billing->refundExists($refund['id'],$row,$refund['amount'])) {
                return $this->store->save($row['transaction_id'],['refund'=>null]);
            }
            return $row;
        }
        // Cancel status examples do not specify amount after cancellation: accept original or zero.
        $result = $this->api->call('status',['paymentToken'=>$this->store->token($row)]);
        $amount = $result['amount']??null;
        if (is_string($amount) && ctype_digit($amount) && strlen($amount)<=13) {
            $amount=(int)$amount;
        }
        $expectedState = $refund['target']===0?'CANCEL':'SETTLE';
        $validAmount = $refund['target']===0 ? in_array($amount,[0,$row['amount']],true) : $amount===$refund['target'];
        if (($result['transactionId']??null)!==$row['transaction_id'] || ($result['status']??null)!==$expectedState || !$validAmount) {
            throw new Failure('refund_uncertain');
        }
        $refund['state']='provider_done';
        $row=$this->store->save($row['transaction_id'],['amount'=>$refund['target'],'cart'=>$refund['cart'],'refund'=>$refund,
            'state'=>$refund['target']===0?'cancelled':'paid','error'=>null]);
        $this->store->audit($row['transaction_id'],'refund','provider_done',$refund['admin']);
        return $row;
    }

    public function revert(string $id, int $admin): array
    {
        if ($admin <= 0 || empty($this->config->values['allowRevert'])) {
            throw new Failure('revert_not_authorized');
        }
        $row=$this->store->get($id);
        return $this->store->locked($row['invoice_id'],function () use ($id,$admin): array {
            $row=$this->store->get($id);
            $this->environment($row);
            if ($row['credited'] || !in_array($this->status($row)['status'],['PENDING','VERIFY'],true)) {
                throw new Failure('revert_status_invalid');
            }
            $this->store->save($id,['state'=>'review','error'=>'revert_intent']);
            $this->store->audit($id,'revert','intent',$admin);
            $this->mutate('revert',$row);
            if ($this->status($row)['status']!=='REVERT') {
                throw new Failure('revert_uncertain');
            }
            return $this->store->save($id,['state'=>'reverted','error'=>null]);
        });
    }
}
