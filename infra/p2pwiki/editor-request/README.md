# Editor-access request flow

Self-service "request editor access" form for the P2P Foundation Wiki, with
one-click email approval. Editing the wiki requires an account, and account
self-registration is disabled — this gives non-admins a way to ask, and gives
the approver (Michel) a single email with Approve / Deny links.

## Flow

1. Visitor opens `https://wiki.p2pfoundation.net/editor-request/`, enters name + email.
2. `index.php` stores a pending request and runs `er_cli.php notify-approver`,
   which emails the approver an Approve and a Deny link (each carries an
   HMAC token tied to the request id).
3. Approver clicks a link → `decide.php` shows a confirmation page (GET never
   mutates, so mail-scanner prefetching can't auto-approve).
4. Approver clicks Confirm → `decide.php` runs `er_cli.php approve|deny`:
   - **approve**: creates the wiki account (same path as MediaWiki's
     `createAndPromote.php`), sets + confirms the email, sets a temporary
     password, and emails the requester their login.
   - **deny**: discards the request and emails the requester.

## Files

| File | Role |
|------|------|
| `index.php` | Public request form (name + email), honeypot + per-IP rate limit |
| `decide.php` | Approver confirm page + action handler (web) |
| `er_cli.php` | MediaWiki maintenance CLI: account creation + mail (never web-callable) |
| `lib.php` / `config.php` | Shared helpers + settings |
| `_header.php` / `_footer.php` | Page chrome |

## Security notes

- The web layer only ever passes a validated 32-hex request id to the CLI — no
  user-controlled strings on the command line.
- Approve/Deny links use a per-request `hash_hmac` token (secret in
  `<data>/secret.key`, generated on first use); state-changing actions require a
  POST confirm, so link prefetching is inert.
- Request state lives in `ER_DATA_DIR` (default `/var/editor-request-data`),
  **outside** the web docroot. `er_cli.php` refuses to run under a web SAPI.
- Mail goes through MediaWiki's configured mailer, so SMTP creds stay in
  `LocalSettings.php`.

## Deploy (docker-compose)

Mount the code read-only and a writable data dir into the `p2pwiki` container:

```yaml
    volumes:
      - ./editor-request:/var/www/html/editor-request:ro
      - ./editor-request-data:/var/editor-request-data
    environment:
      ER_APPROVER_EMAIL: michel@p2pfoundation.net
      ER_BASE_URL: https://wiki.p2pfoundation.net
      ER_FROM_EMAIL: noreply@p2pfoundation.net
      ER_DATA_DIR: /var/editor-request-data
```

`./editor-request-data` must be writable by the container's www-data (UID 33):
`mkdir -p editor-request-data && chown -R 33:33 editor-request-data`.

Recreate the container after changes (`docker compose up -d`) — these are
bind-mounted single files/dirs, so editing in place + `sed -i` does **not**
update the running container (inode swap); a recreate is required.
