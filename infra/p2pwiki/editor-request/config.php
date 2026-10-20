<?php
/**
 * Editor-request flow configuration.
 *
 * Env vars (set in docker-compose) override the defaults below, so the
 * approver address can be swapped for testing without editing this file.
 */
return [
	// Who receives the one-click approve/deny email: a comma-separated list.
	// Every approver gets the same links; the first to confirm decides, and
	// the rest are told it has been handled.
	'approver_emails' => array_values( array_filter( array_map( 'trim', explode( ',',
		getenv( 'ER_APPROVER_EMAIL' ) ?: 'michel@p2pfoundation.net' ) ) ) ),
	// Where a requester's reply lands (their mail comes From: noreply@).
	'reply_to'       => getenv( 'ER_REPLY_TO' ) ?: 'michel@p2pfoundation.net',
	// Envelope/From for outbound mail (matches the wiki's $wgPasswordSender).
	'from_email'     => getenv( 'ER_FROM_EMAIL' ) ?: 'noreply@p2pfoundation.net',
	'from_name'      => 'P2P Foundation Wiki',
	// Public base URL, used to build the approve/deny links.
	'base_url'       => getenv( 'ER_BASE_URL' ) ?: 'https://wiki.p2pfoundation.net',
	// Writable state dir, OUTSIDE the web docroot.
	'data_dir'       => getenv( 'ER_DATA_DIR' ) ?: '/var/editor-request-data',
	// MediaWiki install path ($IP) inside the container.
	'wiki_ip'        => getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/html',
	// Anti-spam: max form submissions per client IP per hour.
	'rate_per_hour'  => 3,
	// Site-wide ceiling on new requests per day, so a botnet that beats every
	// other check can still only put this many emails in the editors' inboxes.
	'max_per_day'    => 15,
	// A person needs a few seconds to type a name and an email; bots don't.
	'min_fill_seconds' => 4,
	'form_ttl_seconds' => 7200,
	// Cloudflare Turnstile public site key. The widget is enforced only when
	// this is set AND <data_dir>/turnstile.secret exists.
	'turnstile_sitekey' => getenv( 'ER_TURNSTILE_SITEKEY' ) ?: '',
];
