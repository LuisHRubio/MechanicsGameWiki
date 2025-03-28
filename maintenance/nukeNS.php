<?php
/**
 * Remove pages with only 1 revision from the MediaWiki namespace, without
 * flooding recent changes, delete logs, etc.
 * Irreversible (can't use standard undelete) and does not update link tables
 *
 * This is mainly useful to run before maintenance/update.php when upgrading
 * to 1.9, to prevent flooding recent changes/deletion logs.  It's intended
 * to be conservative, so it's possible that a few entries will be left for
 * deletion by the upgrade script.  It's also possible that it hasn't been
 * tested thouroughly enough, and will delete something it shouldn't; so
 * back up your DB if there's anything in the MediaWiki that is important to
 * you.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Maintenance
 * @author Steve Sanbeg
 * based on nukePage by Rob Church
 */

// @codeCoverageIgnoreStart
require_once __DIR__ . '/Maintenance.php';
// @codeCoverageIgnoreEnd

use MediaWiki\Maintenance\Maintenance;
use MediaWiki\Title\Title;

/**
 * Maintenance script that removes pages with only one revision from the
 * MediaWiki namespace.
 *
 * @ingroup Maintenance
 */
class NukeNS extends Maintenance {
	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Remove pages with only 1 revision from any namespace' );
		$this->addOption( 'delete', "Actually delete the page" );
		$this->addOption( 'ns', 'Namespace to delete from, default NS_MEDIAWIKI', false, true );
		$this->addOption( 'all', 'Delete everything regardless of revision count' );
	}

	public function execute() {
		$ns = $this->getOption( 'ns', NS_MEDIAWIKI );
		$delete = $this->hasOption( 'delete' );
		$all = $this->hasOption( 'all' );
		$dbw = $this->getPrimaryDB();
		$this->beginTransaction( $dbw, __METHOD__ );

		$res = $dbw->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => $ns ] )
			->caller( __METHOD__ )->fetchResultSet();

		$n_deleted = 0;

		foreach ( $res as $row ) {
			// echo "$ns_name:".$row->page_title, "\n";
			$title = Title::makeTitle( $ns, $row->page_title );
			$id = $title->getArticleID();

			// Get corresponding revisions
			$revs = $dbw->newSelectQueryBuilder()
				->select( 'rev_id' )
				->from( 'revision' )
				->where( [ 'rev_page' => $id ] )
				->caller( __METHOD__ )->fetchFieldValues();
			$count = count( $revs );

			// skip anything that looks modified (i.e. multiple revs)
			if ( $all || $count == 1 ) {
				# echo $title->getPrefixedText(), "\t", $count, "\n";
				$this->output( "delete: " . $title->getPrefixedText() . "\n" );

				// as much as I hate to cut & paste this, it's a little different, and
				// I already have the id & revs
				if ( $delete ) {
					$dbw->newDeleteQueryBuilder()
						->deleteFrom( 'page' )
						->where( [ 'page_id' => $id ] )
						->caller( __METHOD__ )->execute();
					$this->commitTransaction( $dbw, __METHOD__ );
					// Delete revisions as appropriate
					/** @var NukePage $child */
					$child = $this->runChild( NukePage::class, 'nukePage.php' );
					'@phan-var NukePage $child';
					$child->deleteRevisions( $revs );
					$n_deleted++;
				}
			} else {
				$this->output( "skip: " . $title->getPrefixedText() . "\n" );
			}
		}
		$this->commitTransaction( $dbw, __METHOD__ );

		if ( $n_deleted > 0 ) {
			$this->purgeRedundantText( true );

			# update statistics - better to decrement existing count, or just count
			# the page table?
			$pages = $dbw->newSelectQueryBuilder()
				->select( 'ss_total_pages' )
				->from( 'site_stats' )
				->caller( __METHOD__ )->fetchField();
			$pages -= $n_deleted;
			$dbw->newUpdateQueryBuilder()
				->update( 'site_stats' )
				->set( [ 'ss_total_pages' => $pages ] )
				->where( [ 'ss_row_id' => 1 ] )
				->caller( __METHOD__ )
				->execute();
		}

		if ( !$delete ) {
			$this->output( "To update the database, run the script with the --delete option.\n" );
		}
	}
}

// @codeCoverageIgnoreStart
$maintClass = NukeNS::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
