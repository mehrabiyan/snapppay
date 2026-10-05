<?php
declare(strict_types=1);
require_once __DIR__.'/../modules/gateways/snapppay/bootstrap.php';
require_once __DIR__.'/fixtures/FakeProvider.php';
require_once __DIR__.'/fixtures/FakeBilling.php';

function config(array $override=[]): \SnappPay\Config
{
    return new \SnappPay\Config(array_replace(['type'=>'Invoices','apiUrl'=>'https://api.snapppay.test','paymentHosts'=>'pay.snapppay.test',
        'clientId'=>'test-client','clientSecret'=>'test-secret','username'=>'test-user','password'=>'test-password','environment'=>'stage','allowRevert'=>true],$override));
}
function invoice(): array
{
    return ['invoiceid'=>1,'userid'=>7,'status'=>'Unpaid','paymentmethod'=>'snapppay','currency'=>'IRT','balance'=>'110000.00','total'=>'220000.00','tax'=>'20000.00','tax2'=>'0.00',
        'items'=>['item'=>[['id'=>10,'description'=>'Hosting <b>plan</b>','amount'=>'150000.00'],['id'=>11,'description'=>'Domain','amount'=>'50000.00']]]];
}
function fixture(): array
{
    $db=new PDO('sqlite::memory:');
    $key=random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    $store=new \SnappPay\Store($db,static function (string $text) use ($key): string {
        $nonce=random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce.sodium_crypto_secretbox($text,$nonce,$key));
    },static function (string $text) use ($key): string {
        $raw=base64_decode($text,true);
        return sodium_crypto_secretbox_open(substr($raw,24),substr($raw,0,24),$key);
    });
    $store->install();
    $provider=new FakeProvider();
    $billing=new FakeBilling(invoice());
    $config=config();
    $service=new \SnappPay\Service($config,new \SnappPay\Api($config,$provider),$store,$billing);
    return [$service,$store,$provider,$billing,$db];
}
function startFixture(array $f): array
{
    return $f[0]->start(1,7,'+989123456789',bin2hex(random_bytes(32)),'https://merchant.example.test/modules/gateways/callback/snapppay.php');
}
function payFixture(array $f): array
{
    $row=startFixture($f);
    $f[2]->buyerPaid=true;
    return $f[0]->verifyCallback($row['transaction_id'],(string)$row['amount'],'OK');
}
function generate_token(string $type): string { return 'csrf-fixture'; }
$_SESSION=[];
