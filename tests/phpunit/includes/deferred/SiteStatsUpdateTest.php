<?php

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Deferred\SiteStatsUpdate;
use MediaWiki\SiteStats\SiteStats;
use MediaWiki\SiteStats\SiteStatsInit;
use Wikimedia\TestingAccessWrapper;

/**
 * @group Database
 * @covers \MediaWiki\Deferred\SiteStatsUpdate
 * @covers \MediaWiki\SiteStats\SiteStats
 * @covers \MediaWiki\SiteStats\SiteStatsInit
 */
class SiteStatsUpdateTest extends MediaWikiIntegrationTestCase {
	public function testFactoryAndMerge() {
		$update1 = SiteStatsUpdate::factory( [ 'pages' => 1, 'users' => 2 ] );
		$update2 = SiteStatsUpdate::factory( [ 'users' => 1, 'images' => 1 ] );

		$update1->merge( $update2 );
		$wrapped = TestingAccessWrapper::newFromObject( $update1 );

		$this->assertSame( 1, $wrapped->pages );
		$this->assertEquals( 3, $wrapped->users );
		$this->assertSame( 1, $wrapped->images );
		$this->assertSame( 0, $wrapped->edits );
		$this->assertSame( 0, $wrapped->articles );
	}

	public function testDoUpdate() {
		$dbw = $this->getDb();
		$statsInit = new SiteStatsInit( $dbw );
		$statsInit->refresh();

		$ei = SiteStats::edits(); // trigger load
		$pi = SiteStats::pages();
		$ui = SiteStats::users();
		$fi = SiteStats::images();
		$ai = SiteStats::articles();

		$this->assertSame( 0, DeferredUpdates::pendingUpdatesCount() );

		$dbw->begin( __METHOD__ ); // block opportunistic updates

		DeferredUpdates::addUpdate(
			SiteStatsUpdate::factory( [ 'pages' => 2, 'images' => 1, 'edits' => 2 ] )
		);
		$this->assertSame( 1, DeferredUpdates::pendingUpdatesCount() );

		// Still the same
		SiteStats::unload();
		$this->assertEquals( $pi, SiteStats::pages(), 'page count' );
		$this->assertEquals( $ei, SiteStats::edits(), 'edit count' );
		$this->assertEquals( $ui, SiteStats::users(), 'user count' );
		$this->assertEquals( $fi, SiteStats::images(), 'file count' );
		$this->assertEquals( $ai, SiteStats::articles(), 'article count' );
		$this->assertSame( 1, DeferredUpdates::pendingUpdatesCount() );

		// This also notifies DeferredUpdates to do an opportunistic run
		$dbw->commit( __METHOD__ );
		$this->runDeferredUpdates();
		$this->assertSame( 0, DeferredUpdates::pendingUpdatesCount() );

		SiteStats::unload();
		$this->assertEquals( $pi + 2, SiteStats::pages(), 'page count' );
		$this->assertEquals( $ei + 2, SiteStats::edits(), 'edit count' );
		$this->assertEquals( $ui, SiteStats::users(), 'user count' );
		$this->assertEquals( $fi + 1, SiteStats::images(), 'file count' );
		$this->assertEquals( $ai, SiteStats::articles(), 'article count' );

		$statsInit = new SiteStatsInit();
		$statsInit->refresh();
	}
}
