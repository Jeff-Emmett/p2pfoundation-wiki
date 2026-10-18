"""p2pwiki-smtp-config-check.py, both password shapes (literal, and p2pwikiSecret() since
dev-ops TASK-631), judged by an SMTP login (stubbed here via SMTP_LOGIN_STUB). Each case
runs the real script with temp files; the secret must never appear in its output.

    python3 infra/p2pwiki/monitoring/test_smtp_config_check.py
"""
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest

HERE = pathlib.Path(__file__).resolve().parent
SCRIPT = HERE / "p2pwiki-smtp-config-check.py"
PW = "correct-horse-battery-staple-42"

BLOCK = """$wgSMTP = [
    "host"     => "tls://mail.rmail.online",
    "port"     => {port},
    "auth"     => true,
    "password" => {password},
];
"""


class Check(unittest.TestCase):
    def run_check(self, password_expr, port=465, login="ok", rendered=None, rendered_name="mw_smtp_password"):
        d = pathlib.Path(tempfile.mkdtemp(prefix="smtpck-"))
        ls = d / "LocalSettings.php"
        ls.write_text("<?php\n" + BLOCK.format(port=port, password=password_expr))
        rdir = d / "rendered"
        rdir.mkdir()
        if rendered is not None:
            (rdir / rendered_name).write_text(rendered)
        env = dict(os.environ, LS_FILE=str(ls), RENDERED_DIR=str(rdir), SMTP_LOGIN_STUB=login)
        out = subprocess.run([sys.executable, str(SCRIPT)], env=env, capture_output=True, text=True, timeout=30)
        self.assertEqual(out.returncode, 0)
        self.assertNotIn(PW, out.stdout + out.stderr)  # never the password
        return out.stdout.strip()

    # the original literal shape still works
    def test_literal_matching(self):
        self.assertEqual(self.run_check('"%s"' % PW), "ok:")

    def test_literal_rejected_by_the_mail_server_is_drift(self):
        self.assertIn("REJECTS", self.run_check('"old-password"', login="fail"))

    # the TASK-631 file shape: the false alarm this change removes
    def test_rendered_secret_matching_is_ok(self):
        self.assertEqual(self.run_check("p2pwikiSecret( 'mw_smtp_password' )", rendered=PW + "\n"), "ok:")

    def test_rendered_secret_rejected_is_drift(self):
        self.assertIn("REJECTS", self.run_check("p2pwikiSecret( 'mw_smtp_password' )", rendered="stale", login="fail"))

    def test_mail_server_unreachable_is_reported_not_passed(self):
        self.assertIn("could not be tried", self.run_check("p2pwikiSecret('mw_smtp_password')", rendered=PW, login="ConnectionRefusedError"))

    def test_rendered_secret_missing_is_reported_not_passed(self):
        self.assertIn("unreadable", self.run_check("p2pwikiSecret('mw_smtp_password')"))

    def test_rendered_secret_empty_is_drift_because_the_wiki_fails_closed(self):
        self.assertIn("EMPTY", self.run_check("p2pwikiSecret('mw_smtp_password')", rendered="\n"))

    def test_neither_shape_is_drift(self):
        self.assertIn("no $wgSMTP password found", self.run_check("getenv('X')"))

    def test_tls_on_587_is_still_caught(self):
        self.assertIn("implicit TLS needs 465", self.run_check("p2pwikiSecret('mw_smtp_password')", port=587, rendered=PW))


if __name__ == "__main__":
    unittest.main()
