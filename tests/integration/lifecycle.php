<?php
use SnappPay\{Money,Service,Api};

test('integration complete payment and replay credits once',static function (): void {$f=fixture();$row=payFixture($f);eq($row['state'],'paid');eq($f[3]->credits,1);$f[0]->verifyCallback($row['transaction_id'],(string)$row['original_amount'],'OK');eq($f[2]->count('verify'),1);eq($f[3]->credits,1);});
test('integration encrypted token and hosted URL at rest and no mobile retention',static function (): void {$f=fixture();$row=startFixture($f);$raw=$f[4]->query('SELECT * FROM mod_snapppay')->fetch(PDO::FETCH_ASSOC);truth($raw['token']!=='pt0');eq($f[1]->token($row),'pt0');truth(!str_contains(json_encode($raw),'pt0'));truth(!str_contains(json_encode($raw),'09123456789'));eq($row['payment_url'],'https://pay.snapppay.test/checkout/pt0');});
test('integration duplicate submit no extra token',static function (): void {$f=fixture();$n=bin2hex(random_bytes(32));$a=$f[0]->start(1,7,'09123456789',$n,'https://merchant.example.test/callback');$b=$f[0]->start(1,7,'09123456789',$n,'https://merchant.example.test/callback');eq($a['transaction_id'],$b['transaction_id']);eq($f[2]->count('token'),1);});
test('integration independent tabs separate transaction IDs',static function (): void {$f=fixture();$a=startFixture($f);$b=startFixture($f);truth($a['transaction_id']!==$b['transaction_id']);});
test('integration owner authorization before API',static function (): void {$f=fixture();fails(static fn()=>$f[0]->start(1,8,'09123456789',bin2hex(random_bytes(32)),'https://merchant.example.test/callback'),'invoice_unavailable');eq($f[2]->count('token'),0);});
test('integration eligibility false prevents token',static function (): void {$f=fixture();$f[2]->eligible=false;fails(static fn()=>startFixture($f),'not_eligible');eq($f[2]->count('token'),0);});
test('integration forged success does not credit unpaid provider',static function (): void {$f=fixture();$r=startFixture($f);$r=$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');eq($f[3]->credits,0);truth($r['state']!=='paid');});
test('integration FAILED callback cannot reverse payment',static function (): void {$f=fixture();$r=startFixture($f);$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'FAILED');eq($f[2]->count('revert'),0);eq($f[2]->count('verify'),0);});
test('integration callback amount tampering rejected',static function (): void {$f=fixture();$r=startFixture($f);fails(static fn()=>$f[0]->verifyCallback($r['transaction_id'],'1','OK'),'callback_amount_mismatch');eq($f[2]->count('verify'),0);});
test('integration callback SQL injection rejected',static function (): void {$f=fixture();fails(static fn()=>$f[0]->verifyCallback("' OR 1=1 --",'1','OK'),'invalid_callback');});
test('integration status amount mismatch never credits',static function (): void {$f=fixture();$r=startFixture($f);$f[2]->statusOverride=['transactionId'=>$r['transaction_id'],'status'=>'SETTLE','amount'=>1];fails(static fn()=>$f[0]->reconcile($r['transaction_id']),'provider_status_mismatch');eq($f[3]->credits,0);});
test('integration status ID mismatch never credits',static function (): void {$f=fixture();$r=startFixture($f);$f[2]->statusOverride=['transactionId'=>'different','status'=>'SETTLE','amount'=>$r['amount']];fails(static fn()=>$f[0]->reconcile($r['transaction_id']),'provider_status_mismatch');});
test('integration lost verify response resolves through status',static function (): void {$f=fixture();$r=startFixture($f);$f[2]->buyerPaid=true;$f[2]->afterFaults['verify']=['provider_timeout'];$r=$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');eq($r['state'],'paid');eq($f[2]->count('verify'),1);});
test('integration lost settle response resolves through status',static function (): void {$f=fixture();$r=startFixture($f);$f[2]->buyerPaid=true;$f[2]->afterFaults['settle']=['provider_timeout'];$r=$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');eq($r['state'],'paid');eq($f[2]->count('settle'),1);});
test('integration verify timeout PENDING permits bounded retry',static function (): void {$f=fixture();$r=startFixture($f);$f[2]->buyerPaid=true;$f[2]->faults['verify']=['provider_timeout'];$r=$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');eq($r['state'],'paid');eq($f[2]->count('verify'),2);});
test('integration verification exhausted requires operator',static function (): void {$f=fixture();$r=startFixture($f);$f[2]->faults['verify']=['provider_rejected','provider_rejected','provider_rejected'];$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');$f[0]->reconcile($r['transaction_id']);$r=$f[0]->reconcile($r['transaction_id']);eq($r['state'],'review');eq($f[2]->count('verify'),3);});
test('integration missing settle never marks invoice paid',static function (): void {$f=fixture();$r=startFixture($f);$f[2]->buyerPaid=true;$f[2]->faults['settle']=['provider_timeout'];$r=$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');eq($f[3]->credits,0);eq($r['state'],'settling');$r=$f[0]->reconcile($r['transaction_id']);eq($r['state'],'paid');});
test('integration credit crash before posting recovers',static function (): void {$f=fixture();$f[3]->crashBefore=true;$r=startFixture($f);$f[2]->buyerPaid=true;try{$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');}catch(RuntimeException $e){}$f[3]->crashBefore=false;$r=$f[0]->reconcile($r['transaction_id']);eq($r['state'],'paid');eq($f[3]->credits,1);});
test('integration credit crash after posting avoids duplicate',static function (): void {$f=fixture();$f[3]->crashAfter=true;$r=startFixture($f);$f[2]->buyerPaid=true;try{$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');}catch(RuntimeException $e){}$f[3]->crashAfter=false;$r=$f[0]->reconcile($r['transaction_id']);eq($r['state'],'paid');eq($f[3]->credits,1);});
test('integration invoice drift requires review',static function (): void {$f=fixture();$r=startFixture($f);$f[3]->data['balance']='120000.00';$r=$f[0]->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');eq($r['state'],'review');eq($f[2]->count('settle'),0);});
test('integration second tab after paid does not credit twice',static function (): void {$f=fixture();$a=startFixture($f);$b=startFixture($f);$f[2]->buyerPaid=true;$f[0]->verifyCallback($a['transaction_id'],(string)$a['amount'],'OK');$r=$f[0]->verifyCallback($b['transaction_id'],(string)$b['amount'],'OK');eq($r['state'],'review');eq($f[3]->credits,1);});
test('integration ambiguous token response blocks replay',static function (): void {$f=fixture();$f[2]->afterFaults['token']=['provider_timeout'];$n=bin2hex(random_bytes(32));fails(static fn()=>$f[0]->start(1,7,'09123456789',$n,'https://merchant.example.test/callback'),'provider_timeout');fails(static fn()=>$f[0]->start(1,7,'09123456789',$n,'https://merchant.example.test/callback'),'submission_used');eq($f[2]->count('token'),1);});
test('integration unsafe redirect fails closed',static function (): void {$f=fixture();$f[2]->paymentHost='evil.example.test';fails(static fn()=>startFixture($f),'unsafe_host');eq($f[1]->recent()[0]['state'],'token_unknown');});
test('integration rate limit ten attempts',static function (): void {$f=fixture();for($i=0;$i<10;++$i){startFixture($f);}fails(static fn()=>startFixture($f),'rate_limited');});
test('integration environment switch cannot settle',static function (): void {$f=fixture();$r=startFixture($f);$c=config(['environment'=>'production','certified'=>true]);$s=new Service($c,new Api($c,$f[2]),$f[1],$f[3]);fails(static fn()=>$s->reconcile($r['transaction_id']),'environment_mismatch');});
test('integration full refund prevents overlapping bookkeeping',static function (): void {$f=fixture();$r=payFixture($f);$out=$f[0]->refund($r['transaction_id'],$r['amount'],1);fails(static fn()=>$f[0]->refund($r['transaction_id'],$r['amount'],1),'refund_accounting_pending');eq($f[2]->count('cancel'),1);eq($f[1]->get($r['transaction_id'])['state'],'cancelled');$f[3]->refunds[$out['id']]=$out['amount'];$f[0]->reconcile($r['transaction_id']);eq($f[1]->get($r['transaction_id'])['refund'],null);});
test('integration partial refund update exact cart',static function (): void {$f=fixture();$r=payFixture($f);$out=$f[0]->refund($r['transaction_id'],100000,1);eq($f[2]->count('update'),1);$r=$f[1]->get($r['transaction_id']);eq($r['amount'],1000000);eq(array_sum(array_column($r['cart'][0]['cartItems'],'amount')),1000000);$f[3]->refunds[$out['id']]=$out['amount'];$f[0]->reconcile($r['transaction_id']);eq($f[1]->get($r['transaction_id'])['refund'],null);});
test('integration refund blocks unauthorized and excess',static function (): void {$f=fixture();$r=payFixture($f);fails(static fn()=>$f[0]->refund($r['transaction_id'],1,0),'refund_not_authorized');fails(static fn()=>$f[0]->refund($r['transaction_id'],$r['amount']+1,1),'invalid_refund');});
test('integration uncertain refund never blindly resends',static function (): void {$f=fixture();$r=payFixture($f);$f[2]->faults['update']=['provider_timeout'];fails(static fn()=>$f[0]->refund($r['transaction_id'],100000,1),'refund_uncertain');fails(static fn()=>$f[0]->refund($r['transaction_id'],100000,1),'refund_uncertain');eq($f[2]->count('update'),1);});
test('integration lost refund response status recovery',static function (): void {$f=fixture();$r=payFixture($f);$f[2]->afterFaults['update']=['provider_timeout'];$out=$f[0]->refund($r['transaction_id'],100000,1);eq($out['amount'],100000);eq($f[2]->count('update'),1);});
test('integration revert approved only before settle',static function (): void {$f=fixture();$r=startFixture($f);fails(static fn()=>$f[0]->revert($r['transaction_id'],0),'revert_not_authorized');$r=$f[0]->revert($r['transaction_id'],1);eq($r['state'],'reverted');$f=fixture();$r=payFixture($f);fails(static fn()=>$f[0]->revert($r['transaction_id'],1),'revert_status_invalid');});
test('integration migration idempotent preserves payments',static function (): void {$f=fixture();$r=startFixture($f);$f[1]->install();eq($f[1]->get($r['transaction_id'])['amount'],$r['amount']);});
test('integration parameterized SQL protects storage',static function (): void {$f=fixture();startFixture($f);fails(static fn()=>$f[1]->get("' OR 1=1 --"),'unknown_transaction');eq(count($f[1]->recent()),1);});
test('integration invoice lock contention',static function (): void {$f=fixture();$pid=pcntl_fork();if($pid===0){try{$f[1]->locked(1,static function (): void {usleep(250000);});exit(0);}catch(Throwable $e){exit(2);}}usleep(50000);fails(static fn()=>$f[1]->locked(1,static fn()=>1),'payment_busy');pcntl_waitpid($pid,$status);eq(pcntl_wexitstatus($status),0);});
test('integration inspect review state without side effects',static function (): void {$f=fixture();$r=startFixture($f);$f[1]->save($r['transaction_id'],['state'=>'review']);$out=$f[0]->inspect($r['transaction_id']);eq($out,['status'=>'PENDING','amount'=>$r['amount']]);eq($f[2]->count('verify'),0);});
test('integration external payment during settle blocks credit',static function (): void {
    $f=fixture();$r=startFixture($f);$f[2]->buyerPaid=true;
    $provider=new class($f[2],$f[3]) implements SnappPay\Transport {
        private FakeProvider $p;private FakeBilling $b;
        public function __construct(FakeProvider $p,FakeBilling $b){$this->p=$p;$this->b=$b;}
        public function send(string $method,string $url,array $headers,?string $body):array{$out=$this->p->send($method,$url,$headers,$body);if(str_ends_with($url,'/settle')){$this->b->data['status']='Paid';$this->b->data['balance']='0.00';}return $out;}
    };
    $c=config();$s=new Service($c,new Api($c,$provider),$f[1],$f[3]);$r=$s->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');eq($r['state'],'review');eq($r['error'],'invoice_changed_after_settle');eq($f[3]->credits,0);
});
test('integration cron polling advances fairness timestamp',static function (): void {$f=fixture();$r=startFixture($f);$f[4]->exec('UPDATE mod_snapppay SET updated_at=1');eq(count($f[1]->pending()),1);$f[0]->reconcile($r['transaction_id']);eq(count($f[1]->pending()),0);});

test('integration legacy hosted URL migration preserves payment data',static function (): void {
    $f=fixture();$r=startFixture($f);$db=$f[4];
    $q=$db->prepare('UPDATE mod_snapppay SET payment_url=?');$q->execute([$r['payment_url']]);
    $db->exec("UPDATE mod_snapppay_meta SET value='1'");
    eq($f[1]->get($r['transaction_id'])['payment_url'],$r['payment_url']);
    $before=$db->query('SELECT * FROM mod_snapppay')->fetch(PDO::FETCH_ASSOC);
    $f[1]->install();$f[1]->install();
    $after=$db->query('SELECT * FROM mod_snapppay')->fetch(PDO::FETCH_ASSOC);
    truth(!str_contains(json_encode($after),'pt0'));
    unset($before['payment_url'],$after['payment_url']);eq($after,$before);
    eq($db->query('SELECT value FROM mod_snapppay_meta')->fetchColumn(),'2');
    eq($f[1]->get($r['transaction_id'])['payment_url'],$r['payment_url']);
});
test('integration interrupted hosted URL migration resumes without data loss',static function (): void {
    $f=fixture();$a=startFixture($f);$b=startFixture($f);$db=$f[4];
    $q=$db->prepare('UPDATE mod_snapppay SET payment_url=? WHERE transaction_id=?');
    foreach([$a,$b] as $r){$q->execute([$r['payment_url'],$r['transaction_id']]);}
    $db->exec("UPDATE mod_snapppay_meta SET value='1'");
    $ref=new ReflectionClass($f[1]);$encrypt=$ref->getProperty('encrypt')->getValue($f[1]);$decrypt=$ref->getProperty('decrypt')->getValue($f[1]);
    $calls=0;$migration=new SnappPay\Store($db,static function(string $v) use($encrypt,&$calls):string {if(++$calls===2){throw new RuntimeException('Fixture migration interruption');}return $encrypt($v);},$decrypt);
    try{$migration->install();throw new LogicException('Expected interruption');}catch(RuntimeException $e){eq($e->getMessage(),'Fixture migration interruption');}
    eq($db->query('SELECT value FROM mod_snapppay_meta')->fetchColumn(),'1');
    eq($db->query('SELECT COUNT(*) FROM mod_snapppay')->fetchColumn(),2);
    $f[1]->install();eq($db->query('SELECT value FROM mod_snapppay_meta')->fetchColumn(),'2');
    foreach([$a,$b] as $r){eq($f[1]->get($r['transaction_id'])['payment_url'],$r['payment_url']);}
});
test('integration corrupted encrypted hosted URL fails closed',static function (): void {
    $f=fixture();$r=startFixture($f);$raw=$f[4]->query('SELECT payment_url FROM mod_snapppay')->fetchColumn();
    $raw[7]=$raw[7]==='A'?'B':'A';$q=$f[4]->prepare('UPDATE mod_snapppay SET payment_url=?');$q->execute([$raw]);
    fails(static fn()=>$f[1]->get($r['transaction_id']),'invalid_encrypted_url');
    eq($f[3]->credits,0);
});
test('integration future schema refuses activation before migration',static function (): void {
    $f=fixture();$r=startFixture($f);$f[4]->exec("UPDATE mod_snapppay_meta SET value='3'");
    $before=$f[4]->query('SELECT payment_url FROM mod_snapppay')->fetchColumn();
    fails(static fn()=>$f[1]->install(),'newer_schema_installed');
    eq($f[4]->query('SELECT payment_url FROM mod_snapppay')->fetchColumn(),$before);
});
test('integration hosted URL migration traverses multiple batches',static function (): void {
    $f=fixture();$db=$f[4];$url='https://pay.snapppay.test/checkout/legacy-secret';
    for($i=0;$i<101;++$i){$r=$f[1]->create(['transaction_id'=>'s'.bin2hex(random_bytes(16)),'invoice_id'=>1,'client_id'=>7,'nonce'=>bin2hex(random_bytes(32)),
        'environment'=>config()->fingerprint(),'currency'=>'IRT','amount'=>1000,'cart'=>[]]);
        $q=$db->prepare('UPDATE mod_snapppay SET payment_url=? WHERE transaction_id=?');$q->execute([$url,$r['transaction_id']]);}
    $db->exec("UPDATE mod_snapppay_meta SET value='1'");$f[1]->install();
    $rows=$db->query('SELECT transaction_id,payment_url FROM mod_snapppay')->fetchAll(PDO::FETCH_ASSOC);eq(count($rows),101);
    foreach($rows as $r){truth(!str_contains($r['payment_url'],'legacy-secret'));eq($f[1]->get($r['transaction_id'])['payment_url'],$url);}
});
