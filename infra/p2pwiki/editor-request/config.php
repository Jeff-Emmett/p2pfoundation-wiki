<?php
/**
 * Editor-request flow configuration.
 *
 * Env vars (set in docker-compose) override the defaults below, so the
 * approver address can be swapped for testing without editing this file.
 */
return [
	// Who receives the one-click approve/deny email.
	'approver_email' => getenv( 'ER_APPROVER_EMAIL' ) ?: 'michel@p2pfoundation.net',
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
	'rate_per_hour'  => 5,
];
