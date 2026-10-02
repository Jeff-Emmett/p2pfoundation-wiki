# Exercise the DOWN path of a Kuma push monitor without alerting anyone.
#
# A throwaway monitor with NO notification attached, so nothing is mailed; the
# real monitor (332) keeps its notification and its clean history. Created,
# pushed down, read back, then deleted. The token never leaves this container:
# the push goes straight to KUMA_URL rather than out through the host's traefik,
# which the live monitor's UP beat already covered.
import os
import time
import urllib.parse
import urllib.request

from uptime_kuma_api import UptimeKumaApi, MonitorType

NAME = "ZZ throwaway push DOWN test (delete me)"
KUMA = os.environ["KUMA_URL"].rstrip("/")

api = UptimeKumaApi(KUMA, timeout=30, wait_events=3)
mon_id = None
try:
    api.login(os.environ["KUMA_USERNAME"], os.environ["KUMA_PASSWORD"])
    res = api.add_monitor(type=MonitorType.PUSH, name=NAME, interval=600, maxretries=0)
    mon_id = res["monitorID"]
    mon = api.get_monitor(mon_id)
    print("created id=%s notifications=%s" % (mon_id, mon.get("notificationIDList")))

    for status, msg in (("up", "synthetic UP"), ("down", "synthetic DOWN - probe drift path")):
        url = "%s/api/push/%s?%s" % (KUMA, mon["pushToken"],
                                     urllib.parse.urlencode({"status": status, "msg": msg}))
        with urllib.request.urlopen(url, timeout=15) as r:
            print("pushed %-4s -> HTTP %s" % (status, r.status))
        time.sleep(3)
        beats = api.get_monitor_beats(mon_id, 1)
        last = beats[-1] if beats else {}
        print("   kuma now reports status=%s msg=%s" % (last.get("status"), str(last.get("msg"))[:50]))
finally:
    if mon_id is not None:
        try:
            api.delete_monitor(mon_id)
            print("deleted id=%s" % mon_id)
        except Exception as exc:
            print("DELETE FAILED for id=%s (%s) -- remove it by hand" % (mon_id, exc.__class__.__name__))
    try:
        api.disconnect()
    except Exception:
        pass
