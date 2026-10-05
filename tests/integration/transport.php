<?php
declare(strict_types=1);
require __DIR__.'/../../modules/gateways/snapppay/bootstrap.php';
$transport=new SnappPay\CurlTransport();
// Bypass only production DNS policy to reach isolated TLS fixture. Real cURL settings remain intact.
$method=new ReflectionMethod($transport,'request');
$call=static fn(string $path,array $headers=['Accept: application/json'],?string $body=null): array=>$method->invoke($transport,$body===null?'GET':'POST','https://fixture.example.test:9443/'.$path,$headers,$body,['fixture.example.test:9443:127.0.0.1']);
$tests=['valid TLS JSON'=>static function()use($call){if($call('ok')['value']!=='تست')throw new RuntimeException('JSON mismatch');},
    'POST encoding'=>static function()use($call){if($call('echo',['Content-Type: application/json'],'{"amount":100}')['body']!=='{"amount":100}')throw new RuntimeException('Body mismatch');}];
foreach(['redirect'=>'provider_http_302','server-error'=>'provider_http_500','unauthorized'=>'provider_http_401','invalid-json'=>'invalid_json','html'=>'invalid_content_type','oversized'=>'transport_error'] as $path=>$reason){
    $tests[$path]=static function()use($call,$path,$reason){try{$call($path);}catch(SnappPay\Failure $e){if($e->reason!==$reason)throw $e;return;}throw new RuntimeException('Expected transport rejection');};
}
$tests['header injection']=static function()use($call){try{$call('ok',["X-Evil: a\r\nAuthorization: secret"]);}catch(SnappPay\Failure $e){if($e->reason==='unsafe_header')return;throw $e;}throw new RuntimeException('Injection accepted');};
foreach($tests as $name=>$fn){$fn();echo "PASS TLS transport $name\n";}
echo count($tests)." TLS transport checks passed\n";
