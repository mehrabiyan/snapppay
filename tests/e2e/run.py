"""HTTP end-to-end test of real module routes under an explicit WHMCS simulator."""
import pathlib,shutil,sqlite3,json,subprocess,time,urllib.request,urllib.parse,http.cookiejar,re,os,socket
probe=socket.socket()
try:
 probe.bind(('127.0.0.1',8765))
except OSError as e:
 raise RuntimeError('E2E fixture port 8765 occupied; stop your own fixture or choose another port before testing.') from e
finally:probe.close()
ROOT=pathlib.Path(__file__).resolve().parents[2]; RUNTIME=ROOT/'tests/runtime'; SITE=RUNTIME/'site';RUNTIME.mkdir(exist_ok=True)
if SITE.exists():shutil.rmtree(SITE)
SITE.mkdir();shutil.copytree(ROOT/'modules',SITE/'modules');(SITE/'includes').mkdir()
shutil.copytree(ROOT/'includes/hooks',SITE/'includes/hooks')
for f in ['gatewayfunctions.php','invoicefunctions.php']:(SITE/'includes'/f).write_text('<?php // simulator helper definitions in init.php\n')
shutil.copyfile(ROOT/'tests/e2e/init.php',SITE/'init.php')
dbfile=RUNTIME/'e2e.sqlite'
if dbfile.exists():dbfile.unlink()
db=sqlite3.connect(dbfile)
db.executescript('CREATE TABLE test_invoices (id INTEGER PRIMARY KEY,data TEXT);CREATE TABLE test_provider(id INTEGER PRIMARY KEY,data TEXT);CREATE TABLE tblclients(id INTEGER,currency INTEGER);CREATE TABLE tblcurrencies(id INTEGER,code TEXT);CREATE TABLE tblaccounts(id INTEGER PRIMARY KEY,transid TEXT UNIQUE,invoiceid INTEGER,userid INTEGER,gateway TEXT,amountin TEXT,amountout TEXT);CREATE TABLE tbladdonmodules(module TEXT,setting TEXT,value TEXT);INSERT INTO tblclients VALUES(7,1);INSERT INTO tblcurrencies VALUES(1,"IRT");INSERT INTO tbladdonmodules VALUES("snapppay_ops","access","1");')
invoice={'result':'success','invoiceid':1,'userid':7,'status':'Unpaid','paymentmethod':'snapppay','balance':'110000.00','total':'220000.00','tax':'20000.00','tax2':'0.00','items':{'item':[{'id':10,'description':'Hosting plan','amount':'150000.00'},{'id':11,'description':'Domain','amount':'50000.00'}]}}
db.execute('INSERT INTO test_invoices VALUES(1,?)',[json.dumps(invoice)]);db.commit()
install='require "tests/runtime/site/init.php"; SnappPay\\Runtime::store()->install();'
subprocess.run(['php','-r',install],cwd=ROOT,check=True,capture_output=True)
log=open(RUNTIME/'e2e-server.log','w');proc=subprocess.Popen(['php','-S','127.0.0.1:8765',str(ROOT/'tests/e2e/router.php')],cwd=ROOT,stdout=log,stderr=log)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args,**kwargs):return None
jar=http.cookiejar.CookieJar();opener=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar),NoRedirect())
passed=0
def check(v,msg):
 global passed
 if not v:raise AssertionError(msg)
 passed+=1;print('PASS e2e',msg)
def request(path,data=None):
 req=urllib.request.Request('http://127.0.0.1:8765'+path,data=urllib.parse.urlencode(data).encode() if data is not None else None)
 try:r=opener.open(req,timeout=15)
 except urllib.error.HTTPError as e:r=e
 return r.status,r.headers,r.read().decode()
def fields(html):return dict(re.findall(r'name="(token|invoice|nonce)" value="([^"]+)"',html))
try:
 for _ in range(50):
  try:request('/login?client=7');break
  except urllib.error.URLError:time.sleep(.1)
 status,_,html=request('/invoice');check('Continue with SnappPay' in html,'client invoice card rendered');check('&lt;script&gt;' in html and '<script>alert' not in html,'provider terms escaped')
 _,_,body=request('/hook-cart');methods=json.loads(body);check(methods['paymentmethods']['snapppay']['name']=='پرداخت اقساطی','checkout hook shows dynamic provider title');check('&lt;script&gt;' in methods['paymentmethods']['snapppay']['description'],'checkout hook escapes description');check('\\u003Cscript' in methods['footer'],'checkout footer prevents inline script injection')
 _,_,body=request('/hook-cart?amount=1000.00');methods=json.loads(body);check('snapppay' not in methods['paymentmethods'] and 'banktransfer' in methods['paymentmethods'],'ineligible checkout hides only SnappPay')
 _,_,body=request('/hook-cart?amount=10000001.00');check('snapppay' not in json.loads(body)['paymentmethods'],'stage upper-limit eligibility comes from provider')
 def stock_methods(body):
  methods=json.loads(body).get('gateways',[])
  if isinstance(methods,dict):methods=methods.values()
  return {v['sysname']:v for v in methods}
 _,_,body=request('/hook-cart?shape=stock');check(stock_methods(body).get('snapppay',{}).get('name')=='پرداخت اقساطی','stock cart gateways show dynamic provider title')
 _,_,body=request('/hook-cart?shape=stock&amount=1000.00');stock=stock_methods(body);check('snapppay' not in stock and stock.get('banktransfer',{}).get('name')=='Bank Transfer','stock cart gateways hide only ineligible SnappPay')
 _,_,body=request('/hook-cart?shape=stock&totalShape=price');check('snapppay' in stock_methods(body),'stock cart Price object numeric total accepted')
 _,_,body=request('/hook-cart?shape=stock&totalShape=formatted');check('snapppay' in stock_methods(body),'raw cart total takes precedence over formatted total')
 _,_,body=request('/hook-invoice');check(json.loads(body).get('availableGateways',{}).get('snapppay')=='پرداخت اقساطی','stock invoice availableGateways show dynamic provider title')
 data=fields(html);data['mobile']='09123456789'
 status,_,_=request('/modules/gateways/snapppay/start.php');check(status==405,'start rejects GET')
 status,_,_=request('/modules/gateways/snapppay/start.php',data|{'token':'forged'});check(status==400,'start rejects CSRF')
 request('/login?client=8');status,_,html=request('/invoice');check('Continue with SnappPay' not in html,'foreign client cannot view payment form')
 _,_,body=request('/hook-invoice');stock=json.loads(body).get('availableGateways',{});check('snapppay' not in stock and 'banktransfer' in stock,'stock invoice selector denies foreign client')
 request('/login?client=7');_,_,html=request('/invoice');data=fields(html)|{'mobile':'09123456789'}
 status,headers,_=request('/modules/gateways/snapppay/start.php',data);check(status==303 and headers['Location'].startswith('https://pay.snapppay.test/'),'start redirects to allowlisted hosted checkout')
 row=db.execute('SELECT transaction_id,original_amount FROM mod_snapppay').fetchone();tx,amount=row
 stored=db.execute('SELECT token,payment_url FROM mod_snapppay WHERE transaction_id=?',[tx]).fetchone()
 check(stored[1].startswith('spurl1:') and all('pt0' not in value and headers['Location'] not in value for value in stored),'payment token and hosted URL encrypted in persisted HTTP checkout')
 request('/provider/pay');jar.clear()
 callback={'transactionId':tx,'amount':str(amount),'state':'OK'}
 status,_,html=request('/modules/gateways/callback/snapppay.php',callback);check(status==200 and 'Payment complete' in html,'sessionless callback settles and credits')
 status,_,html=request('/modules/gateways/callback/snapppay.php',callback);check(status==200,'callback replay returns success');check(db.execute('SELECT COUNT(*) FROM tblaccounts WHERE amountin!="0.00"').fetchone()[0]==1,'callback replay leaves exactly one payment')
 status,_,_=request('/modules/gateways/callback/snapppay.php',callback|{'amount':'1'});check(status==400,'callback amount tampering rejected')
 status,_,_=request('/modules/gateways/callback/snapppay.php',callback|{'transactionId':"' OR 1=1 --"});check(status==400,'callback SQL injection rejected')
 _,_,html=request('/admin');check('admin_required' in html,'admin dashboard denies anonymous access')
 request('/login?admin=2&role=2');_,_,html=request('/admin');check('admin_access_denied' in html,'addon role restriction enforced')
 token=request('/login?admin=1&role=1')[2];_,_,html=request('/admin');check('Payment operations' in html and tx in html,'admin dashboard shows real persisted payment')
 check('SECRET-NEVER-OUTPUT' not in html and 'PASSWORD-NEVER-OUTPUT' not in html,'dashboard secrets absent')
 _,_,html=request('/admin',{'token':'forged','transaction':tx,'action':'revert','confirm':'yes'});check('csrf_failed' in html,'admin mutation rejects CSRF')
 _,_,html=request('/admin',{'token':token,'transaction':tx,'action':'preview','refundAmount':'10000.00'});check('Refund preview · Invoice #1' in html,'admin partial refund preview rendered')
 status,_,body=request('/native-refund',{'token':token,'transaction':tx,'amount':'10000.00'});out=json.loads(body);check(out['status']=='success','native partial refund calls Update API')
 _,_,html=request('/admin',{'token':token,'transaction':tx,'action':'reconcile'});check('Reconciliation complete' in html,'refund accounting reconciliation clears intent')
 status,_,body=request('/native-refund',{'token':token,'transaction':tx,'amount':'100000.00'});out=json.loads(body);check(out['status']=='success','native remaining full refund calls Cancel API')
 check(db.execute('SELECT amount,state FROM mod_snapppay').fetchone()==(0,'cancelled'),'full refund leaves cancelled zero remaining balance')
 check(db.execute('SELECT COUNT(*) FROM tblaccounts WHERE amountout!="0.00"').fetchone()[0]==2,'WHMCS simulator ledger records two distinct refunds')
 gateway=RUNTIME/'gateway.log';check(not gateway.exists() or not any(v in gateway.read_text() for v in ['SECRET-NEVER-OUTPUT','PASSWORD-NEVER-OUTPUT','09123456789','pt0']),'gateway log excludes secrets phone and payment token')
 print(f'{passed} HTTP E2E assertions passed (WHMCS simulator; no live certification)')
finally:
 proc.terminate();proc.wait(timeout=5);log.close();db.close()
