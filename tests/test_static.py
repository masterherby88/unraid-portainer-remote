#!/usr/bin/env python3
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
PLUGIN = ROOT / 'src/usr/local/emhttp/plugins/portainer.remote'

class PluginStaticTests(unittest.TestCase):
    def test_api_requires_post_and_csrf_before_actions(self):
        text = (PLUGIN / 'api.php').read_text()
        self.assertLess(text.index("$_SERVER['REQUEST_METHOD'] !== 'POST'"), text.index("$action = isset($_POST['action'])"))
        self.assertLess(text.index('hash_equals($expected, $provided)'), text.index("$action = isset($_POST['action'])"))

    def test_action_surface_is_explicit(self):
        text = (PLUGIN / 'api.php').read_text()
        self.assertIn("in_array($action, array('start', 'stop', 'restart'), true)", text)
        self.assertNotRegex(text, r"\$_POST\[['\"](?:path|url|method)['\"]\].*pr_api_request")
        self.assertIn('never trust a browser-supplied ID', text)

    def test_secret_not_embedded_in_dashboard_page(self):
        text = (PLUGIN / 'portainer.remote.page').read_text()
        scripts = re.findall(r'<script>(.*?)</script>', text, re.S)
        self.assertTrue(scripts)
        self.assertNotIn('api_key', ''.join(scripts))
        self.assertIn("credentials:'same-origin'", text)
        self.assertIn('csrf_token', text)

    def test_config_secret_permissions_and_atomic_write(self):
        text = (PLUGIN / 'lib.php').read_text()
        self.assertIn('@chmod($path, 0600)', text)
        self.assertIn("rename($tmp, $path)", text)
        self.assertIn("'X-API-Key: ' . $cfg['api_key']", text)

    def test_no_portainer_database_dependency(self):
        for path in PLUGIN.iterdir():
            if path.is_file():
                self.assertNotIn('portainer.db', path.read_text(errors='replace').lower())

    def test_page_headers(self):
        dashboard = (PLUGIN / 'portainer.remote.page').read_text()
        settings_files = list(PLUGIN.glob('PortainerRemote.page'))
        self.assertIn('Menu="Dashboard"', dashboard)
        self.assertTrue(settings_files)
        self.assertIn('Menu="Settings"', settings_files[0].read_text())

if __name__ == '__main__':
    unittest.main(verbosity=2)
