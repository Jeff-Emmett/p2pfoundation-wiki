#!/usr/bin/env python3
"""Compare the $wgSMTP block in p2pwiki's LocalSettings.php against what it must be.

Called by p2pwiki-extension-drift-probe.sh. Prints one line:

    ok:
    drift:<reason>; <reason>
    unchecked:<why>

It reports STATES, never a fingerprint, and never the password itself -- same
rule as the deploy-webhook secret drift probe.

Two things are asserted:
  * tls:// is implicit TLS and therefore belongs on 465. Paired with 587 the
    connection fails before anything is queued, which is how six weeks of
    password-reset mail went missing after the 2026-08-24 failback.
  * the password in the file is still the one in the secret file. The same
    failback restored a pre-rotation copy.
"""
import hashlib
import os
import re
import sys

LS = os.environ.get("LS_FILE", "/opt/websites/p2pwiki/LocalSettings.php")
SECRET = os.environ.get("SMTP_SECRET", "/opt/secrets/mailcow/p2pwiki_noreply_smtp_password")

SQ = chr(39)


def php_literal_value(lit):
    """Unescape a PHP single- or double-quoted string literal (the subset that
    can appear in a generated password line: backslash and the quote char)."""
    quote, body = lit[0], lit[1:-1]
    body = body.replace("\\\\", "\x00").replace("\\" + quote, quote).replace("\x00", "\\")
    return body


def main():
    try:
        with open(LS, encoding="utf-8", errors="replace") as fh:
            s = fh.read()
    except OSError as exc:
        print("unchecked:LocalSettings unreadable (%s)" % exc.__class__.__name__)
        return

    problems = []

    host = re.search(r'"host"\s*=>\s*"([^"]*)"', s)
    port = re.search(r'"port"\s*=>\s*(\d+)', s)
    if host is None or port is None:
        problems.append("no $wgSMTP host/port pair found")
    elif host.group(1).startswith("tls://") and port.group(1) != "465":
        problems.append(
            "tls:// paired with port %s -- implicit TLS needs 465, and on 587 the "
            "connection fails before any mail is queued" % port.group(1)
        )

    lit = re.search(
        r'"password"\s*=>\s*("(?:\\.|[^"\\])*"|' + SQ + r"(?:\\.|[^" + SQ + r"\\])*" + SQ + ")",
        s,
    )
    if lit is None:
        problems.append("no $wgSMTP password literal found")
    elif not os.access(SECRET, os.R_OK):
        problems.append("secret file unreadable, so the password was not compared")
    else:
        with open(SECRET, "rb") as fh:
            want = fh.read().strip()
        have = php_literal_value(lit.group(1)).encode()
        if hashlib.sha256(have).digest() != hashlib.sha256(want).digest():
            problems.append(
                "password does not match %s -- a pre-rotation copy restored?" % SECRET
            )

    print(("drift:" + "; ".join(problems)) if problems else "ok:")


if __name__ == "__main__":
    main()
    sys.exit(0)
