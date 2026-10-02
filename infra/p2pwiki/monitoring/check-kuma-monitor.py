# Read back the p2pwiki drift monitor: is it active, and did the heartbeat land?
import os
from uptime_kuma_api import UptimeKumaApi

MONITOR_ID = int(os.environ.get("MONITOR_ID", "332"))

api = UptimeKumaApi(os.environ["KUMA_URL"], timeout=30, wait_events=3)
try:
    api.login(os.environ["KUMA_USERNAME"], os.environ["KUMA_PASSWORD"])
    m = api.get_monitor(MONITOR_ID)
    print("name=%s active=%s type=%s interval=%s notifications=%s"
          % (m["name"], m.get("active"), m.get("type"), m.get("interval"),
             m.get("notificationIDList")))
    try:
        beats = api.get_monitor_beats(MONITOR_ID, 2)
    except Exception as exc:
        beats = []
        print("beats unavailable: %s" % exc.__class__.__name__)
    for b in beats[-3:]:
        print("beat time=%s status=%s msg=%s"
              % (b.get("time"), b.get("status"), str(b.get("msg"))[:90]))
    if not beats:
        print("NO BEATS YET")
finally:
    try:
        api.disconnect()
    except Exception:
        pass
