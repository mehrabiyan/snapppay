"""Inventory public academy articles/pages; retain raw evidence locally only."""
import subprocess,json,re,html,pathlib,hashlib
from concurrent.futures import ThreadPoolExecutor
root=pathlib.Path('docs/research'); root.mkdir(parents=True,exist_ok=True)
def fetch(kind,page):
 url=f'https://academy.snapppay.ir/wp-json/wp/v2/{kind}?per_page=100&page={page}'
 p=subprocess.run(['curl','-fsS','--max-time','45','--resolve','academy.snapppay.ir:443:185.143.234.122',url],capture_output=True)
 if p.returncode: return []
 data=json.loads(p.stdout); (root/f'{kind}-{page}.json').write_bytes(p.stdout)
 return data if isinstance(data,list) else []
allitems=[]
for kind in ['posts','pages']:
 for page in range(1,6):
  items=fetch(kind,page); allitems.extend(items)
  if len(items)<100:break
out=[]
for x in allitems:
 raw=x['content']['rendered']; text=re.sub(r'<(script|style)\b.*?</\1>','',raw,flags=re.S)
 text=html.unescape(re.sub('<[^>]+>',' ',text));text=re.sub(r'\s+',' ',text)
 out.append({'id':x['id'],'title':html.unescape(re.sub('<[^>]+>','',x['title']['rendered'])),'url':x['link'],'text':text,'sha256':hashlib.sha256(raw.encode()).hexdigest()})
(root/'articles.json').write_text(json.dumps(out,ensure_ascii=False,indent=2))
pathlib.Path('docs/documentation-inventory.json').write_text(json.dumps([{k:v for k,v in x.items() if k!='text'} for x in out],ensure_ascii=False,indent=2))
print('Retrieved',len(out),'public articles/pages;',sum(len(x['text']) for x in out),'text characters')
for x in out[100:]:print(x['id'],x['title'],len(x['text']))
