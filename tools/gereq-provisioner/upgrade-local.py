#!/usr/bin/env python3
"""Local-only upgrade rehearsal/promotion with verified stopped rollback copies."""
import argparse,fcntl,hashlib,json,os,pathlib,shutil,sqlite3,subprocess,urllib.request
p=argparse.ArgumentParser();p.add_argument('phase',choices=['preflight','promote'])
for name in ['root','release','provisioner','job']:p.add_argument('--'+name,required=True)
a=p.parse_args();root=pathlib.Path(a.root).resolve();job=pathlib.Path(a.job).resolve();release_path=pathlib.Path(a.release).resolve();cli=str(pathlib.Path(a.provisioner).resolve());release=json.loads(release_path.read_text());env=dict(os.environ,PODMAN_COMPOSE_PROVIDER='podman-compose')
os.umask(0o077);job.mkdir(parents=True,exist_ok=True)
def run(args):
 r=subprocess.run(args,env=env,capture_output=True,text=True)
 if r.returncode:
  (job/'operation-error.log').write_text(r.stderr);raise RuntimeError('Operation failed; private diagnostic at '+str(job/'operation-error.log'))
 return r.stdout

def write(path,data):
 temp=path.with_name(path.name+'.pending');temp.write_text(json.dumps(data,indent=2)+'\n');temp.chmod(0o600)
 with temp.open('rb') as f:os.fsync(f.fileno())
 temp.replace(path)

def command(op,site,registry=root):return json.loads(run([cli,op,'--root',str(registry),'--site',site]))
def compose(cfg,args):
 project=json.loads(cfg.read_text())['services']['app']['container_name'].removesuffix('-app')
 return run(['podman','compose','--project-name',project,'--file',str(cfg),*args])
def copy(source,dest):
 for path in source.rglob('*'):
  if path.is_symlink():raise RuntimeError('Refusing symlink in instance backup')
 shutil.copytree(source,dest)
 for path in [dest,*dest.rglob('*')]:path.chmod(0o700 if path.is_dir() else 0o600)
 for original in source.rglob('*'):
  if original.is_file():assert hashlib.sha256(original.read_bytes()).digest()==hashlib.sha256((dest/original.relative_to(source)).read_bytes()).digest()
def snapshot(instance):
 with sqlite3.connect(instance/'storage/database.sqlite') as c:
  result={}
  for (table,) in c.execute("select name from sqlite_master where type='table' and name not like 'sqlite_%'").fetchall():
   if not table.replace('_','').isalnum() or table in ['schema_migrations','audit_logs','installed_modules','permissions']:continue
   columns=[d[1] for d in c.execute('pragma table_info("'+table+'")')]
   if table=='modules':columns=['id','enabled']
   rows=c.execute('select '+','.join('"'+v+'"' for v in columns)+' from "'+table+'"').fetchall()
   if rows:result[table]=(columns,rows)
  return result

def verify(before,instance):
 with sqlite3.connect(instance/'storage/database.sqlite') as c:
  assert c.execute('pragma integrity_check').fetchone()[0]=='ok'
  for table,(columns,rows) in before.items():
   after=set(c.execute('select '+','.join('"'+v+'"' for v in columns)+' from "'+table+'"').fetchall())
   if not set(rows).issubset(after):raise RuntimeError('Existing records changed in '+table)
  versions=dict(c.execute('select id,version from modules'));assert all(versions.get(k)==v for k,v in expected_versions.items())
def health(state):
 r=state['request'];request=urllib.request.Request('http://127.0.0.1:'+str(r['port'])+'/api/v1/health',headers={'Host':r['site_id']+'.localhost:'+str(r['port'])})
 with urllib.request.build_opener(urllib.request.ProxyHandler({})).open(request,timeout=15) as response:
  data=json.load(response)['data'];assert data['status']=='ok' and data['version']=='0.6.0'
def backup(site,label,resume=True):
 instance=root/'sites'/site;command('stop',site)
 try:
  with (root/'.lock').open('a+') as lock:
   fcntl.flock(lock,fcntl.LOCK_EX)
   with sqlite3.connect(instance/'storage/database.sqlite') as c:
    assert c.execute('pragma wal_checkpoint(TRUNCATE)').fetchone()[0]==0
    assert c.execute('pragma integrity_check').fetchone()[0]=='ok'
   dest=job/label/site;dest.parent.mkdir(parents=True,exist_ok=True);copy(instance,dest)
  return dest
 finally:
  if resume:command('start',site)
def configure(instance,registry,site,port):
 state=json.loads((instance/'state.json').read_text());state['request'].update({'site_id':site,'port':port,'release_id':release['id']});state['release']=release;state['status']='stopped';state.pop('relocated_to',None)
 req=instance/'upgrade-request.json';write(req,state['request']);plan=json.loads(run([cli,'plan','--root',str(registry),'--request',str(req),'--release',str(release_path)]));state['fingerprint']=plan['fingerprint'];write(instance/'state.json',state)
 cfg=json.loads((instance/'compose.json').read_text());app=cfg['services']['app'];app.update({'image':release['image'],'container_name':plan['compose_project']+'-app','ports':['127.0.0.1:'+str(port)+':8080']});app['labels'].update({'gereq.site':site,'gereq.fingerprint':state['fingerprint'],'gereq.instance':str(instance)})
 for volume in app['volumes']:volume['source']=str(instance/('storage' if volume['target']=='/app/storage' else '.env'))
 write(instance/'compose.json',cfg)
 lines=(instance/'.env').read_text().splitlines()
 for key,value in {'APP_URL':'http://'+site+'.localhost:'+str(port),'FRONTEND_ORIGIN':'http://'+site+'.localhost:'+str(port),'GEREQ_PACKAGE':state['request']['package']}.items():
  lines=[line for line in lines if not line.startswith(key+'=')];lines.append(key+'='+value)
 (instance/'.env').write_text('\n'.join(lines)+'\n');(instance/'.env').chmod(0o600)
 cache=instance/'storage/cache'
 if cache.exists():shutil.rmtree(cache)
 return state

def migrate(instance):compose(instance/'compose.json',['run','--rm','--no-deps','app','php','/opt/provision/bridge.php','upgrade'])
image_id=run(['podman','image','inspect','--format','{{.Id}}',release['image']]).strip()
provenance=json.loads(run(['podman','run','--rm','--network','none','--read-only','--entrypoint','cat',release['image'],'/app/PROVISION_SOURCE.json']))
expected_versions=provenance['module_versions']
if not isinstance(expected_versions,dict) or not all(isinstance(v,str) and v for v in expected_versions.values()):raise RuntimeError('Invalid image module provenance')
sites=['customer-one','customer-two']
if a.phase=='preflight':
 for index,site in enumerate(sites):
  saved=job/'preflight-backups'/site
  if not saved.exists():saved=backup(site,'preflight-backups')
  before=snapshot(saved);registry=job/'rehearsal';newsite='rehearsal-'+site;instance=registry/'sites'/newsite;instance.parent.mkdir(parents=True,exist_ok=True)
  if not instance.exists():copy(saved,instance)
  else:command('stop',newsite,registry)
  state=configure(instance,registry,newsite,18091+index);migrate(instance);verify(before,instance);command('start',newsite,registry);health(state)
  print(site+': migration rehearsal passed; existing records preserved',flush=True)
 write(job/'preflight.json',{'image_id':image_id,'release':release,'sites':sites})
else:
 report=json.loads((job/'preflight.json').read_text());assert report['image_id']==image_id and report['release']==release
 for site in sites:
  saved=backup(site,'promotion-backups',resume=False);instance=root/'sites'/site;before=snapshot(saved);original=json.loads((saved/'state.json').read_text())
  try:
   with (root/'.lock').open('a+') as lock:
    fcntl.flock(lock,fcntl.LOCK_EX);compose(instance/'compose.json',['down']);state=configure(instance,root,site,original['request']['port']);migrate(instance);verify(before,instance)
   command('start',site);health(state)
  except Exception:
   compose(instance/'compose.json',['down']);failed=job/'failed'/site;failed.parent.mkdir(parents=True,exist_ok=True);instance.rename(failed);copy(saved,instance);command('start',site)
   raise RuntimeError('Upgrade failed; original runtime/data restored for '+site)
  print(site+': newest runtime active; accounts/content/settings preserved',flush=True)
 print('Promotion complete; rollback copies retained privately at '+str(job),flush=True)
