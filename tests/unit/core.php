<?php
use SnappPay\{Money,Security,Cart,Api,View};

foreach ([['100.00','IRR',100],['100.10','IRT',1001],['0','TMN',0],['1000000000000','IRR',Money::MAX]] as [$value,$currency,$want]) {
    test("unit money $value $currency",static fn()=>eq(Money::rials($value,$currency),$want));
}
foreach (['-1','1e3','1;DROP TABLE','01','1,000','100.001','NaN','<script>'] as $value) {
    test("unit reject money $value",static fn()=>fails(static fn()=>Money::rials($value,'IRR'),'invalid_amount'));
}
test('unit reject fractional rial',static fn()=>fails(static fn()=>Money::rials('1.01','IRT'),'fractional_rial'));
test('unit reject unsupported FX',static fn()=>fails(static fn()=>Money::rials('1','USD'),'unsupported_currency'));
test('unit reject overflow',static fn()=>fails(static fn()=>Money::rials('1000000000001','IRR'),'amount_overflow'));
test('unit exact decimal inverse',static function (): void { for($i=0;$i<1000;++$i){eq(Money::rials(Money::decimal($i,'IRT'),'IRT'),$i);} });
test('unit allocation properties 1000 randomized cases',static function (): void {
    mt_srand(43);
    for($i=0;$i<1000;++$i){$weights=[mt_rand(1,10000000),mt_rand(1,10000000),mt_rand(1,10000000)];$total=mt_rand(0,array_sum($weights));$out=Money::allocate($total,$weights);eq(array_sum($out),$total);foreach($out as $j=>$v){truth($v>=0 && $v<=$weights[$j]);}}
    eq(Money::allocate(2,[1,1,1]),[1,1,0]);eq(array_sum(Money::allocate(Money::MAX,[Money::MAX-1,1])),Money::MAX);
});
foreach(['09123456789','+98 912 345 6789','00989123456789','۰۹۱۲۳۴۵۶۷۸۹','٠٩١٢٣٤٥٦٧٨٩'] as $mobile){test("unit phone $mobile",static fn()=>eq(Security::mobile($mobile),'09123456789'));}
foreach(['09123','+49123456789',"09123456789\r\nX: evil",'09123456789<script>'] as $mobile){test('unit phone reject '.bin2hex($mobile),static fn()=>fails(static fn()=>Security::mobile($mobile),'invalid_mobile'));}
foreach(['http://pay.example.test','https://user:pass@pay.example.test','https://pay.example.test:8443','https://127.0.0.1','https://localhost','https://pay.example.test/#x',"https://pay.example.test/\r\nX:evil",'https://pay.example.test\\@evil.test'] as $url){test('unit SSRF URL '.bin2hex($url),static fn()=>fails(static fn()=>Security::url($url),'unsafe_url'));}
test('unit redirect exact host',static fn()=>fails(static fn()=>Security::url('https://pay.example.test.evil.test',['pay.example.test']),'unsafe_host'));
test('unit array input injection',static fn()=>fails(static fn()=>Security::scalar(['x'=>['evil']],'x'),'invalid_input'));
test('unit cart net amount tax and credits',static function (): void {$cart=Cart::invoice(invoice(),1100000,'Services',100);eq(array_sum(array_column($cart[0]['cartItems'],'amount')),1100000);eq($cart[0]['taxAmount'],100000);eq($cart[0]['cartItems'][0]['name'],'Hosting plan');eq($cart[0]['cartItems'][0]['id'],10);});
test('unit partial cart removes exhausted items',static function (): void {$cart=Cart::invoice(invoice(),1100000,'Services',100);$cart=Cart::reduce($cart,1);eq(count($cart[0]['cartItems']),1);eq($cart[0]['cartItems'][0]['amount'],1);});
test('unit cart blocks increase and zero',static function (): void {$cart=Cart::invoice(invoice(),100,'Services',100);fails(static fn()=>Cart::reduce($cart,100),'invalid_refund');fails(static fn()=>Cart::reduce($cart,0),'invalid_refund');});
test('unit HTML provider text escaped',static function (): void {$html=View::card(['eligible'=>true,'title_message'=>'<img src=x onerror=alert(1)>','description'=>'<script>alert(1)</script>'],1,'https://merchant.example.test/','" onfocus="alert(1)',false);truth(!str_contains($html,'<script>'));truth(str_contains($html,'&lt;script&gt;'));truth(str_contains($html,'&quot; onfocus='));eq(View::card(['eligible'=>false],1,'https://merchant.example.test/','',false),'');});
test('unit forced method filters match docs',static function (): void {$p=new FakeProvider();$c=config(['methods'=>'INSTALLMENT,FINANCING']);$a=new Api($c,$p);$a->eligible(100000);eq($p->calls[1]['payload']['paymentMethodTypes'],'INSTALLMENT,FINANCING');});
test('unit production acknowledgment',static fn()=>fails(static fn()=>config(['environment'=>'production']),'production_not_certified'));
test('unit API origin rejects path',static fn()=>fails(static fn()=>config(['apiUrl'=>'https://api.example.test/path']),'invalid_api_origin'));
test('unit config secret CRLF injection',static fn()=>fails(static fn()=>config(['clientId'=>"evil\nHeader: bad"]),'missing_configuration'));
foreach(['127.0.0.1','10.1.1.1','169.254.169.254','100.64.0.1','224.0.0.1','0.0.0.0','192.168.0.1','::1','::ffff:127.0.0.1','fc00::1','fe80::1','ff02::1','2001:db8::1'] as $ip){test('unit SSRF blocks IP '.$ip,static fn()=>eq(Security::publicIp($ip),false));}
test('unit SSRF allows public IPv4 and IPv6',static function():void{truth(Security::publicIp('8.8.8.8'));truth(Security::publicIp('2606:4700:4700::1111'));});
