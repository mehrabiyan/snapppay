"""Build deterministic production candidate; test harness and research are never shipped."""
import pathlib,zipfile,hashlib,json
ROOT=pathlib.Path(__file__).resolve().parents[1];DIST=ROOT/'dist';DIST.mkdir(exist_ok=True)
VERSION='1.0.0-rc.3';files=[]
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
checksum=hashlib.sha256(path.read_bytes()).hexdigest();(DIST/'SHA256SUMS').write_text(checksum+'  '+path.name+'\n')
print(f'Built and verified {path.name}: {len(files)} files; SHA256 {checksum}')
