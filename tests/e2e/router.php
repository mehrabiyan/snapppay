<?php
declare(strict_types=1);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
$site=__DIR__.'/../runtime/site';
chdir($site);
if(str_starts_with($path,'/modules/gateways/')) {
    if(str_contains($path,'..')){http_response_code(400);exit;}
    $file=$site.$path;
    if(is_file($file) && str_ends_with($file,'.php')){require $file;return;}
    if(is_file($file) && str_ends_with($file,'.css')){header('Content-Type: text/css');readfile($file);return;}
}
require $site.'/init.php';
if($path==='/login') {
    $_SESSION=[];
    if(isset($_GET['admin'])){$_SESSION['admin']=(int)$_GET['admin'];$_SESSION['role']=(int)($_GET['role']??1);}
    else{$_SESSION['client']=(int)($_GET['client']??7);}
    echo generate_token('plain');return;
}
if($path==='/invoice') {
    require $site.'/modules/gateways/snapppay.php';
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.file_get_contents($site.'/modules/gateways/snapppay/assets/style.css').'</style></head><body>'.preg_replace('~<link[^>]+>~','',snapppay_link(getGatewayVariables('snapppay')+['invoiceid'=>(int)($_GET['id']??1),'clientdetails'=>['phonenumber'=>'09123456789']])).'</body></html>';return;
}
if($path==='/hook-cart') {
    require $site.'/includes/hooks/snapppay.php';
    $key=($_GET['shape']??'')==='stock'?'gateways':'paymentmethods';
    $vars=['total'=>$_GET['amount']??'110000.00','currency'=>['code'=>'IRT'],$key=>[
        'snapppay'=>['sysname'=>'snapppay','name'=>'Static name'], 'banktransfer'=>['sysname'=>'banktransfer','name'=>'Bank Transfer']]];
    if ($key==='gateways') {$vars[$key]=array_values($vars[$key]);}
    if (($_GET['totalShape']??'')==='price') {
        $vars['total']=new class {public function toNumeric(): float {return 110000.0;}};
    } elseif (($_GET['totalShape']??'')==='formatted') {
        $vars['total']='110,000 تومان';$vars['rawtotal']='110000.00';
    }
    $out=$GLOBALS['test_hooks']['ClientAreaPageCart']($vars);
    $out['footer']=$GLOBALS['test_hooks']['ClientAreaFooterOutput']();
    header('Content-Type: application/json');echo json_encode($out);return;
}
if($path==='/hook-invoice') {
    require $site.'/includes/hooks/snapppay.php';
    $out=$GLOBALS['test_hooks']['ClientAreaPageViewInvoice'](['invoiceid'=>1,'availableGateways'=>['snapppay'=>'Static name','banktransfer'=>'Bank Transfer']]);
    header('Content-Type: application/json');echo json_encode($out);return;
}
if($path==='/settings') {
    foreach(['feeType','feeValue','feeDescription'] as $key){if(isset($_GET[$key])){$s=simdb()->prepare('INSERT OR REPLACE INTO test_settings (name,value) VALUES (?,?)');$s->execute([$key,$_GET[$key]]);}}
    echo 'ok';return;
}
if($path==='/hook-gateway') {
    require $site.'/includes/hooks/snapppay.php';
    $id=(int)$_GET['id'];$invoice=localAPI('GetInvoice',['invoiceid'=>$id]);$invoice['paymentmethod']=$_GET['method'];
    $s=simdb()->prepare('UPDATE test_invoices SET data=? WHERE id=?');$s->execute([json_encode($invoice),$id]);
    $GLOBALS['test_hooks'][$_GET['hook']??'InvoiceChangeGateway'](['invoiceid'=>$id,'paymentmethod'=>$_GET['method']]);
    header('Content-Type: application/json');echo json_encode(localAPI('GetInvoice',['invoiceid'=>$id]));return;
}
if($path==='/admin') {
    require $site.'/modules/addons/snapppay_ops/snapppay_ops.php';
    echo '<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><style>'.file_get_contents($site.'/modules/gateways/snapppay/assets/style.css').'</style></head><body>';
    ob_start();snapppay_ops_output(['modulelink'=>'/admin?module=snapppay_ops']);echo preg_replace('~<link[^>]+>~','',ob_get_clean());echo '</body></html>';return;
}
if($path==='/provider/pay') {
    $v=json_decode(simdb()->query('SELECT data FROM test_provider WHERE id=1')->fetchColumn(),true);$v['buyerPaid']=true;
    $s=simdb()->prepare('UPDATE test_provider SET data=? WHERE id=1');$s->execute([json_encode($v)]);echo 'Simulated provider checkout paid';return;
}
if($path==='/native-refund') {
    require $site.'/modules/gateways/snapppay.php';
    // Simulator mirrors WHMCS's permission/CSRF gate before gateway refund function.
    if(($_SESSION['role']??0)!==1 || ($_POST['token']??'')!==generate_token('plain')){http_response_code(403);echo 'Forbidden';return;}
    $params=getGatewayVariables('snapppay')+['invoiceid'=>1,'currency'=>'IRT','amount'=>$_POST['amount']??'','transid'=>$_POST['transaction']??''];
    $out=snapppay_refund($params);
    if($out['status']==='success'){
        $db=simdb();$s=$db->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid=?');$s->execute([$out['transid']]);
        if(!$s->fetchColumn()){$s=$db->prepare('INSERT INTO tblaccounts (transid,invoiceid,userid,gateway,amountin,amountout) VALUES (?,1,7,?,?,?)');$s->execute([$out['transid'],'snapppay','0.00',$params['amount']]);}
    }
    header('Content-Type: application/json');echo json_encode($out);return;
}
http_response_code(404);echo 'Simulator route not found';
