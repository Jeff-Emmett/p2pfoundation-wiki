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

Since dev-ops TASK-631 the password is no longer a literal: LocalSettings reads it at
runtime with ``p2pwikiSecret('mw_smtp_password')`` from the Infisical-rendered
/run/secrets/<name> inside the p2pwiki container (read from RENDERED_DIR when set, else
with ``docker exec``). Both shapes are checked.

THE PASSWORD IS JUDGED BY THE MAIL SERVER, not by a copy. A LOGIN-ONLY SMTP check (no mail
is sent) with the password the wiki will actually use answers the real question. Measured
2026-10-17: the reference copy at SMTP_SECRET had gone stale -- the wiki's Infisical value
logged in, the file's did not -- so comparing against the file reported drift on a working
wiki, and before that the literal-only parser reported "no password" on it. A login
catches a restored pre-rotation password too: a stale password fails it. Set
SMTP_LOGIN_STUB=ok|fail|error to test without a network.
"""
import os
import re
import smtplib
import subprocess
import sys

LS = os.environ.get("LS_FILE", "/opt/websites/p2pwiki/LocalSettings.php")

RENDERED_DIR = os.environ.get("RENDERED_DIR", "")
CONTAINER = os.environ.get("P2PWIKI_CONTAINER", "p2pwiki")

SQ = chr(39)


def rendered_secret(name):
    """The bytes of /run/secrets/<name> as the wiki sees them, or None when unreadable.
    Never printed: the caller only hashes it."""
    if not re.fullmatch(r"[A-Za-z0-9_.-]+", name):
        return None
    if RENDERED_DIR:
        try:
            with open(os.path.join(RENDERED_DIR, name), "rb") as fh:
                return fh.read()
        except OSError:
            return None
    try:
        p = subprocess.run(["docker", "exec", CONTAINER, "cat", "/run/secrets/" + name],
                           capture_output=True, timeout=20)
    except (OSError, subprocess.TimeoutExpired):
        return None
    return p.stdout if p.returncode == 0 else None


def php_literal_value(lit):
    """Unescape a PHP single- or double-quoted string literal (the subset that
    can appear in a generated password line: backslash and the quote char)."""
    quote, body = lit[0], lit[1:-1]
    body = body.replace("\\\\", "\x00").replace("\\" + quote, quote).replace("\x00", "\\")
    return body


def smtp_login(host, port, user, password):
    """'ok' | 'fail' | an error class name. LOGIN ONLY: nothing is sent."""
    stub = os.environ.get("SMTP_LOGIN_STUB")
    if stub:
        return stub
    implicit = host.startswith("tls://") or host.startswith("ssl://")
    name = re.sub(r"^(tls|ssl)://", "", host)
    try:
        s = smtplib.SMTP_SSL(name, port, timeout=20) if implicit else smtplib.SMTP(name, port, timeout=20)
        if not implicit:
            s.starttls()
        try:
            s.login(user, password.decode("utf-8", "replace"))
        except smtplib.SMTPAuthenticationError:
            return "fail"
        finally:
            try:
                s.quit()
            except Exception:
                pass
        return "ok"
    except Exception as exc:  # unreachable, TLS, timeout: not a verdict about the password
        return exc.__class__.__name__


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
    user = re.search(r'"username"\s*=>\s*"([^"]*)"', s)
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
    ref = re.search(
        r'"password"\s*=>\s*p2pwikiSecret\(\s*([' + SQ + r'"])([A-Za-z0-9_.-]+)\1\s*\)', s
    )
    have = None
    if lit is not None:
        have = php_literal_value(lit.group(1)).encode()
    elif ref is not None:
        # p2pwikiSecret() strips trailing CR/LF; compare the same way.
        raw = rendered_secret(ref.group(2))
        if raw is None:
            problems.append("rendered secret %s unreadable, so the password was not compared" % ref.group(2))
        else:
            have = raw.rstrip(b"\r\n")
            if not have:
                problems.append("rendered secret %s is EMPTY -- the wiki fails closed and sends no mail" % ref.group(2))
                have = None
    else:
        problems.append("no $wgSMTP password found (neither a literal nor p2pwikiSecret())")
    if have is not None and host is not None and port is not None:
        verdict = smtp_login(host.group(1), int(port.group(1)), user.group(1) if user else "", have)
        if verdict == "fail":
            problems.append("the mail server REJECTS the wiki's SMTP password -- a pre-rotation copy restored?")
        elif verdict != "ok":
            problems.append("SMTP login could not be tried (%s), so the password was not checked" % verdict)

    print(("drift:" + "; ".join(problems)) if problems else "ok:")


if __name__ == "__main__":
    main()
    sys.exit(0)
