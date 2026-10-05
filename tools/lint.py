import pathlib,subprocess
ROOT=pathlib.Path(__file__).resolve().parents[1]
files=[]
for folder in ['modules','includes','tests']:
 files.extend(p for p in (ROOT/folder).rglob('*.php') if 'runtime' not in p.parts)
for path in files:
 subprocess.run(['php','-l',str(path)],check=True,stdout=subprocess.DEVNULL)
print(f'PHP syntax checked: {len(files)} files')
