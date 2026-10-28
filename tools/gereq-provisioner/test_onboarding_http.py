#!/usr/bin/env python3
"""Exercise the actual local HTTP onboarding flow in a disposable owned instance."""
import argparse, os, hashlib, json, pathlib, re, shutil, socket, sqlite3, subprocess, tempfile
import http.cookiejar as cookiejar
import urllib.error, urllib.parse, urllib.request

parser=argparse.ArgumentParser()
parser.add_argument('--provisioner',required=True)
parser.add_argument('--keep-for-browser',action='store_true')
args=parser.parse_args()
os.environ.setdefault("PODMAN_COMPOSE_PROVIDER","podman-compose")
base=pathlib.Path(tempfile.mkdtemp(prefix='gereq-onboarding-http-'))
root=base/'registry';site='onboarding-test';tool=str(pathlib.Path(args.provisioner).resolve())
with socket.socket() as listener:
 listener.bind(('127.0.0.1',0));port=listener.getsockname()[1]
request={'schema_version':1,'request_id':'http-onboarding-test','site_id':site,'customer_ref':'disposable-test','package':'funnel-v1','release_id':json.loads((pathlib.Path(__file__).parent/'examples/release.json').read_text())['id'],'port':port}
request_file=base/'request.json';request_file.write_text(json.dumps(request))
release_file=pathlib.Path(__file__).parent/'examples/release.json'
instance=root/'sites'/site;db=instance/'storage/database.sqlite';created=False;passed=False

def cli(op,*extra):
 result=subprocess.run([tool,op,'--root',str(root),*extra],text=True,capture_output=True)
 if result.returncode:raise RuntimeError('Provisioner operation failed: '+op+' '+result.stderr)
 return json.loads(result.stdout)
class NoRedirect(urllib.request.HTTPRedirectHandler):
 def redirect_request(self,*args):return None

def browser():return urllib.request.build_opener(urllib.request.ProxyHandler({}),urllib.request.HTTPCookieProcessor(cookiejar.CookieJar()),NoRedirect())
def http(opener,path,fields=None,first_party=False):
 req=urllib.request.Request('http://127.0.0.1:'+str(port)+path,data=None if fields is None else urllib.parse.urlencode(fields).encode(),headers=dict({'Host':site+'.localhost:'+str(port)}, **({'Origin':'http://'+site+'.localhost:'+str(port)} if first_party else {})))
 try:response=opener.open(req,timeout=15)
 except urllib.error.HTTPError as error:response=error
 return response.code,response.headers,response.read().decode()
def csrf(body):
 found=re.search(r'name="_csrf"[^>]*value="([^"]+)"',body)
 if not found:raise RuntimeError('CSRF field missing from actual auth form')
 return found.group(1)
def password_hash():
 with sqlite3.connect(db) as c:return c.execute('select password_hash from users').fetchone()[0]
def link():
 result=cli('setup-link','--site',site,'--email','owner@example.test')
 path=urllib.parse.urlsplit(result['setup_url']).path;token=path.rsplit('/',1)[1]
 with sqlite3.connect(db) as c:
  assert c.execute('select token_hash from password_reset_tokens').fetchone()[0]==hashlib.sha256(token.encode()).hexdigest()
 assert not (instance/'access.json').exists()
 return path
try:
 cli('create','--request',str(request_file),'--release',str(release_file));created=True
 guest=browser()
 status,_,body=http(guest,'/api/public/branding',first_party=True);assert status==200 and 'data' in json.loads(body)
 status,_,_=http(guest,'/api/public/branding');assert status==401
 old=link();current=link();initial=password_hash()
 status,headers,body=http(guest,old);assert status==200
 http(guest,old,{'_csrf':csrf(body),'password':'Test-selected-password-123','password_confirmation':'Test-selected-password-123'})
 assert password_hash()==initial
 status,headers,body=http(guest,current);assert status==200;csrf_token=csrf(body)
 status,_,_=http(guest,current,{'password':'Test-selected-password-123','password_confirmation':'Test-selected-password-123'});assert status==419
 with sqlite3.connect(db) as c:assert c.execute('select count(*) from password_reset_tokens').fetchone()[0]==1
 fields={'_csrf':csrf_token,'password':'Test-selected-password-123','password_confirmation':'Test-selected-password-123'}
 status,headers,_=http(guest,current,fields);assert status==302 and '/login' in headers['Location']
 selected=password_hash();assert selected!=initial
 with sqlite3.connect(db) as c:
  assert c.execute('select count(*) from password_reset_tokens').fetchone()[0]==0
  assert c.execute("select value from settings where keyName='setup.completed'").fetchone()[0]=='0'
 http(guest,current,dict(fields,password='Test-replay-password-456',password_confirmation='Test-replay-password-456'))
 assert password_hash()==selected
 status,_,body=http(guest,'/admin/login');assert status==200
 status,headers,_=http(guest,'/admin/login',{'_csrf':csrf(body),'email':'owner@example.test','password':'Test-selected-password-123'})
 assert status==302 and '/setup' in headers['Location']
 status,_,_=http(guest,'/admin/setup');assert status==200
 status,_,body=http(guest,'/admin/setup');assert status==200
 status,headers,_=http(guest,'/admin/setup/welcome',{'_csrf':csrf(body),'app_name':'Onboarding test','locale':'cs','theme':'system'});assert status==302
 status,_,body=http(guest,'/admin/setup/account');assert status==200
 status,headers,_=http(guest,'/admin/setup/account',{'_csrf':csrf(body),'name':'Test owner','email':'owner@example.test','password':'','password_confirmation':''});assert status==302
 status,_,body=http(guest,'/admin/setup/company');assert status==200
 assert 'Jste firma?' in body and 'orders.settings.' not in body and 'shop.settings.' not in body
 assert 'data-company-fields hidden' in body and 'setup.step.shop.nav' not in body
 token=csrf(body)
 status,headers,_=http(guest,'/admin/setup/company',{'_csrf':token,'is_company':'0','seller_name':'Forged hidden value'})
 assert status==302 and '/done' in headers['Location']
 with sqlite3.connect(db) as c:assert c.execute("select value from settings where keyName='invoices.seller_name'").fetchone() is None
 status,_,body=http(guest,'/admin/setup/company');token=csrf(body)
 http(guest,'/admin/setup/company',{'_csrf':token,'is_company':'1','seller_name':'Example company','seller_ico':'12345678','seller_email':'company@example.test'})
 with sqlite3.connect(db) as c:assert c.execute("select value from settings where keyName='invoices.seller_name'").fetchone()[0]=='Example company'
 status,_,body=http(guest,'/admin/setup/company');assert 'data-company-fields hidden' not in body
 token=csrf(body)
 http(guest,'/admin/setup/shop',{'_csrf':token,'package':'shop-v1','store_name':'Forged shop'})
 with sqlite3.connect(db) as c:assert c.execute("select value from settings where keyName='shop.store_name'").fetchone() is None
 status,_,body=http(guest,'/admin/setup/shop');assert status==200 and 'setup.field.store_name' not in body and 'name="store_name"' not in body
 # Only trusted runtime configuration can select the shop profile.
 env_file=instance/'.env';env_text=env_file.read_text();env_file.write_text(env_text.replace('GEREQ_PACKAGE=funnel-v1','GEREQ_PACKAGE=shop-v1'))
 status,_,body=http(guest,'/admin/setup/shop');assert status==200 and 'name="store_name"' in body
 assert 'shop.settings.' not in body and 'setup.field.store_name' not in body
 env_file.write_text(env_text)
 http(guest,'/admin/setup/company',{'_csrf':csrf(body),'is_company':'0'})
 with sqlite3.connect(db) as c:
  assert c.execute("select value from settings where keyName='setup.is_company'").fetchone()[0]=='0'
  assert c.execute("select value from settings where keyName='invoices.seller_name'").fetchone()[0]=='Example company'
 expired=link();before=password_hash()
 with sqlite3.connect(db) as c:c.execute("update password_reset_tokens set expires_at='2000-01-01 00:00:00'")
 fresh=browser();_,_,body=http(fresh,expired)
 http(fresh,expired,{'_csrf':csrf(body),'password':'Test-expired-password-789','password_confirmation':'Test-expired-password-789'})
 assert password_hash()==before
 if args.keep_for_browser:
  final=link();fresh=browser();_,_,body=http(fresh,final)
  http(fresh,final,{'_csrf':csrf(body),'password':'Test-selected-password-123','password_confirmation':'Test-selected-password-123'})
 passed=True
 print('PASS: HTTP reset form, CSRF, hashed/rotated/expired/single-use tokens, chosen-password login and pending setup.')
finally:
 if created and passed and not args.keep_for_browser:
  cli('stop','--site',site)
  cfg=json.loads((instance/'compose.json').read_text());project=cfg['services']['app']['container_name'].removesuffix('-app')
  result=subprocess.run(['podman','compose','--project-name',project,'--file',str(instance/'compose.json'),'down'],capture_output=True)
  if result.returncode:raise RuntimeError('Owned test cleanup failed; fixture retained at '+str(base))
 if passed and not args.keep_for_browser:shutil.rmtree(base)
 else:print("Test fixture retained:",base,"port:",port)
