<?php
/**
 * MediaWiki-bootstrapped CLI backend for the editor-request flow.
 *
 * Invoked by the web endpoints via shell_exec with a single validated,
 * hex-only request id — never with user-controlled strings on the command
 * line. Runs inside the p2pwiki container as www-data.
 *
 *   php er_cli.php notify-approver <id>   email approver the approve/deny links
 *   php er_cli.php approve        <id>    create account + email requester
 *   php er_cli.php deny           <id>    discard + email requester
 *
 * Account creation mirrors MediaWiki's own maintenance/createAndPromote.php.
 * Mail is sent through MediaWiki's configured mailer ($wgSMTP), so SMTP
 * credentials live only in LocalSettings.php.
 */

if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 403 );
	exit( "CLI only\n" );
}

require_once __DIR__ . '/lib.php';

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/html';
require_once "$IP/maintenance/Maintenance.php";

use MediaWiki\Auth\AuthManager;
use MediaWiki\MediaWikiServices;

class EditorRequestCli extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Editor-request flow backend (notify-approver|approve|deny)' );
		$this->addArg( 'subcommand', 'notify-approver | approve | deny' );
		$this->addArg( 'id', 'request id (32 hex chars)' );
	}

	public function execute() {
		$sub = $this->getArg( 0 );
		$id = $this->getArg( 1 );
		if ( !er_valid_id( $id ) ) {
			$this->fatalError( 'invalid id' );
		}
		$rec = er_load( $id );
		if ( !$rec ) {
			$this->fatalError( 'request not found' );
		}

		switch ( $sub ) {
			case 'notify-approver':
				$this->notifyApprover( $rec );
				break;
			case 'approve':
				$this->approve( $rec );
				break;
			case 'deny':
				$this->deny( $rec );
				break;
			default:
				$this->fatalError( 'unknown subcommand: ' . $sub );
		}
	}

	/** Send mail via MediaWiki's configured mailer; returns bool ok. */
	private function sendMail( $to, $subject, $body, $replyTo = null ) {
		$cfg = er_config();
		// Probe the global name first: on MW 1.40 probing the namespaced one
		// autoloads MailAddress.php again on every call, so a second send in
		// the same process dies with "Cannot declare class MailAddress".
		$maCls = class_exists( 'MailAddress' ) ? 'MailAddress' : 'MediaWiki\\Mail\\MailAddress';
		$from = new $maCls( $cfg['from_email'], $cfg['from_name'] );
		$toAddr = new $maCls( $to );
		$opts = $replyTo ? [ 'replyTo' => new $maCls( $replyTo ) ] : [];

		$services = MediaWikiServices::getInstance();
		if ( method_exists( $services, 'getEmailer' ) ) {
			$status = $services->getEmailer()->send( [ $toAddr ], $from, $subject, $body, null, $opts );
			return $status->isOK();
		}
		$umCls = class_exists( 'UserMailer' ) ? 'UserMailer' : 'MediaWiki\\Mail\\UserMailer';
		$status = $umCls::send( $toAddr, $from, $subject, $body, $opts );
		return $status->isOK();
	}

	private function notifyApprover( array $rec ) {
		$cfg = er_config();
		$id = $rec['id'];
		$tok = er_token( $id );
		$base = rtrim( $cfg['base_url'], '/' ) . '/editor-request/decide.php';
		$q = '?id=' . rawurlencode( $id ) . '&token=' . rawurlencode( $tok ) . '&action=';
		$approve = $base . $q . 'approve';
		$deny = $base . $q . 'deny';

		$body = "A new editor-access request on the P2P Foundation Wiki:\n\n"
			. "  Name:              {$rec['name']}\n"
			. "  Email:             {$rec['email']}\n"
			. "  Proposed username: {$rec['username']}\n"
			. '  Requested:         ' . gmdate( 'Y-m-d H:i', (int)$rec['ts'] ) . " UTC\n"
			. "  From IP:           {$rec['ip']}\n\n"
			. "APPROVE  (creates the account and emails them a login):\n  $approve\n\n"
			. "DENY  (discards the request and notifies them):\n  $deny\n\n"
			. "Each link opens a confirmation page and can be used once. No login required.\n";

		// One message per approver, so one bad address cannot sink the others.
		// The request counts as submitted if at least one approver got it.
		$sent = [];
		foreach ( $cfg['approver_emails'] as $to ) {
			$ok = $this->sendMail( $to, 'Wiki editor request: ' . $rec['name'], $body );
			$sent[$to] = $ok ? 'sent' : 'failed';
			$this->output( $sent[$to] . " -> $to\n" );
		}
		$rec['notify_status'] = in_array( 'sent', $sent, true ) ? 'sent' : 'failed';
		$rec['notified_to'] = $sent;
		er_save( $id, $rec );
	}

	/** Tell every approver a request is settled, so nobody acts on a stale link. */
	private function notifyDecision( array $rec, $outcome ) {
		$body = "The editor-access request from {$rec['name']} <{$rec['email']}> "
			. "has been $outcome"
			. ( isset( $rec['final_username'] ) ? " (account: {$rec['final_username']})" : '' )
			. ".\n\nNo further action is needed; the links in the original email are now spent.\n";
		foreach ( er_config()['approver_emails'] as $to ) {
			$this->sendMail( $to, "Wiki editor request $outcome: {$rec['name']}", $body );
		}
	}

	private function approve( array $rec ) {
		$id = $rec['id'];
		// Re-load + single-use guard under a lock to avoid double-approve.
		$lock = fopen( er_requests_dir() . '/' . $id . '.lock', 'c' );
		if ( $lock ) {
			flock( $lock, LOCK_EX );
		}
		$rec = er_load( $id );
		if ( ( $rec['status'] ?? '' ) !== 'pending' ) {
			$this->output( 'already ' . ( $rec['status'] ?? '?' ) . "\n" );
			return;
		}

		$services = MediaWikiServices::getInstance();
		$uf = $services->getUserFactory();

		// Resolve a unique username.
		$base = $rec['username'];
		$name = $base;
		$i = 1;
		$user = $uf->newFromName( $name );
		while ( $user && $user->idForName() !== 0 ) {
			$i++;
			$name = $base . ' ' . $i;
			$user = $uf->newFromName( $name );
		}
		if ( !$user ) {
			$this->failRec( $rec, 'invalid username derived from name' );
			$this->fatalError( 'invalid username' );
		}

		// Create the account through AuthManager (same path as createAndPromote.php).
		$status = $services->getAuthManager()->autoCreateUser(
			$user,
			AuthManager::AUTOCREATE_SOURCE_MAINT,
			false
		);
		if ( !$status->isGood() ) {
			$msg = $status->getMessage( false, false, 'en' )->text();
			$this->failRec( $rec, 'account creation failed: ' . $msg );
			$this->fatalError( 'create failed: ' . $msg );
		}

		// Set a temporary password.
		$pw = er_gen_password();
		$pwStatus = $user->changeAuthenticationData( [
			'username' => $user->getName(),
			'password' => $pw,
			'retype'   => $pw,
		] );
		if ( !$pwStatus->isGood() ) {
			$this->output( "warning: could not set password: "
				. $pwStatus->getMessage( false, false, 'en' )->text() . "\n" );
		}

		// Set + confirm their email so notifications work and they can reset.
		$user->setEmail( $rec['email'] );
		$user->confirmEmail();
		$user->saveSettings();

		// Keep site_stats user count correct.
		SiteStatsUpdate::factory( [ 'users' => 1 ] )->doUpdate();

		// Email the requester their login.
		$cfg = er_config();
		$login = rtrim( $cfg['base_url'], '/' ) . '/index.php?title=Special:UserLogin';
		$change = rtrim( $cfg['base_url'], '/' ) . '/index.php?title=Special:ChangePassword';
		$body = "Hello {$rec['name']},\n\n"
			. "Your request for editor access to the P2P Foundation Wiki has been approved.\n\n"
			. "  Username:           {$user->getName()}\n"
			. "  Temporary password: {$pw}\n\n"
			. "Log in here:\n  $login\n\n"
			. "Please set your own password after logging in:\n  $change\n\n"
			. "Welcome, and thank you for contributing!\n";
		$mailed = $this->sendMail( $rec['email'], 'Your P2P Foundation Wiki editor access is approved', $body, $cfg['reply_to'] );

		$rec['status'] = 'approved';
		$rec['final_username'] = $user->getName();
		$rec['requester_mailed'] = $mailed ? 'sent' : 'failed';
		$rec['decided_ts'] = time();
		er_save( $id, $rec );
		$this->output( "approved {$user->getName()} (mail " . ( $mailed ? 'sent' : 'failed' ) . ")\n" );
		$this->notifyDecision( $rec, 'approved' );
	}

	private function deny( array $rec ) {
		$id = $rec['id'];
		$rec = er_load( $id );
		if ( ( $rec['status'] ?? '' ) !== 'pending' ) {
			$this->output( 'already ' . ( $rec['status'] ?? '?' ) . "\n" );
			return;
		}
		$body = "Hello {$rec['name']},\n\n"
			. "Thank you for your interest in editing the P2P Foundation Wiki. "
			. "After review, we're not able to grant editor access at this time.\n\n"
			. "If you think this was a mistake, just reply to this email.\n";
		$mailed = $this->sendMail( $rec['email'], 'About your P2P Foundation Wiki editor request', $body, er_config()['reply_to'] );

		$rec['status'] = 'denied';
		$rec['requester_mailed'] = $mailed ? 'sent' : 'failed';
		$rec['decided_ts'] = time();
		er_save( $id, $rec );
		$this->output( "denied (mail " . ( $mailed ? 'sent' : 'failed' ) . ")\n" );
		$this->notifyDecision( $rec, 'denied' );
	}

	private function failRec( array $rec, $why ) {
		$rec['last_error'] = $why;
		er_save( $rec['id'], $rec );
	}
}

$maintClass = EditorRequestCli::class;
require_once RUN_MAINTENANCE_IF_MAIN;
