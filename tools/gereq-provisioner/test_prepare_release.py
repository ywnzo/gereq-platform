import hashlib
import importlib.util
import pathlib
import subprocess
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = pathlib.Path(__file__).with_name('prepare-release.py')
spec = importlib.util.spec_from_file_location('prepare_release', SCRIPT)
release = importlib.util.module_from_spec(spec)
spec.loader.exec_module(release)


class PrepareReleaseTest(unittest.TestCase):
    def test_digest_includes_edits_and_ignores_dependencies(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            (root / 'source').write_text('first')
            (root / 'node_modules').mkdir()
            (root / 'node_modules' / 'dependency').write_text('ignored')

            def fingerprint():
                digest = hashlib.sha256()
                release.digest_tree(digest, root, 'source')
                return digest.hexdigest()

            before = fingerprint()
            (root / 'node_modules' / 'dependency').write_text('changed')
            self.assertEqual(before, fingerprint())
            (root / 'source').write_text('edited')
            self.assertNotEqual(before, fingerprint())

    def test_existing_output_is_never_removed(self):
        with tempfile.TemporaryDirectory() as directory:
            root = pathlib.Path(directory)
            platform = root / 'platform'
            frontend = root / 'frontend'
            library = root / 'library'
            output = root / 'output'
            for path in [platform, frontend, library, output, platform / 'node_modules', frontend / 'node_modules']:
                path.mkdir(parents=True, exist_ok=True)
            for name in ['vendor/autoload.php', 'src/Foundation/Application.php', 'modules/Cms/module.php',
                         'modules/SiteInquiries/module.php', 'modules/Analytics/module.php']:
                path = platform / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('')
            (platform / 'VERSION').write_text('0.6.0')
            sentinel = output / 'keep'
            sentinel.write_text('existing data')
            with patch.object(release, 'git_info', return_value={'commit': 'abc1234', 'dirty': False}):
                with self.assertRaisesRegex(ValueError, 'new directory'):
                    release.prepare(platform, frontend, library, output)
            self.assertEqual(sentinel.read_text(), 'existing data')

    def test_cli_rejects_snapshot_platform(self):
        result = subprocess.run(['python3', str(SCRIPT), '--platform', '/tmp', '--output', '/tmp/unused'],
                                text=True, capture_output=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('original checkout', result.stderr)


if __name__ == '__main__':
    unittest.main()
