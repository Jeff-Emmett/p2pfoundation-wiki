<?php
// One-off dump of the article/category/link graph as TSV, using MediaWiki's
// own DB credentials. Writes /tmp/pages.tsv, /tmp/cats.tsv, /tmp/links.tsv.
require_once __DIR__ . '/Maintenance.php';

class DumpGraph extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Dump article/category/link graph as TSV' );
	}

	public function execute() {
		$dbr = $this->getDB( DB_REPLICA );

		$fh = fopen( '/tmp/pages.tsv', 'w' );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_title', 'page_len' ] )
			->from( 'page' )
			->where( [ 'page_namespace' => 0, 'page_is_redirect' => 0 ] )
			->caller( __METHOD__ )->fetchResultSet();
		$n = 0;
		foreach ( $res as $row ) {
			fwrite( $fh, $row->page_id . "\t" . $row->page_title . "\t" . $row->page_len . "\n" );
			$n++;
		}
		fclose( $fh );
		$this->output( "pages: $n\n" );

		$fh = fopen( '/tmp/cats.tsv', 'w' );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'cl_from', 'cl_to' ] )
			->from( 'categorylinks' )
			->caller( __METHOD__ )->fetchResultSet();
		$n = 0;
		foreach ( $res as $row ) {
			fwrite( $fh, $row->cl_from . "\t" . $row->cl_to . "\n" );
			$n++;
		}
		fclose( $fh );
		$this->output( "categorylinks: $n\n" );

		$fh = fopen( '/tmp/links.tsv', 'w' );
		$res = $dbr->newSelectQueryBuilder()
			->select( [ 'pl_from', 'pl_title' ] )
			->from( 'pagelinks' )
			->where( [ 'pl_from_namespace' => 0, 'pl_namespace' => 0 ] )
			->caller( __METHOD__ )->fetchResultSet();
		$n = 0;
		foreach ( $res as $row ) {
			fwrite( $fh, $row->pl_from . "\t" . $row->pl_title . "\n" );
			$n++;
		}
		fclose( $fh );
		$this->output( "pagelinks: $n\n" );
	}
}

$maintClass = DumpGraph::class;
require_once RUN_MAINTENANCE_IF_MAIN;
