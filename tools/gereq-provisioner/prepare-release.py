#!/usr/bin/env python3
"""Prepare a local development image context without copying customer state."""
import argparse,json,pathlib,shutil
p=argparse.ArgumentParser();p.add_argument('--platform',required=True);p.add_argument('--frontend-dist',required=True);p.add_argument('--output',required=True);a=p.parse_args()
source=pathlib.Path(a.platform).resolve();frontend=pathlib.Path(a.frontend_dist).resolve();dest=pathlib.Path(a.output).resolve()
version=(source/'VERSION').read_text().strip()
if tuple(map(int,version.split('.')[:2])) < (0,6):p.error('Refusing obsolete platform baseline; version 0.6+ is required')
provenance=source.parent/'source-revisions.json'
if not provenance.is_file():p.error('Verified stack source-revisions.json is required beside the platform snapshot')
for required in ['vendor/autoload.php','public/build/manifest.json','src/Foundation/Application.php','modules/Cms/module.php','modules/SiteInquiries/module.php','modules/Analytics/module.php']:
 if not (source/required).is_file():p.error('Missing platform release input: '+required)
if not (frontend/'index.html').is_file():p.error('Frontend must already be built with empty VITE_API_URL/VITE_API_KEY')
if dest.exists() or dest==source or source in dest.parents:p.error('Output must be a new directory outside platform source')
def ignore(path,names):
 rel=pathlib.Path(path).relative_to(source)
 denied={n for n in names if n in {'.git','.codex','.agents','node_modules','storage','backups','.idea','.cursor'} or n.startswith('.env') or n.endswith('.sqlite') or (pathlib.Path(path)/n).is_symlink()}
 if rel==pathlib.Path('modules'):denied.update(n for n in names if n not in {'Cms','SiteInquiries','Analytics'})
 return denied
shutil.copytree(source,dest/'platform',ignore=ignore)
(dest/'platform'/'storage').mkdir()
shutil.copyfile(provenance,dest/'platform'/'PROVISION_SOURCE.json')
shutil.copytree(frontend,dest/'frontend')
runtime=pathlib.Path(__file__).parent/'runtime';shutil.copytree(runtime,dest/'runtime');shutil.copyfile(runtime/'Dockerfile',dest/'Dockerfile')
(dest/'release-inputs.json').write_text(json.dumps({'development':True,'modules':['cms','site_inquiries','analytics'],'note':'Local source snapshot; do not promote as a production release.'},indent=2))
print('Prepared development context:',dest)
print('Build with: podman build -t localhost/gereq-local:'+json.loads(provenance.read_text())['release_id']+' '+str(dest))
