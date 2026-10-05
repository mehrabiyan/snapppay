<?php
// WHMCS simulator only. Copied into tests/runtime/site; never part of release.
declare(strict_types=1);
namespace WHMCS\Database {
    final class Capsule {
        public static function connection(): object { return new class {
            public function getPdo(): \PDO { return \simdb(); }
            public function transaction(\Closure $fn) { $db=\simdb();$db->beginTransaction();try{$out=$fn();$db->commit();return $out;}catch(\Throwable $e){$db->rollBack();throw $e;} }
        }; }
        public static function table(string $name): Query { return new Query($name); }
    }
    final class Query {
        private string $table;private array $where=[];
        public function __construct(string $table) { $this->table=$table; }
        public function where(string $key,$value): self { $this->where[$key]=$value;return $this; }
        public function get(): array {
            $sql='SELECT * FROM '.$this->table;
            if($this->where){$sql.=' WHERE '.implode(' AND ',array_map(static fn($key)=>$key.'=?',array_keys($this->where)));}
            $s=\simdb()->prepare($sql);$s->execute(array_values($this->where));return $s->fetchAll(\PDO::FETCH_OBJ);
        }
        public function first(): ?object { return $this->get()[0]??null; }
        public function value(string $key) { $r=$this->first();return $r->$key??null; }
        public function delete(): int {
            $s=\simdb()->prepare('DELETE FROM '.$this->table.' WHERE '.implode(' AND ',array_map(static fn($key)=>$key.'=?',array_keys($this->where))));$s->execute(array_values($this->where));return $s->rowCount();
        }
        public function insert(array $values): bool {
            $s=\simdb()->prepare('INSERT INTO '.$this->table.' ('.implode(',',array_keys($values)).') VALUES ('.implode(',',array_fill(0,count($values),'?')).')');return $s->execute(array_values($values));
        }
    }
}
namespace WHMCS\Authentication {
    final class CurrentUser {
        public function client(): ?object { return isset($_SESSION['client'])?(object)['id'=>$_SESSION['client']]:null; }
        public function user(): ?object { return null; }
        public function isAuthenticatedUser(): bool { return isset($_SESSION['client']); }
        public function isMasqueradingAdmin(): bool { return false; }
        public function isAuthenticatedAdmin(): bool { return isset($_SESSION['admin']); }
        public function admin(): ?object { return isset($_SESSION['admin'])?(object)['id'=>$_SESSION['admin'],'roleId'=>$_SESSION['role']]:null; }
    }
}
namespace {
    define('WHMCS',true);define('ROOTDIR',__DIR__);
    session_start();
    require_once __DIR__.'/modules/gateways/snapppay/bootstrap.php';
    require_once dirname(__DIR__,2).'/fixtures/FakeProvider.php';
    function simdb(): PDO { static $db;$db??=new PDO('sqlite:'.dirname(__DIR__).'/e2e.sqlite');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);return $db; }
    function generate_token(string $type): string { $_SESSION['token']??=bin2hex(random_bytes(32));return $_SESSION['token']; }
    function encrypt(string $value): string { $n=random_bytes(24);return base64_encode($n.sodium_crypto_secretbox($value,$n,str_repeat('k',32))); }
    function decrypt(string $value): string { $r=base64_decode($value,true);return sodium_crypto_secretbox_open(substr($r,24),substr($r,0,24),str_repeat('k',32)); }
    function getGatewayVariables(string $module): array { return ['type'=>'Invoices','apiUrl'=>'https://api.snapppay.test','paymentHosts'=>'pay.snapppay.test','clientId'=>'test','clientSecret'=>'SECRET-NEVER-OUTPUT','username'=>'test','password'=>'PASSWORD-NEVER-OUTPUT','systemurl'=>'https://merchant.example.test/','environment'=>'stage','allowRevert'=>'on']+simdb()->query('SELECT name,value FROM test_settings')->fetchAll(PDO::FETCH_KEY_PAIR); }
    function localAPI(string $op,array $p): array {
        if($op!=='GetInvoice'){throw new RuntimeException('Unknown simulator API');}
        $s=simdb()->prepare('SELECT data FROM test_invoices WHERE id=?');$s->execute([$p['invoiceid']]);$v=$s->fetchColumn();return $v?json_decode($v,true):['result'=>'error'];
    }
    function checkCbInvoiceID(int $id,string $module): int { return $id; }
    function checkCbTransID(string $id): void { $s=simdb()->prepare('SELECT COUNT(*) FROM tblaccounts WHERE transid=?');$s->execute([$id]);if($s->fetchColumn()){throw new RuntimeException('Duplicate core ledger');} }
    function addInvoicePayment(int $id,string $transid,string $amount,string $fee,string $gateway): void {
        $invoice=localAPI('GetInvoice',['invoiceid'=>$id]);$s=simdb()->prepare('INSERT INTO tblaccounts (transid,invoiceid,userid,gateway,amountin,amountout) VALUES (?,?,?,?,?,?)');$s->execute([$transid,$id,$invoice['userid'],$gateway,$amount,'0.00']);$invoice['status']='Paid';$invoice['balance']='0.00';$s=simdb()->prepare('UPDATE test_invoices SET data=? WHERE id=?');$s->execute([json_encode($invoice),$id]);
    }
    // Mirrors core recalculation for module-owned fee rows; other simulator items live in invoice JSON.
    function updateInvoiceTotal(int $id): void {
        $invoice=localAPI('GetInvoice',['invoiceid'=>$id]);$delta=0;$items=[];
        foreach($invoice['items']['item'] as $item){if(($item['type']??'')==='SnappPayFee'){$delta-=(int)round((float)$item['amount']*100);}else{$items[]=$item;}}
        $s=simdb()->prepare('SELECT id,type,description,amount FROM tblinvoiceitems WHERE invoiceid=?');$s->execute([$id]);
        foreach($s->fetchAll(PDO::FETCH_ASSOC) as $row){$items[]=$row;$delta+=(int)round((float)$row['amount']*100);}
        $invoice['items']['item']=$items;
        foreach(['total','balance'] as $key){$invoice[$key]=number_format(((int)round((float)$invoice[$key]*100)+$delta)/100,2,'.','');}
        $s=simdb()->prepare('UPDATE test_invoices SET data=? WHERE id=?');$s->execute([json_encode($invoice),$id]);
    }
    function logTransaction(string $module,array $data,string $state): void { file_put_contents(dirname(__DIR__).'/gateway.log',json_encode([$module,$data,$state])."\n",FILE_APPEND); }
    function add_hook(string $hook,int $priority,callable $fn): void { $GLOBALS['test_hooks'][$hook]=$fn; }
}
namespace SnappPay {
    // Test transport injected by class declaration before runtime autoload. Not shipped.
    final class CurlTransport extends \FakeProvider {
        public function send(string $method,string $url,array $headers,?string $body): array {
            $saved=\simdb()->query('SELECT data FROM test_provider WHERE id=1')->fetchColumn();
            if($saved){$v=json_decode($saved,true);$this->payments=$v['payments'];$this->buyerPaid=$v['buyerPaid'];}
            if(str_contains($url,'/eligible?')){parse_str((string)parse_url($url,PHP_URL_QUERY),$q);$this->eligible=(int)$q['amount']>=40000 && (int)$q['amount']<=100000000;}
            $out=parent::send($method,$url,$headers,$body);
            $s=\simdb()->prepare('INSERT OR REPLACE INTO test_provider (id,data) VALUES (1,?)');$s->execute([json_encode(['payments'=>$this->payments,'buyerPaid'=>$this->buyerPaid])]);return $out;
        }
    }
}
