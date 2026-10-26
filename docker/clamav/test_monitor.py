import importlib.util
import unittest
from pathlib import Path
spec = importlib.util.spec_from_file_location('monitor', str(Path(__file__).with_name('monitor.py')))
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
class MonitorTest(unittest.TestCase):
    def test_health_and_freshness(self):
        now=m.dt.datetime(2026,10,26,12,tzinfo=m.dt.timezone.utc).timestamp()
        healthy={'State':{'Running':True,'Health':{'Status':'healthy'}}}
        self.assertEqual([],m.verdict(healthy,'ClamAV 1.4.6/28165/Mon Oct 26 08:00:00 2026',now,48*3600))
        self.assertEqual(['signatures_stale'],m.verdict(healthy,'ClamAV 1.4.6/28160/Thu Oct 22 08:00:00 2026',now,48*3600))
        self.assertIn('scanner_unreachable_or_invalid_version',m.verdict(healthy,'bad',now,48*3600))
        self.assertIn('container_unhealthy',m.verdict({'State':{'Running':True,'Health':{'Status':'unhealthy'}}},'bad',now,48*3600))
        self.assertIn('container_down',m.verdict({'State':{'Running':False}},'bad',now,48*3600))
    def test_debounce_dedup_recovery_and_delivery_retry(self):
        state,event=m.transition({},['down'],100)
        self.assertIsNone(event)
        state,event=m.transition(state,['down'],160)
        self.assertEqual('alert',event)
        state,event=m.transition(state,['down'],220)
        self.assertEqual('alert',event)
        state.update(notified_problem='down',notified_at=220)
        state,event=m.transition(state,['down'],280)
        self.assertIsNone(event)
        state,event=m.transition(state,['down'],220+21600)
        self.assertEqual('alert',event)
        state,event=m.transition(state,[],220+21660)
        self.assertEqual('recovery',event)
        state.update(notified_problem='',notified_at=220+21660)
        state,event=m.transition(state,[],220+21720)
        self.assertIsNone(event)
if __name__ == '__main__':
    unittest.main()
