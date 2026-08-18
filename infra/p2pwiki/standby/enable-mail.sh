#!/usr/bin/env bash
# Turn on email, and with it Special:PasswordReset.
#
# STAGED, NOT ACTIVE. Run it once the redeployed Mailcow is reachable from this
# box. It refuses to run without ./mail.env so it cannot half-enable email and
# leave the wiki generating reset links that go nowhere — a user who is told
# "check your inbox" and never receives anything is worse off than one told
# plainly to contact an admin.
#
# THE PART THAT IS NOT ABOUT SMTP. Password reset needs somewhere to send to,
# and NO account here has an email address — the user table never left Netcup,
# so these accounts were created fresh with no addresses at all. SMTP alone
# changes nothing for them.
#
# The bootstrap order that actually works:
#   1. this script (SMTP works, reset route open)
#   2. each editor logs in with the temporary password from standby-accounts.txt
#   3. Special:Preferences -> set email -> confirm via the link they now receive
#   4. from then on Special:PasswordReset works for them unaided
#
# Step 3 is why this cannot be done before Mailcow exists: confirming an address
# requires mail to already work. There is no ordering that avoids that.
set -euo pipefail
cd "$(dirname "$0")"

SEND_TEST="${1:-}"
TEST_TO="${2:-}"

[ -f mail.env ] || { echo "no ./mail.env — copy mail.env.example and fill it in" >&2; exit 2; }
set -a; . ./mail.env; set +a
: "${MW_SMTP_HOST:?}" "${MW_SMTP_PORT:?}" "${MW_SMTP_USER:?}" "${MW_PASSWORD_SENDER:?}"

[ -f smtp-password ] || { echo "no ./smtp-password (chmod 600, the mailbox password, no trailing newline)" >&2; exit 2; }
chmod 600 smtp-password

echo "== 1. can the wiki container even reach the mail host? =="
# Checked BEFORE writing config. A container that cannot open the port will
# produce reset mail that vanishes, and the wiki reports success either way.
HOSTONLY="${MW_SMTP_HOST#ssl://}"; HOSTONLY="${HOSTONLY#tls://}"
if docker exec p2pwiki-standby bash -c "timeout 8 bash -c '</dev/tcp/$HOSTONLY/$MW_SMTP_PORT' 2>/dev/null"; then
  echo "   $HOSTONLY:$MW_SMTP_PORT reachable"
else
  echo "   CANNOT reach $HOSTONLY:$MW_SMTP_PORT from inside the container." >&2
  echo "   Fix networking first — if Mailcow is on this host, the wiki container" >&2
  echo "   needs to be on its network or use the host address, not localhost." >&2
  exit 3
fi

echo
echo "== 2. mount the password into the container =="
docker cp smtp-password p2pwiki-standby:/run/smtp-password >/dev/null
docker exec p2pwiki-standby chmod 400 /run/smtp-password

echo
echo "== 3. LocalSettings =="
if grep -q "MAIL AND PASSWORD RESET" LocalSettings.php; then
  echo "   already present — edit the block by hand to change settings"
else
  cat >> LocalSettings.php <<PHP

# ---------------------------------------------------------------------------
# MAIL AND PASSWORD RESET - enabled $(date -u +%F)
# Overrides the earlier standby block, which disabled email because Mailcow was
# on the host this wiki was standing in for.
# ---------------------------------------------------------------------------
\$wgEnableEmail         = true;
\$wgEnableUserEmail     = true;
\$wgEmailAuthentication = true;

# Both routes: by username (mail goes to that account's stored address) and by
# email address. Username-only would strand anyone who has forgotten which
# account name they used, which after a year away is most people.
\$wgPasswordResetRoutes = [ 'username' => true, 'email' => true ];

\$wgPasswordSender   = '$MW_PASSWORD_SENDER';
\$wgEmergencyContact = '$MW_PASSWORD_SENDER';
\$wgNoReplyAddress   = '$MW_PASSWORD_SENDER';

# The password is read from a file mounted at runtime, never written into this
# config. LocalSettings.php is world-readable inside the container and gets
# copied around during recovery; a mailbox password in it would leak by routine.
\$wgSMTP = [
	'host'     => '$MW_SMTP_HOST',
	'IDHost'   => '${MW_SMTP_IDHOST:-p2pfoundation.net}',
	'port'     => $MW_SMTP_PORT,
	'auth'     => true,
	'username' => '$MW_SMTP_USER',
	'password' => trim( @file_get_contents( '/run/smtp-password' ) ),
];
PHP
  echo "   appended"
fi

echo
echo "== 4. restart =="
# Not optional. wgMainCacheType is CACHE_ACCEL, and APCu in the Apache process
# is a different segment from the CLI's — config and message changes are not
# picked up until the web SAPI restarts. This cost an hour earlier today.
docker compose up -d p2pwiki-standby >/dev/null 2>&1
sleep 10

echo
echo "== 5. verify the wiki agrees email is on =="
docker exec p2pwiki-standby php -r '
  $IP="/var/www/html"; require_once "$IP/includes/WebStart.php";
' 2>/dev/null || true
curl -sS -m 20 -H "Host: wiki.p2pfoundation.net" \
  "http://127.0.0.1:18081/index.php?title=Special:PasswordReset" -o /tmp/pr.html \
  -w "   Special:PasswordReset -> %{http_code}\n"
if grep -qi "not enabled\|disabled" /tmp/pr.html; then
  echo "   STILL DISABLED — check the block above and restart again" >&2
else
  echo "   reset form is live"
fi

if [ "$SEND_TEST" = "--set-email" ] && [ -n "$TEST_TO" ]; then
  # $TEST_TO is "user address" here. resetUserEmail.php is the bootstrap that
  # breaks the chicken-and-egg: an editor cannot confirm an address without
  # working mail, and cannot receive reset mail without a stored address. A
  # sysop with shell access can set it directly, and the editor can then use
  # Special:PasswordReset straight away without ever needing the temporary
  # password.
  set -- $TEST_TO
  echo
  echo "== setting email for user '$1' =="
  docker exec p2pwiki-standby php /var/www/html/maintenance/resetUserEmail.php "$1" "$2"
  echo "   done — that user can now use Special:PasswordReset"
elif [ "$SEND_TEST" = "--send-test" ] && [ -n "$TEST_TO" ]; then
  echo
  echo "== test =="
  # No sendMail.php in MediaWiki 1.40 (checked), so the honest test is the real
  # path: set an address on a throwaway account and run a reset against it.
  echo "   MediaWiki 1.40 has no sendMail.php. Test the real flow instead:"
  echo "     ./enable-mail.sh --set-email 'JeffEmmett $TEST_TO'"
  echo "     then visit Special:PasswordReset and request a reset for JeffEmmett"
else
  echo
  echo "No mail sent. Deliberate: this estate does not fire real mail without"
  echo "being asked. When you want to bootstrap an editor:"
  echo "    ./enable-mail.sh --set-email 'Mbauwens michel@p2pfoundation.net'"
fi

cat <<'NOTE'

STILL TO DO, and SMTP does not fix it:
  No account has an email address. Until each editor logs in with their
  temporary password and sets one in Special:Preferences, Special:PasswordReset
  has nowhere to send. Passwords are in standby-accounts.txt (0600).

  Netcup's user table WILL bring the real addresses back with it, if that host
  is ever recovered. Nothing done here conflicts with that.
NOTE
