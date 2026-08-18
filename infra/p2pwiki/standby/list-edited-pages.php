<?php
/**
 * Print the prefixed title of every page with a revision at or after --since.
 *
 * WHY THIS EXISTS INSTEAD OF A SQL QUERY WITH A NAMESPACE CASE STATEMENT.
 *
 * dumpBackup --pagelist matches on the PREFIXED DISPLAY title, and the prefix
 * depends on this wiki's namespace configuration, which is not the default:
 *
 *     ns 4/5    "P2P Foundation Wiki" / "... talk"   (renamed, not "Project")
 *     ns 118/119 "Draft" / "Draft talk"              (custom)
 *
 * A hand-written map produced "Project:Foo" and an unprefixed Draft title.
 * dumpBackup then matched neither, so those pages were dropped from the export
 * while the run still reported success and a page count. An editor's work would
 * have disappeared with nothing to show it had.
 *
 * Title::getPrefixedText() asks MediaWiki, which is the only component that
 * actually knows. It also stays correct if a namespace is added later.
 */

$IP = getenv( 'MW_INSTALL_PATH' ) ?: '/var/www/html';
require_once "$IP/maintenance/Maintenance.php";

class ListEditedPages extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'List prefixed titles of pages edited since a timestamp' );
		$this->addOption( 'since', 'MediaWiki timestamp, e.g. 20260818120000', true, true );
	}

	public function execute() {
		$since = $this->getOption( 'since' );
		if ( !preg_match( '/^\d{14}$/', $since ) ) {
			$this->fatalError( "--since must be exactly 14 digits (MediaWiki timestamp)" );
		}

		$db = $this->getDB( DB_REPLICA );
		$res = $db->select(
			[ 'page', 'revision' ],
			[ 'page_namespace', 'page_title' ],
			[ 'rev_timestamp >= ' . $db->addQuotes( $since ) ],
			__METHOD__,
			[ 'GROUP BY' => 'page_id' ],
			[ 'revision' => [ 'JOIN', 'rev_page = page_id' ] ]
		);

		foreach ( $res as $row ) {
			$title = Title::makeTitle( (int)$row->page_namespace, $row->page_title );
			if ( $title ) {
				$this->output( $title->getPrefixedText() . "\n" );
			}
		}
	}
}

$maintClass = ListEditedPages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
