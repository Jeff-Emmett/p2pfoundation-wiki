#!/usr/bin/env bash
# Give a wiki user a fresh password, and PROVE it works before handing it over.
#
#   ./reset-user-password.sh Mbauwens
#
# The password is generated on this host, written to standby-accounts.txt (0600)
# and never printed to stdout — so it cannot end up in a terminal transcript, in
# shell history, or in an agent's context. Read it out of that file when you are
# ready to pass it on, and pass it on out of band.
#
# WHY IT VERIFIES. Setting a password and assuming it works is how you hand
# someone credentials that fail, at which point they conclude the wiki is broken
# rather than that the password is wrong. This logs in through the PUBLIC url
# with the new password before reporting success, so what is verified is the
# thing the person will actually do.
set -euo pipefail
cd "$(dirname "$0")"

# Report WHERE a failure happened without echoing what was being handled. The
# obvious debugging move here is `bash -x`, and it is the wrong one: this script
# handles a plaintext password, and tracing it prints that password to the
# terminal, into scrollback, and into any transcript watching. It has already
# happened once. A line number is enough.
trap 'echo "reset-user-password.sh: failed at line $LINENO (exit $?)" >&2' ERR

USER_NAME="${1:?usage: ./reset-user-password.sh <username>}"
API=https://wiki.p2pfoundation.net/api.php

docker exec p2pwiki-standby php /var/www/html/maintenance/createAndPromote.php \
  --force "$USER_NAME" >/dev/null 2>&1 || true

PW=$(openssl rand -base64 18)
if ! docker exec p2pwiki-standby php /var/www/html/maintenance/changePassword.php \
       --user="$USER_NAME" --password="$PW" >/dev/null 2>&1; then
  echo "FAILED to set the password for $USER_NAME" >&2; exit 2
fi

JAR=$(mktemp); trap 'rm -f "$JAR"' EXIT
api(){ curl -sS -m 40 -b "$JAR" -c "$JAR" "$@"; }
LT=$(api -X POST "$API" -d "action=query&meta=tokens&type=login&format=json" \
     | python3 -c 'import sys,json;print(json.load(sys.stdin)["query"]["tokens"]["logintoken"])')
STATUS=$(api -X POST "$API" \
  --data-urlencode action=clientlogin --data-urlencode format=json \
  --data-urlencode loginreturnurl=https://wiki.p2pfoundation.net/ \
  --data-urlencode logintoken="$LT" \
  --data-urlencode username="$USER_NAME" --data-urlencode password="$PW" \
  | python3 -c 'import sys,json;print(json.load(sys.stdin).get("clientlogin",{}).get("status","?"))')

if [ "$STATUS" != "PASS" ]; then
  echo "password was set but the login did NOT work (status: $STATUS)" >&2; exit 3
fi

touch standby-accounts.txt && chmod 600 standby-accounts.txt
# Replace any previous line for this user so the file never holds two passwords
# for one person — the older one being the wrong one, indistinguishably.
grep -v -P "^\Q$USER_NAME\E\t" standby-accounts.txt > standby-accounts.tmp 2>/dev/null || true
printf '%s\t%s\t%s\n' "$USER_NAME" "$PW" "$(date -u +%F)" >> standby-accounts.tmp
mv standby-accounts.tmp standby-accounts.txt
chmod 600 standby-accounts.txt
unset PW

# The user's group list used to be looked up here for the report. It is gone:
# it was decorative, it sat BETWEEN the real work and the report of it, and when
# it failed under `set -euo pipefail` the script died after the password had
# been changed and verified — printing nothing at all. The operator saw silence,
# read it as failure, and re-ran it, rotating the password again. Anything
# non-essential belongs after the report, or nowhere.

echo "$USER_NAME: password reset and LOGIN VERIFIED through wiki.p2pfoundation.net"
# (no group line here: GROUPS is a bash BUILT-IN array of the caller's unix
#  group ids, so after the lookup above was removed this printed "1000" — a
#  real value, confidently wrong, about the wrong system entirely.)
echo "  password is in standby-accounts.txt (0600) - not printed here on purpose:"
echo "    ssh spark 'grep ^$USER_NAME ~/p2pwiki-standby/standby-accounts.txt | cut -f2'"
echo "  tell them to change it at Special:ChangePassword after first login."
