"""Build deterministic production candidate; test harness and research are never shipped."""
import pathlib,zipfile,hashlib,json
ROOT=pathlib.Path(__file__).resolve().parents[1];DIST=ROOT/'dist';DIST.mkdir(exist_ok=True)
VERSION='1.0.0-rc.4';files=[]
for directory in ['modules','includes','docs']:
 files.extend(p for p in (ROOT/directory).rglob('*') if p.is_file() and 'research' not in p.relative_to(ROOT).parts)
files.extend(ROOT/p for p in ['README.md','LICENSE','.env.example'])
files=sorted(files,key=lambda p:p.relative_to(ROOT).as_posix())
manifest={p.relative_to(ROOT).as_posix():hashlib.sha256(p.read_bytes()).hexdigest() for p in files}
path=DIST/f'snapppay-whmcs-{VERSION}.zip'
with zipfile.ZipFile(path,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for p in files:
  name=p.relative_to(ROOT).as_posix();info=zipfile.ZipInfo(name,(2026,10,5,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,p.read_bytes())
 info=zipfile.ZipInfo('manifest.json',(2026,10,5,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,json.dumps({'version':VERSION,'files':manifest},sort_keys=True,indent=2))
with zipfile.ZipFile(path) as z:
 assert not any(any(v in p for v in ['tests/','vendor/','research/','.tools/','.sqlite','.key','.pem']) for p in z.namelist())
 for p,digest in manifest.items():assert hashlib.sha256(z.read(p)).hexdigest()==digest
checksum=hashlib.sha256(path.read_bytes()).hexdigest()
# Upload ZIP mirrors WHMCS root: extract over the installation so modules/ and includes/ merge in place.
upload=DIST/f'snapppay-whmcs-{VERSION}-upload.zip';runtime=[p for p in files if p.relative_to(ROOT).parts[0] in ('modules','includes') and p.name!='.DS_Store']
with zipfile.ZipFile(upload,'w',compression=zipfile.ZIP_DEFLATED,compresslevel=9) as z:
 for p in runtime:
  info=zipfile.ZipInfo(p.relative_to(ROOT).as_posix(),(2026,10,5,0,0,0));info.compress_type=zipfile.ZIP_DEFLATED;info.external_attr=0o100644<<16;z.writestr(info,p.read_bytes())
with zipfile.ZipFile(upload) as z:
 names=z.namelist();assert all(n.startswith(('modules/','includes/')) for n in names)
 for n in ['modules/gateways/snapppay.php','modules/gateways/callback/snapppay.php','modules/gateways/snapppay/start.php','modules/addons/snapppay_ops/snapppay_ops.php','includes/hooks/snapppay.php']:assert n in names,n
 for n in names:assert hashlib.sha256(z.read(n)).hexdigest()==manifest[n]
upload_sum=hashlib.sha256(upload.read_bytes()).hexdigest();(DIST/f'{upload.name}.sha256').write_text(upload_sum+'  '+upload.name+'\n')
(DIST/'SHA256SUMS').write_text(checksum+'  '+path.name+'\n'+upload_sum+'  '+upload.name+'\n')
print(f'Built and verified {path.name}: {len(files)} files; SHA256 {checksum}')
print(f'Built and verified {upload.name}: {len(runtime)} files; SHA256 {upload_sum}')
