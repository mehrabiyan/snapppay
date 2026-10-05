import http.server,ssl,threading,subprocess,pathlib,json
ROOT=pathlib.Path(__file__).resolve().parents[2];TMP=ROOT/'tests/runtime/tls';TMP.mkdir(parents=True,exist_ok=True)
cert=TMP/'fixture.pem';key=TMP/'fixture.key'
subprocess.run(['openssl','req','-x509','-newkey','rsa:2048','-nodes','-keyout',str(key),'-out',str(cert),'-days','1','-subj','/CN=fixture.example.test','-addext','subjectAltName=DNS:fixture.example.test'],check=True,capture_output=True)
class QuietServer(http.server.ThreadingHTTPServer):
 def handle_error(self,*args):pass
class Handler(http.server.BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_POST(self):self.do_GET()
 def do_GET(self):
  path=self.path.split('?')[0];code=200;typ='application/json';body=json.dumps({'value':'تست'}).encode()
  if path=='/echo':body=json.dumps({'body':self.rfile.read(int(self.headers.get('Content-Length',0))).decode()}).encode()
  if path=='/redirect':code=302
  if path=='/server-error':code=500
  if path=='/unauthorized':code=401
  if path=='/invalid-json':body=b'{'
  if path=='/html':typ='text/html';body=b'<html>'
  if path=='/oversized':body=b'x'*(1048577)
  self.send_response(code);self.send_header('Content-Type',typ);self.send_header('Content-Length',str(len(body)))
  if code==302:self.send_header('Location','http://127.0.0.1:1/secret')
  self.end_headers()
  try:self.wfile.write(body)
  except (BrokenPipeError,ConnectionResetError,ssl.SSLError):pass
server=QuietServer(('127.0.0.1',9443),Handler);ctx=ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER);ctx.load_cert_chain(cert,key);server.socket=ctx.wrap_socket(server.socket,server_side=True)
threading.Thread(target=server.serve_forever,daemon=True).start()
try:
 # First prove untrusted certificate is rejected; then trust only this test CA in child PHP.
 code='require "modules/gateways/snapppay/bootstrap.php";$t=new SnappPay\\CurlTransport;$m=new ReflectionMethod($t,"request");try{$m->invoke($t,"GET","https://fixture.example.test:9443/ok",[],null,["fixture.example.test:9443:127.0.0.1"]);exit(2);}catch(SnappPay\\Failure $e){if($e->reason!=="transport_error")exit(3);echo "PASS TLS rejects untrusted certificate\\n";}'
 subprocess.run(['php', '-d','curl.cainfo=', '-r',code],cwd=ROOT,check=True)
 subprocess.run(['php','-d','curl.cainfo='+str(cert),'tests/integration/transport.php'],cwd=ROOT,check=True)
finally:server.shutdown();server.server_close()
