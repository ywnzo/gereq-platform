#!/usr/bin/env python3
"""Build a local development image context from the original working directories."""
import argparse
import hashlib
import json
import os
import pathlib
import shutil
import subprocess

MODULES = {'cms': 'Cms', 'site_inquiries': 'SiteInquiries', 'analytics': 'Analytics'}
EXCLUDED = {'.git', '.codex', '.agents', 'node_modules', 'storage', 'backups', '.idea', '.cursor'}


def git_info(root):
    def git(*args):
        return subprocess.check_output(['git', '-C', str(root), *args], text=True).strip()
    if pathlib.Path(git('rev-parse', '--show-toplevel')).resolve() != root:
        raise ValueError('Not a repository root: ' + str(root))
    return {'path': str(root), 'commit': git('rev-parse', 'HEAD'),
            'dirty': bool(git('status', '--porcelain', '--untracked-files=normal'))}


def digest_tree(digest, root, prefix):
    for item in sorted(root.rglob('*')):
        if not item.is_file() or item.is_symlink() or any(part in EXCLUDED for part in item.parts):
            continue
        digest.update((prefix + '/' + str(item.relative_to(root))).encode() + b'\0')
        digest.update(hashlib.sha256(item.read_bytes()).digest())


def prepare(platform, frontend_source, cms_lib, dest):
    sources = {name: git_info(root) for name, root in {
        'platform': platform, 'cms': platform / 'modules/Cms',
        'site_inquiries': platform / 'modules/SiteInquiries',
        'analytics': platform / 'modules/Analytics',
        'cms_lib': cms_lib, 'front_vue': frontend_source,
    }.items()}
    version = (platform / 'VERSION').read_text().strip()
    if tuple(map(int, version.split('.')[:2])) < (0, 6):
        raise ValueError('Platform version 0.6 or newer is required')
    for required in ['vendor/autoload.php', 'src/Foundation/Application.php', 'node_modules',
                     *['modules/' + directory + '/module.php' for directory in MODULES.values()]]:
        if not (platform / required).exists():
            raise ValueError('Missing platform release input: ' + required)
    if not (frontend_source / 'node_modules').is_dir():
        raise ValueError('Install the original front-vue dependencies before building')
    if dest.exists() or any(dest == root or root in dest.parents for root in [platform, frontend_source, cms_lib]):
        raise ValueError('Output must be a new directory outside the source checkouts')

    def ignore(path, names):
        rel = pathlib.Path(path).relative_to(platform)
        denied = {n for n in names if n in EXCLUDED or n.startswith('.env') or n.endswith('.sqlite')
                  or (pathlib.Path(path) / n).is_symlink()}
        if rel == pathlib.Path('modules'):
            denied.update(n for n in names if n not in MODULES.values())
        return denied

    dest.mkdir()
    try:
        shutil.copytree(platform, dest / 'platform', ignore=ignore)
        build_root = dest / 'platform'
        (build_root / 'storage').mkdir()
        # A temporary dependency link lets the copied build context compile the original source.
        (build_root / 'node_modules').symlink_to(platform / 'node_modules', target_is_directory=True)
        subprocess.run(['bun', 'run', 'build'], cwd=build_root, check=True)
        for module_id in MODULES:
            subprocess.run(['bun', 'run', 'build:module', '--', module_id], cwd=build_root, check=True)
        (build_root / 'node_modules').unlink()
        hash_code = r'''require "vendor/autoload.php"; $h=[]; foreach (["cms"=>"Cms", "site_inquiries"=>"SiteInquiries", "analytics"=>"Analytics"] as $id=>$dir) { $h[$id]=Gereq\Module\ModuleFootprint::ofDirectory("modules/".$dir); } file_put_contents("public/build/module-source-hashes.json", json_encode($h, JSON_THROW_ON_ERROR));'''
        subprocess.run(['php', '-r', hash_code], cwd=build_root, check=True)
        check_code = r'''require "vendor/autoload.php"; $b=new Gereq\Module\ModuleAssetBuilder(getcwd(),prebuilt:true); foreach (["cms", "site_inquiries", "analytics"] as $id) { $b->ensure($id, true); }'''
        subprocess.run(['php', '-r', check_code], cwd=build_root, check=True)
        env = dict(os.environ, VITE_API_URL='', VITE_API_KEY='', CMS_API_KEY='', VITE_MEDIA_ORIGIN='')
        subprocess.run(['bun', 'run', 'build', '--', '--outDir', str(dest / 'frontend'), '--emptyOutDir'],
                       cwd=frontend_source, env=env, check=True)
        modules = {}
        for name, directory in MODULES.items():
            code = '$m=require $argv[1]; echo $m["version"];'
            modules[name] = subprocess.check_output(['php', '-r', code, str(build_root / 'modules' / directory / 'module.php')], text=True)
        digest = hashlib.sha256()
        for root, prefix in [(build_root, 'platform'), (cms_lib / 'packages', 'cms-lib/packages'),
                             (frontend_source / 'src', 'front-vue/src'), (dest / 'frontend', 'frontend')]:
            digest_tree(digest, root, prefix)
        release_id = 'local-' + sources['platform']['commit'][:7] + '-' + digest.hexdigest()[:12]
        provenance = {'development': True, 'release_id': release_id, 'platform_version': version,
                      'module_versions': modules, 'module_selection': True, 'runtime_security_revision': 1, 'sources': sources, 'content_sha256': digest.hexdigest()}
        (build_root / 'PROVISION_SOURCE.json').write_text(json.dumps(provenance, indent=2) + '\n')
        runtime = platform / 'tools/gereq-provisioner/runtime'
        shutil.copytree(runtime, dest / 'runtime')
        shutil.copyfile(runtime / 'Dockerfile', dest / 'Dockerfile')
        (dest / 'release-inputs.json').write_text(json.dumps(provenance, indent=2) + '\n')
        release = {'schema_version': 1, 'id': release_id, 'image': 'localhost/gereq-local:' + release_id,
                   'development': True, 'modules': list(MODULES), 'module_selection': True}
        (dest / 'release.json').write_text(json.dumps(release, indent=2) + '\n')
    except Exception:
        shutil.rmtree(dest, ignore_errors=True)
        raise
    return release_id


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--platform', required=True)
    parser.add_argument('--frontend-dist', help='Deprecated; if supplied, must be the original front-vue/dist')
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    platform = pathlib.Path(args.platform).resolve()
    canonical = pathlib.Path(__file__).resolve().parents[2]
    frontend_source = canonical.parent / 'front-vue'
    cms_lib = canonical.parent / 'cms-lib'
    if platform != canonical:
        parser.error('Platform must be the original checkout containing this provisioner')
    if args.frontend_dist and pathlib.Path(args.frontend_dist).resolve() != (frontend_source / 'dist').resolve():
        parser.error('Frontend must come from the original sibling front-vue checkout')
    try:
        release_id = prepare(platform, frontend_source, cms_lib, pathlib.Path(args.output).resolve())
    except (ValueError, OSError, subprocess.CalledProcessError) as exc:
        parser.exit(1, 'Release preparation failed: ' + str(exc) + '\n')
    print('Prepared development context:', args.output)
    print('Build with: podman build -t localhost/gereq-local:' + release_id + ' ' + args.output)


if __name__ == '__main__':
    main()
