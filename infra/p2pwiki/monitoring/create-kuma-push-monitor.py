# Create (or reuse) the push monitor for the p2pwiki config drift probe.
#
# Runs INSIDE the kuma-alert-agent container, which already has uptime_kuma_api
# and KUMA_URL/KUMA_USERNAME/KUMA_PASSWORD in its environment -- so no credential
# is read, passed or printed by the caller.
#
# Prints NAME, MONITOR_ID, INTERVAL, NOTIFICATION, CREATED, and PUSH_TOKEN last
# on its own line so the caller can take it without taking the rest.
import os

from uptime_kuma_api import UptimeKumaApi, MonitorType

NAME = "p2pwiki config drift (extensions + <ref> + $wgSMTP)"
INTERVAL = 25200          # 7h: the probe runs every 6h, so one window of slack
NOTIF_WANTED = "Mailcow Email Alerts"

api = UptimeKumaApi(os.environ["KUMA_URL"], timeout=30, wait_events=3)
try:
    api.login(os.environ["KUMA_USERNAME"], os.environ["KUMA_PASSWORD"])

    notif_id = None
    for n in api.get_notifications():
        if n.get("name") == NOTIF_WANTED:
            notif_id = n["id"]
            break

    existing = next((m for m in api.get_monitors() if m.get("name") == NAME), None)
    if existing:
        mon, created = existing, "no (reused)"
    else:
        kwargs = dict(type=MonitorType.PUSH, name=NAME, interval=INTERVAL, maxretries=0)
        if notif_id is not None:
            kwargs["notificationIDList"] = [notif_id]
        res = api.add_monitor(**kwargs)
        mon = api.get_monitor(res["monitorID"])
        created = "yes"

    print("NAME=%s" % mon["name"])
    print("MONITOR_ID=%s" % mon["id"])
    print("INTERVAL=%s" % mon["interval"])
    print("NOTIFICATION=%s" % (NOTIF_WANTED if notif_id is not None else "NONE FOUND"))
    print("CREATED=%s" % created)
    print("PUSH_TOKEN=%s" % mon["pushToken"])
finally:
    try:
        api.disconnect()
    except Exception:
        pass
