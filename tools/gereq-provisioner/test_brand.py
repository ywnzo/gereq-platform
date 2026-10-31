#!/usr/bin/env python3
"""Verify brand initialization and targeted repair against disposable platform instances."""
import json
import os
import pathlib
import sqlite3
import subprocess
import unittest
import test_bridge as fixtures

ROOT = pathlib.Path(__file__).resolve().parents[2]
BRAND = pathlib.Path(__file__).parent / 'runtime/brand.php'

class BrandTests(unittest.TestCase):
    def setUp(self):
        self.fixture = fixtures.BridgeTests()
        self.fixture.setUp()
        self.addCleanup(self.fixture.tearDown)

    def brand(self, instance, name, mode='initialize'):
        result = subprocess.run(['php', str(BRAND), name, instance.name, mode],
                                cwd=instance, env=dict(os.environ, GEREQ_INSTANCE_ROOT=str(instance), GEREQ_PROVISION_TEST_DEBUG='1'),
                                capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        return json.loads(result.stdout)

    def php(self, instance, code):
        result = subprocess.run(['php', '-r', "require getenv('GEREQ_INSTANCE_ROOT').'/vendor/autoload.php'; $c=Gereq\\Foundation\\Application::create(getenv('GEREQ_INSTANCE_ROOT'))->container(); $s=$c->get(Gereq\\Setting\\Settings::class); " + code],
                                cwd=instance, env=dict(os.environ, GEREQ_INSTANCE_ROOT=str(instance)), capture_output=True, text=True)
        self.assertEqual(result.returncode, 0, result.stderr)

    def test_initializes_full_name_once_and_preserves_owner_customizations(self):
        instance = self.fixture.instance('brand-initial')
        self.assertEqual(set(self.brand(instance, 'Studio Lípa & partneři')['changed']), {'brand.name', 'brand.site_name'})
        with sqlite3.connect(instance/'storage/database.sqlite') as db:
            for key in ['brand.name', 'brand.site_name', 'app.name']:
                self.assertEqual(db.execute('select value from settings where keyName=?', (key,)).fetchone()[0], 'Studio Lípa & partneři')
        self.php(instance, "$s->setLive('brand.name','Owner brand'); $s->setLive('brand.site_name','Owner site');")
        self.assertEqual(self.brand(instance, 'Different brief name')['changed'], [])
        with sqlite3.connect(instance/'storage/database.sqlite') as db:
            self.assertEqual(db.execute("select value from settings where keyName='brand.name'").fetchone()[0], 'Owner brand')
            self.assertEqual(db.execute("select value from settings where keyName='brand.site_name'").fetchone()[0], 'Owner site')

    def test_initialization_resumes_its_matching_staged_names(self):
        instance = self.fixture.instance('brand-resume')
        self.php(instance, "$c->get(Gereq\\Publish\\ContentReadContext::class)->useStaging(); $s->set('brand.name','Pending company'); $s->set('brand.site_name','Pending company');")
        self.assertEqual(set(self.brand(instance, 'Pending company')['changed']), {'brand.name', 'brand.site_name'})
        with sqlite3.connect(instance/'storage/database.sqlite') as db:
            for key in ['brand.name', 'brand.site_name']:
                self.assertEqual(db.execute('select value from settings where keyName=?', (key,)).fetchone()[0], 'Pending company')
            self.assertEqual(db.execute('select count(*) from content_staging').fetchone()[0], 0)

    def test_repair_publishes_only_name_keys_and_leaves_other_changes_staged(self):
        instance = self.fixture.instance('brand-repair')
        self.php(instance, "$s->setLive('brand.name','brand-repair'); $s->setLive('brand.site_name','brand-repair'); $c->get(Gereq\\Publish\\ContentReadContext::class)->useStaging(); $s->set('brand.tagline','Keep staged tagline'); $s->set('brand.palette',['primary'=>'#ffcc00'],Gereq\\Setting\\SettingRecord::TYPE_JSON,'core');")
        self.assertEqual(set(self.brand(instance, 'Celý název firmy', 'repair')['changed']), {'brand.name', 'brand.site_name'})
        with sqlite3.connect(instance/'storage/database.sqlite') as db:
            for key in ['brand.name', 'brand.site_name']:
                self.assertEqual(db.execute('select value from settings where keyName=?', (key,)).fetchone()[0], 'Celý název firmy')
            self.assertEqual(set(row[0] for row in db.execute('select resource_key from content_staging')), {'brand.tagline', 'brand.palette'})
            self.assertGreater(db.execute('select count(*) from content_releases').fetchone()[0], 0)

if __name__ == '__main__':
    unittest.main()
