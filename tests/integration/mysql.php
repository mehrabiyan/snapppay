<?php
declare(strict_types=1);
require __DIR__.'/../bootstrap.php';
$dsn=getenv('SNAPPPAY_TEST_DSN');
if(!$dsn){fwrite(STDERR,"Set SNAPPPAY_TEST_DSN pointing to disposable MySQL test database.\n");exit(2);}
$connect=static fn():PDO=>new PDO($dsn,'snapppay_test','test-only-password',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$db=$connect();
$fixtureKey=hash('sha256','snapppay-disposable-mysql-fixture-key',true);
$encrypt=static function(string $value)use($fixtureKey):string{
    $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    return base64_encode($nonce.sodium_crypto_secretbox($value,$nonce,$fixtureKey));
};
$decrypt=static function(string $value)use($fixtureKey):string{
    // Read only older disposable test records; new fixture writes use authenticated encryption.
    if(str_starts_with($value,'test-encrypted:'))return substr($value,15);
    $raw=base64_decode($value,true);
    if($raw===false||strlen($raw)<=SODIUM_CRYPTO_SECRETBOX_NONCEBYTES)throw new RuntimeException('Invalid fixture ciphertext');
    $plain=sodium_crypto_secretbox_open(substr($raw,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),substr($raw,0,SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),$fixtureKey);
    if($plain===false)throw new RuntimeException('Invalid fixture ciphertext');
    return $plain;
};
$make=static fn(PDO $db):SnappPay\Store=>new SnappPay\Store($db,$encrypt,$decrypt);
$store=$make($db);$store->install();$store->install();
$provider=new FakeProvider();$provider->tokenPrefix='mysql-'.bin2hex(random_bytes(16)).'-';
$invoiceId=random_int(1000000,2147483647);$invoice=invoice();$invoice['invoiceid']=$invoiceId;
$billing=new FakeBilling($invoice);$c=config();$s=new SnappPay\Service($c,new SnappPay\Api($c,$provider),$store,$billing);
$r=$s->start($invoiceId,7,'09123456789',bin2hex(random_bytes(32)),'https://merchant.example.test/callback');
$legacyUrl=$r['payment_url'];
$raw=$db->prepare('SELECT * FROM mod_snapppay WHERE transaction_id=?');$raw->execute([$r['transaction_id']]);$before=$raw->fetch(PDO::FETCH_ASSOC);
if(str_contains(json_encode($before,JSON_THROW_ON_ERROR),$provider->tokenPrefix))throw new RuntimeException('MySQL token leaked through storage');
$legacy=$db->prepare('UPDATE mod_snapppay SET payment_url=? WHERE transaction_id=?');$legacy->execute([$legacyUrl,$r['transaction_id']]);
$db->exec("UPDATE mod_snapppay_meta SET value='1' WHERE name='schema_version'");
$store->install();$store->install();
$raw->execute([$r['transaction_id']]);$after=$raw->fetch(PDO::FETCH_ASSOC);
if($store->get($r['transaction_id'])['payment_url']!==$legacyUrl||str_contains(json_encode($after,JSON_THROW_ON_ERROR),$provider->tokenPrefix))throw new RuntimeException('MySQL URL migration failed');
unset($before['payment_url'],$after['payment_url']);
if($before!==$after||$db->query("SELECT value FROM mod_snapppay_meta WHERE name='schema_version'")->fetchColumn()!=='2')throw new RuntimeException('MySQL migration changed ledger fields');
echo "PASS MySQL legacy URL encryption migration preserves ledger fields and is idempotent\n";
$provider->buyerPaid=true;$r=$s->verifyCallback($r['transaction_id'],(string)$r['amount'],'OK');
if($r['state']!=='paid'||$billing->credits!==1)throw new RuntimeException('MySQL lifecycle failed');
echo "PASS MySQL real schema, install idempotence, encrypted token and full lifecycle\n";
$other=$make($connect());$db->query("SELECT GET_LOCK('snapppay:$invoiceId',0)");
try{$other->locked($invoiceId,static fn()=>1);throw new RuntimeException('Lock failed to exclude second connection');}catch(SnappPay\Failure $e){if($e->reason!=='payment_busy')throw $e;}
$db->query("SELECT RELEASE_LOCK('snapppay:$invoiceId')");
$other->locked($invoiceId,static fn()=>1);echo "PASS MySQL connection advisory lock contention and release\n";
$out=$s->refund($r['transaction_id'],100000,$invoiceId);$billing->refunds[$out['id']]=$out['amount'];$s->reconcile($r['transaction_id']);
if($store->get($r['transaction_id'])['refund']!==null)throw new RuntimeException('Refund recovery failed');
echo "PASS MySQL partial refund and accounting recovery\n";
