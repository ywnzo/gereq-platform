#!/usr/bin/env python3
"""Exercise real platform bootstrap/import against disposable SQLite instances."""
import json,os,pathlib,secrets,sqlite3,subprocess,tempfile,unittest
ROOT=pathlib.Path(__file__).resolve().parents[2]
BRIDGE=pathlib.Path(__file__).parent/'runtime/bridge.php'
class BridgeTests(unittest.TestCase):
 def setUp(self):
  self.tmp=tempfile.TemporaryDirectory(prefix='gereq-provision-test-');self.base=pathlib.Path(self.tmp.name)
 def tearDown(self):self.tmp.cleanup()
 def instance(self,name,selected=None):
  p=self.base/name;p.mkdir();(p/'storage').mkdir();(p/'public').mkdir();(p/'public/build').mkdir();(p/'modules').mkdir()
  for part in ['vendor','src','config','resources','integrations','VERSION']:
   if (ROOT/part).exists():(p/part).symlink_to(ROOT/part,target_is_directory=(ROOT/part).is_dir())
  import shutil
  shutil.copytree(ROOT/'public/build',p/'public/build',dirs_exist_ok=True)
  for part in ['Cms','SiteInquiries','Analytics']:(p/'modules'/part).symlink_to(ROOT/'modules'/part,target_is_directory=True)
  hashes=subprocess.check_output(['php','-r',r"require $argv[1].'/vendor/autoload.php'; $h=[]; foreach (['cms'=>'Cms','site_inquiries'=>'SiteInquiries','analytics'=>'Analytics'] as $id=>$dir) { $h[$id]=Gereq\Module\ModuleFootprint::ofDirectory($argv[1].'/modules/'.$dir); } echo json_encode($h, JSON_THROW_ON_ERROR);",str(ROOT)],text=True)
  (p/'public/build/module-source-hashes.json').write_text(hashes)
  (p/'.env').write_text('APP_ENV=production\nAPP_DEBUG=false\nGEREQ_PACKAGE=funnel-v1\nAPP_KEY='+secrets.token_hex(32)+'\nAPP_URL=http://'+name+'.localhost:18081\nDB_CONNECTION=sqlite\nDB_DATABASE='+str(p/'storage/database.sqlite')+'\nCACHE_DRIVER=file\nSESSION_DRIVER=file\nQUEUE_DRIVER=sync\nGEREQ_PREBUILT_ASSETS=true\nGEREQ_SKIP_AUTO_BOOTSTRAP=true\nMAILER_DSN=null://null\n')
  if selected is not None:
   with (p/'.env').open('a') as f:f.write('GEREQ_PACKAGE_MODULES='+','.join(selected)+'\n')
  self.call(p,'bootstrap');self.call(p,'modules');return p
 def call(self,p,op,artifact=None,ok=True):
  env=os.environ.copy();env.update(GEREQ_INSTANCE_ROOT=str(p),GEREQ_PROVISION_TEST_DEBUG='1')
  args=['php',str(BRIDGE),op]
  if artifact:args.append(str(artifact))
  result=subprocess.run(args,env=env,cwd=p,text=True,capture_output=True)
  if ok:self.assertEqual(result.returncode,0,result.stderr);return json.loads(result.stdout)
  self.assertNotEqual(result.returncode,0);return result
 def test_backend_cannot_enable_modules_outside_managed_package(self):
  p=self.instance('managed-policy',['cms'])
  code=r"require $argv[1].'/vendor/autoload.php';$c=Gereq\Foundation\Application::create($argv[1])->container();$c->get(Gereq\Module\ModuleManager::class)->enable('analytics');"
  result=subprocess.run(['php','-r',code,str(p)],cwd=p,text=True,capture_output=True)
  self.assertNotEqual(result.returncode,0)
  with sqlite3.connect(p/'storage/database.sqlite') as c:
   self.assertEqual({r[0] for r in c.execute('select id from modules where enabled=1')},{'cms'})
 def test_default_administrator_initializer_handles_old_images_without_touching_custom_names(self):
  p=self.instance('legacy-default')
  script=(BRIDGE.parent/'administrator.php').read_text().removeprefix('<?php\n')
  with sqlite3.connect(p/'storage/database.sqlite') as c:c.execute("update users set name='Instance owner' where email='operator@example.invalid'")
  def initialize():
   result=subprocess.run(['php','-r',script],cwd=p,env=dict(os.environ,GEREQ_INSTANCE_ROOT=str(p)),text=True,capture_output=True)
   self.assertEqual(result.returncode,0,result.stderr)
  initialize()
  with sqlite3.connect(p/'storage/database.sqlite') as c:
   self.assertEqual(c.execute("select name from users where email='operator@example.invalid'").fetchone()[0],'Administrator')
   c.execute("update users set name='Customer chosen name' where email='operator@example.invalid'")
  initialize()
  with sqlite3.connect(p/'storage/database.sqlite') as c:self.assertEqual(c.execute("select name from users where email='operator@example.invalid'").fetchone()[0],'Customer chosen name')
 def test_default_owner_name_is_administrator(self):
  p=self.instance('administrator-default')
  with sqlite3.connect(p/'storage/database.sqlite') as c:
   name=c.execute("select name from users where email='operator@example.invalid'").fetchone()[0]
   self.assertEqual(name,'Administrator')
 def test_package_modules_exclude_analytics_and_filter_dashboard(self):
  p=self.instance('without-analytics',['cms','site_inquiries'])
  enabled=self.call(p,'modules')['enabled_modules']
  self.assertEqual(set(enabled),{'cms','site_inquiries'})
  self.assertEqual(set(self.call(p,'capabilities')['selected_modules']),set(enabled))
  self.call(p,'analytics-config',ok=False)
  self.call(p,'upgrade')
  self.assertEqual(set(self.call(p,'modules')['enabled_modules']),{'cms','site_inquiries'})
  self.call(p,'dashboard-preset')
  widgets=self.call(p,'dashboard-catalog')['widgets']
  self.assertFalse(any(w['id'].startswith('analytics.') for w in widgets))
 def test_cms_only_and_existing_enabled_modules_are_not_silently_removed(self):
  p=self.instance('cms-only',['cms'])
  self.assertEqual(self.call(p,'modules')['enabled_modules'],['cms'])
  self.call(p,'dashboard-preset')
  self.assertFalse(any(w['id'].startswith('site_inquiries.') for w in self.call(p,'dashboard-catalog')['widgets']))
  other=self.instance('full-package')
  with (other/'.env').open('a') as f:f.write('GEREQ_PACKAGE_MODULES=cms\n')
  self.call(other,'modules',ok=False)
  with sqlite3.connect(other/'storage/database.sqlite') as c:
   self.assertEqual({r[0] for r in c.execute('select id from modules where enabled=1')},{'cms','site_inquiries','analytics'})
 def transition(self,p,operation,target,expected,ok=True):
  script=(BRIDGE.parent/'package-modules.php').read_text().removeprefix('<?php\n')
  env=os.environ.copy();env['GEREQ_INSTANCE_ROOT']=str(p)
  result=subprocess.run(['php','-r',script,operation,json.dumps(target),json.dumps(expected)],env=env,cwd=p,text=True,capture_output=True)
  if ok:self.assertEqual(result.returncode,0,result.stderr);return json.loads(result.stdout)
  self.assertNotEqual(result.returncode,0)
 def test_package_module_upgrade_downgrade_retains_data_and_credentials(self):
  p=self.instance('module-transition',['cms'])
  with sqlite3.connect(p/'storage/database.sqlite') as c:
   user=c.execute('select id,password from users').fetchall() if 'password' in [r[1] for r in c.execute('pragma table_info(users)')] else c.execute('select * from users').fetchall()
  all_modules=['cms','site_inquiries','analytics']
  self.transition(p,'preflight',all_modules,['cms'])
  self.transition(p,'apply',all_modules,['cms'])
  with (p/'.env').open('a') as f:f.write('GEREQ_PACKAGE_MODULES='+','.join(all_modules)+'\n')
  self.call(p,'analytics-config')
  with sqlite3.connect(p/'storage/database.sqlite') as c:
   count=c.execute('select count(*) from analytics_sites').fetchone()[0]
   self.assertEqual(count,1)
  self.transition(p,'apply',['cms'],all_modules)
  with sqlite3.connect(p/'storage/database.sqlite') as c:
   self.assertEqual(c.execute('select count(*) from analytics_sites').fetchone()[0],count)
   self.assertEqual({r[0] for r in c.execute('select id from modules where enabled=1')},{'cms'})
   current=c.execute('select id,password from users').fetchall() if 'password' in [r[1] for r in c.execute('pragma table_info(users)')] else c.execute('select * from users').fetchall()
   self.assertEqual(current,user)
  self.transition(p,'apply',all_modules,['cms'])
  with sqlite3.connect(p/'storage/database.sqlite') as c:self.assertEqual(c.execute('select count(*) from analytics_sites').fetchone()[0],count)
  self.transition(p,'preflight',['cms','nonexistent'],all_modules,ok=False)
  self.transition(p,'preflight',['cms'],['cms'],ok=False)
 def test_initial_palette_is_set_once_and_preserves_owner_changes(self):
  a=self.instance('customer-a')
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   original=json.loads(c.execute("select value from settings where keyName='brand.palette'").fetchone()[0])
   self.assertEqual(original['primary'],'#f5c518')
   original['primary']='#123456'
   c.execute("update settings set value=? where keyName='brand.palette'",(json.dumps(original),))
  self.call(a,'bootstrap')
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   self.assertEqual(json.loads(c.execute("select value from settings where keyName='brand.palette'").fetchone()[0])['primary'],'#123456')
 def test_generated_palette_is_staged_and_conflicts_preserve_existing_work(self):
  a=self.instance('customer-a'); self.assertTrue(self.call(a,'capabilities')['generated_palette']); path=self.artifact('palette-one','palette-one')
  data=json.loads(path.read_text()); data['palette']={'primary':'#ffdb99','secondary':'#112211','accent':'#443311','light_background':'#fff7ee','light_surface':'#fff7ee','dark_background':'#112211','dark_surface':'#182218','storefront_mode':'light'}; path.write_text(json.dumps(data))
  self.call(a,'import',path)
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   live=json.loads(c.execute("select value from settings where keyName='brand.palette'").fetchone()[0]); staged=json.loads(c.execute("select payload from content_staging where resource_key='brand.palette'").fetchone()[0]); self.assertNotEqual(live['primary'],data['palette']['primary']); self.assertEqual(staged['value'],data['palette'])
  other=self.artifact('palette-two','palette-two'); data2=json.loads(other.read_text()); data2['palette']={**data['palette'],'primary':'#ffcc66'}; other.write_text(json.dumps(data2)); self.call(a,'import',other,False)
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   self.assertEqual(json.loads(c.execute("select payload from content_staging where resource_key='brand.palette'").fetchone()[0])['value'],data['palette']); self.assertEqual(c.execute("select count(*) from cms_pages where slug='palette-two'").fetchone()[0],0)
 def test_editorial_illustration_is_instance_owned_and_idempotent(self):
  a=self.instance('customer-a');b=self.instance('customer-b')
  source=pathlib.Path(__file__).parent/'examples/editorial/editorial-path.png'
  name='.editorial-upload-test123'
  for instance in [a,b]:(instance/'storage'/name).write_bytes(source.read_bytes())
  first=self.call(a,'editorial-media',name);again=self.call(a,'editorial-media',name);other=self.call(b,'editorial-media',name)
  self.assertEqual(first['url'],again['url']);self.assertNotEqual(first['url'],other['url'])
  with sqlite3.connect(a/'storage/database.sqlite') as c:self.assertEqual(c.execute('select count(*) from media_assets').fetchone()[0],1)
 def test_setup_links_hash_only_rotate_and_refuse_completed_setup(self):
  a=self.instance('customer-a')
  one=self.call(a,'setup-link','owner@example.test')
  token=one['setup_url'].rsplit('/',1)[1]
  self.assertEqual(len(token),64)
  import hashlib
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   self.assertEqual(c.execute('select token_hash from password_reset_tokens').fetchone()[0],hashlib.sha256(token.encode()).hexdigest())
  two=self.call(a,'setup-link','owner@example.test');self.assertNotEqual(one['setup_url'],two['setup_url'])
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   self.assertEqual(c.execute('select count(*) from password_reset_tokens').fetchone()[0],1)
   self.assertIsNone(c.execute('select 1 from password_reset_tokens where token_hash=?',(hashlib.sha256(token.encode()).hexdigest(),)).fetchone())
   c.execute("update settings set value='1' where keyName='setup.completed'")
  self.call(a,'setup-link','owner@example.test',ok=False)
  for path in (a/'storage').rglob('*'):
   if path.is_file() and path.suffix not in ('.sqlite','-wal','-shm') and path.stat().st_size<1000000:
    self.assertNotIn(two['setup_url'].rsplit('/',1)[1].encode(),path.read_bytes())
 def consume(self,p,token,password,confirm=None):
  code=r'''require getenv('GEREQ_INSTANCE_ROOT').'/vendor/autoload.php';
  $input=json_decode(stream_get_contents(STDIN),true,16,JSON_THROW_ON_ERROR);
  $app=Gereq\Foundation\Application::create(getenv('GEREQ_INSTANCE_ROOT'));
  $request=Symfony\Component\HttpFoundation\Request::create('/admin/reset-password/'.$input['token'],'POST',['password'=>$input['password'],'password_confirmation'=>$input['confirm']]);
  $request->setSession(new Symfony\Component\HttpFoundation\Session\Session(new Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage()));
  $app->container()->get(Symfony\Component\HttpFoundation\RequestStack::class)->push($request);
  $response=$app->container()->get(Gereq\Admin\Http\AuthController::class)->updatePassword($request,$input['token']);
  echo json_encode(['status'=>$response->getStatusCode()]);'''
  result=subprocess.run(['php','-r',code],input=json.dumps({'token':token,'password':password,'confirm':password if confirm is None else confirm}),env=dict(os.environ,GEREQ_INSTANCE_ROOT=str(p)),cwd=p,text=True,capture_output=True)
  self.assertEqual(result.returncode,0,result.stderr)
 def test_password_selection_consumes_token_once_and_keeps_setup_pending(self):
  a=self.instance('customer-a');link=self.call(a,'setup-link','owner@example.test');token=link['setup_url'].rsplit('/',1)[1]
  self.consume(a,token,'Selected-password-123','different')
  with sqlite3.connect(a/'storage/database.sqlite') as c:self.assertEqual(c.execute('select count(*) from password_reset_tokens').fetchone()[0],1)
  self.consume(a,token,'Selected-password-123')
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   self.assertEqual(c.execute('select count(*) from password_reset_tokens').fetchone()[0],0)
   selected=c.execute('select password_hash from users').fetchone()[0]
   self.assertEqual(c.execute("select value from settings where keyName='setup.completed'").fetchone()[0],'0')
  self.consume(a,token,'Another-password-456')
  with sqlite3.connect(a/'storage/database.sqlite') as c:self.assertEqual(c.execute('select password_hash from users').fetchone()[0],selected)
  link=self.call(a,'setup-link','owner@example.test');token=link['setup_url'].rsplit('/',1)[1]
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   c.execute("update password_reset_tokens set expires_at='2000-01-01 00:00:00'")
   before=c.execute('select password_hash from users').fetchone()[0]
  self.consume(a,token,'Expired-password-789')
  with sqlite3.connect(a/'storage/database.sqlite') as c:self.assertEqual(c.execute('select password_hash from users').fetchone()[0],before)
 def test_legacy_operator_password_is_invalidated(self):
  a=self.instance('customer-a')
  with sqlite3.connect(a/'storage/database.sqlite') as c:old=c.execute('select password_hash from users').fetchone()[0]
  (a/'storage/.provision-bootstrap.json').write_text('{"version":1}')
  (a/'storage/sessions').mkdir(exist_ok=True);(a/'storage/sessions/old-session').write_text('old session')
  self.call(a,'secure-access')
  with sqlite3.connect(a/'storage/database.sqlite') as c:self.assertNotEqual(c.execute('select password_hash from users').fetchone()[0],old)
  self.assertFalse((a/'storage/sessions/old-session').exists())
  self.assertEqual(json.loads((a/'storage/.provision-bootstrap.json').read_text())['version'],2)
 def test_interrupted_bootstrap_invalidates_legacy_password_without_marker(self):
  a=self.instance('customer-a')
  with sqlite3.connect(a/'storage/database.sqlite') as c:before=c.execute('select password_hash from users').fetchone()[0]
  (a/'storage/.provision-bootstrap.json').unlink()
  self.call(a,'bootstrap')
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   self.assertNotEqual(c.execute('select password_hash from users').fetchone()[0],before)
   self.assertEqual(c.execute('select count(*) from users').fetchone()[0],1)
 def test_dashboard_preset_is_once_only_and_preserves_customizations(self):
  a=self.instance('customer-a')
  catalog=self.call(a,'dashboard-catalog');self.assertIn('cms.stats',[w['id'] for w in catalog['widgets']])
  self.assertTrue(self.call(a,'dashboard-preset')['applied'])
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   raw=c.execute("select value from settings where keyName='admin.dashboard.layout'").fetchone()[0]
   rows=json.loads(raw);enabled={r['id'] for r in rows if r['enabled']}
   self.assertEqual(enabled,{'site_inquiries.count','site_inquiries.recent','analytics.kpi','analytics.trend.chart','cms.stats','cms.recent','core.shortcuts','cms.shortcut','core.settings.shortcut'})
   rows[0]['width']='third';custom=json.dumps(rows)
   c.execute("update settings set value=? where keyName='admin.dashboard.layout'",(custom,))
  self.assertFalse(self.call(a,'dashboard-preset')['applied'])
  with sqlite3.connect(a/'storage/database.sqlite') as c:self.assertEqual(c.execute("select value from settings where keyName='admin.dashboard.layout'").fetchone()[0],custom)
 def test_analytics_configuration_is_idempotent_and_isolated(self):
  a=self.instance('customer-a');b=self.instance('customer-b')
  self.call(a,'analytics-config');self.call(a,'analytics-config');self.call(b,'analytics-config')
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   tables=[r[0] for r in c.execute("select name from sqlite_master where type='table' and name like '%analytics%'")]
   self.assertIn('analytics_sites',tables)
   self.assertEqual(c.execute('select count(*) from analytics_sites').fetchone()[0],1)
  with sqlite3.connect(b/'storage/database.sqlite') as c:self.assertEqual(c.execute('select count(*) from analytics_sites').fetchone()[0],1)
 def test_inquiry_widget_counts_and_recent_limit(self):
  a=self.instance('customer-a')
  code=r'''require getenv('GEREQ_INSTANCE_ROOT').'/vendor/autoload.php';
  $app=Gereq\Foundation\Application::create(getenv('GEREQ_INSTANCE_ROOT'));$c=$app->container();
  $em=$c->get(Doctrine\ORM\EntityManagerInterface::class);
  $make=static fn($class)=>new $class($c->get(Twig\Environment::class),$em,$c->get(Gereq\Http\Router::class),$c->get(Symfony\Contracts\Translation\TranslatorInterface::class));
  $count=$make(Modules\SiteInquiries\Application\Widgets\InquiryCountWidget::class);
  $recent=$make(Modules\SiteInquiries\Application\Widgets\RecentInquiriesWidget::class);
  $empty=$recent->payload();
  for($i=0;$i<7;$i++){$q=new Modules\SiteInquiries\Domain\Inquiry('Visitor '.$i,'private@example.test','123','Private details');if($i===0){$q->markResolved();}$em->persist($q);}$em->flush();
  echo json_encode(['empty'=>$empty['items'],'count'=>$count->payload()['value'],'items'=>$recent->payload()['items'],'permission'=>$recent->permission(),'html'=>$recent->render()]);'''
  result=subprocess.run(['php','-r',code],env=dict(os.environ,GEREQ_INSTANCE_ROOT=str(a)),cwd=a,text=True,capture_output=True)
  self.assertEqual(result.returncode,0,result.stderr);data=json.loads(result.stdout)
  self.assertEqual(data['empty'],[]);self.assertEqual(data['count'],'6');self.assertEqual(len(data['items']),5)
  self.assertEqual(data['permission'],'site_inquiries.view');self.assertIn('Visitor',data['html'])
  self.assertNotIn('private@example.test',data['html']);self.assertNotIn('Private details',data['html'])
 def test_generated_funnel_preserves_nested_form_identity(self):
  a=self.instance('customer-a');artifact=pathlib.Path(__file__).parent/'examples/generated-funnel.json'
  result=self.call(a,'import',artifact);self.assertTrue(result['draft'])
  source=json.loads(artifact.read_text());forms=[]
  def walk(blocks):
   for block in blocks:
    if block['type']=='form':forms.append(block)
    walk(block['payload'].get('children',[]))
  walk(source['blocks']);self.assertEqual(len(forms),1);self.assertEqual(len(forms[0]['id']),36)
  code=r'''require getenv('GEREQ_INSTANCE_ROOT').'/vendor/autoload.php';
  $app=Gereq\Foundation\Application::create(getenv('GEREQ_INSTANCE_ROOT'));
  $page=$app->container()->get(Doctrine\ORM\EntityManagerInterface::class)->getRepository(Modules\Cms\Domain\Page::class)->findOneBy(['slug'=>'native-funnel-preview']);
  echo json_encode($app->container()->get(Modules\Cms\Application\CmsProvider::class)->serializePage($page,true,true));'''
  proc=subprocess.run(['php','-r',code],env=dict(os.environ,GEREQ_INSTANCE_ROOT=str(a)),cwd=a,text=True,capture_output=True)
  self.assertEqual(proc.returncode,0,proc.stderr);saved=json.loads(proc.stdout);original=forms[0]['id'];forms.clear();walk(saved['blocks'])
  self.assertEqual(forms[0]['id'],original);self.assertEqual(forms[0]['payload']['action'],'site_inquiries.create')
 def test_editorial_import_keeps_native_display_typography(self):
  a=self.instance('customer-a');artifact=pathlib.Path(__file__).parent/'examples/editorial/artifact.json'
  result=self.call(a,'import',artifact);self.assertTrue(result['draft'])
  code=r'''require getenv('GEREQ_INSTANCE_ROOT').'/vendor/autoload.php';
  $app=Gereq\Foundation\Application::create(getenv('GEREQ_INSTANCE_ROOT'));
  $page=$app->container()->get(Doctrine\ORM\EntityManagerInterface::class)->getRepository(Modules\Cms\Domain\Page::class)->findOneBy(['slug'=>'editorial-native']);
  echo json_encode($app->container()->get(Modules\Cms\Application\CmsProvider::class)->serializePage($page,true,true));'''
  proc=subprocess.run(['php','-r',code],env=dict(os.environ,GEREQ_INSTANCE_ROOT=str(a)),cwd=a,text=True,capture_output=True)
  self.assertEqual(proc.returncode,0,proc.stderr);page=json.loads(proc.stdout);headings=[];forms=[]
  def walk(blocks):
   for block in blocks:
    self.assertNotEqual(block['type'],'html')
    if block['type']=='heading':headings.append(block['payload'])
    if block['type']=='form':forms.append(block)
    walk(block['payload'].get('children',[]))
  walk(page['blocks']);hero=next(h for h in headings if h['level']==1)
  self.assertEqual(hero['font_size'],'display');self.assertIn('<em>',hero['text']);self.assertEqual(len(forms),1)
  self.assertTrue(any(h['font_size']=='display-sm' for h in headings))
 def artifact(self,name='sample-v1',slug='sample-page',blocks=None):
  data={'schema_version':1,'artifact_id':name,'title':'Sample page','slug':slug,'blocks':blocks or [{'type':'heading','payload':{'text':'Sample title','level':1}}]}
  p=self.base/(name+'.json');p.write_text(json.dumps(data));return p
 def test_bootstrap_retry_and_draft_isolation(self):
  a=self.instance('customer-a');b=self.instance('customer-b');self.call(a,'bootstrap');self.call(a,'modules')
  artifact=self.artifact();one=self.call(a,'import',artifact);two=self.call(a,'import',artifact);self.assertEqual(one['page_id'],two['page_id']);self.assertTrue(one['draft'])
  with sqlite3.connect(a/'storage/database.sqlite') as c:
   self.assertEqual(c.execute('select count(*) from cms_pages').fetchone()[0],1)
   self.assertEqual(c.execute('select status from cms_pages').fetchone()[0],'draft')
  with sqlite3.connect(b/'storage/database.sqlite') as c:self.assertEqual(c.execute('select count(*) from cms_pages').fetchone()[0],0)
  self.assertNotEqual((a/'.env').read_text(),(b/'.env').read_text())
 def test_invalid_and_conflicting_artifacts_preserve_content(self):
  a=self.instance('customer-a');artifact=self.artifact();self.call(a,'import',artifact)
  data=json.loads(artifact.read_text());data['title']='Changed';artifact.write_text(json.dumps(data));self.call(a,'import',artifact,ok=False)
  self.call(a,'import',self.artifact('other-id-v1'),ok=False)
  for block in [{'type':'heading','payload':{'text':'<em onclick=alert(1)>Bad</em>','level':1}},{'type':'html','payload':{'html':'<script>alert(1)</script>'}},{'type':'text','payload':{'body':'<b>HTML</b>'}},{'type':'text','payload':{'body':'Test','text_color':'#ffffff'}},{'type':'form','payload':{'action':'arbitrary.action'}}]:
   self.call(a,'import',self.artifact('invalid-id-v1','new-page',[block]),ok=False)
  with sqlite3.connect(a/'storage/database.sqlite') as c:self.assertEqual(c.execute('select count(*) from cms_pages').fetchone()[0],1)
if __name__=='__main__':unittest.main()
