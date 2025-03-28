<?php
/**
 * Reset login/signup throttling for a specified user and/or IP.
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
 */

use MediaWiki\Auth\Throttler;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\MainConfigNames;
use MediaWiki\Maintenance\Maintenance;
use Wikimedia\IPUtils;

// @codeCoverageIgnoreStart
require_once __DIR__ . '/Maintenance.php';
// @codeCoverageIgnoreEnd

/**
 * Reset login/signup throttling for a specified user and/or IP.
 *
 * @ingroup Maintenance
 * @since 1.32
 */
class ResetAuthenticationThrottle extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Reset login/signup throttling for a specified user and/or IP. '
			. "\n\n"
			. 'When resetting signup or temp account, provide the IP. When resetting login (or both), provide '
			. 'both username (as entered in login screen) and IP. An easy way to obtain them is '
			. "the 'throttler' log channel." );
		$this->addOption( 'login', 'Reset login throttle' );
		$this->addOption( 'signup', 'Reset account creation throttle' );
		$this->addOption( 'tempaccount', 'Reset temp account creation throttle' );
		$this->addOption( 'tempaccountnameacquisition', 'Reset temp account name acquisition throttle' );
		$this->addOption( 'user', 'Username to reset (when using --login)', false, true );
		$this->addOption( 'ip', 'IP to reset', false, true );
	}

	public function execute() {
		$forLogin = (bool)$this->getOption( 'login' );
		$forSignup = (bool)$this->getOption( 'signup' );
		$forTempAccount = (bool)$this->getOption( 'tempaccount' );
		$forTempAccountNameAcquisition = (bool)$this->getOption( 'tempaccountnameacquisition' );
		$username = $this->getOption( 'user' );
		$ip = $this->getOption( 'ip' );

		if ( !$forLogin && !$forSignup && !$forTempAccount && !$forTempAccountNameAcquisition ) {
			$this->fatalError(
				'At least one of --login, --signup, --tempaccount, or --tempaccountnameacquisition is required!'
			);
		} elseif ( $ip === null ) {
			$this->fatalError( '--ip is required!' );
		} elseif ( !IPUtils::isValid( $ip ) ) {
			$this->fatalError( "Not a valid IP: $ip" );
		}

		if ( $forLogin ) {
			$this->clearLoginThrottle( $username, $ip );
		}
		if ( $forSignup ) {
			$this->clearSignupThrottle( $ip );
		}
		if ( $forTempAccount ) {
			$this->clearTempAccountCreationThrottle( $ip );
		}
		if ( $forTempAccountNameAcquisition ) {
			$this->clearTempAccountNameAcquisitionThrottle( $ip );
		}

		LoggerFactory::getInstance( 'throttler' )->info( 'Manually cleared {type} throttle', [
			'type' => implode( ' and ', array_filter( [
				$forLogin ? 'login' : null,
				$forSignup ? 'signup' : null,
				$forTempAccount ? 'tempaccount' : null,
				$forTempAccountNameAcquisition ? 'tempaccountnameacquisition' : null,
			] ) ),
			'username' => $username,
			'ipKey' => $ip,
		] );
	}

	/**
	 * @param string|null $rawUsername
	 * @param string|null $ip
	 */
	protected function clearLoginThrottle( $rawUsername, $ip ) {
		$this->output( 'Clearing login throttle...' );

		$passwordAttemptThrottle = $this->getConfig()->get( MainConfigNames::PasswordAttemptThrottle );
		if ( !$passwordAttemptThrottle ) {
			$this->output( "none set\n" );
			return;
		}

		$objectCacheFactory = $this->getServiceContainer()->getInstance()->getObjectCacheFactory();

		$throttler = new Throttler( $passwordAttemptThrottle, [
			'type' => 'password',
			'cache' => $objectCacheFactory->getLocalClusterInstance(),
		] );
		if ( $rawUsername !== null ) {
			$usernames = $this->getServiceContainer()->getAuthManager()
				->normalizeUsername( $rawUsername );
			if ( !$usernames ) {
				$this->fatalError( "Not a valid username: $rawUsername" );
			}
		} else {
			$usernames = [ null ];
		}
		foreach ( $usernames as $username ) {
			$throttler->clear( $username, $ip );
		}

		$botPasswordThrottler = new Throttler( $passwordAttemptThrottle, [
			'type' => 'botpassword',
			'cache' => $objectCacheFactory->getLocalClusterInstance(),
		] );
		// @phan-suppress-next-line PhanPossiblyUndeclaredVariable T240141
		$botPasswordThrottler->clear( $username, $ip );

		$this->output( "done\n" );
	}

	/**
	 * @param string $ip
	 */
	protected function clearSignupThrottle( $ip ) {
		$this->output( 'Clearing signup throttle...' );

		$accountCreationThrottle = $this->getConfig()->get( MainConfigNames::AccountCreationThrottle );
		if ( !is_array( $accountCreationThrottle ) ) {
			$accountCreationThrottle = [ [
				'count' => $accountCreationThrottle,
				'seconds' => 86400,
			] ];
		}
		if ( !$accountCreationThrottle ) {
			$this->output( "none set\n" );
			return;
		}
		$throttler = new Throttler( $accountCreationThrottle, [
			'type' => 'acctcreate',
			'cache' => $this->getServiceContainer()->getObjectCacheFactory()
				->getLocalClusterInstance(),
		] );

		$throttler->clear( null, $ip );

		$this->output( "done\n" );
	}

	protected function clearTempAccountCreationThrottle( string $ip ): void {
		$this->output( 'Clearing temp account creation throttle...' );

		$tempAccountCreationThrottle = $this->getConfig()->get( MainConfigNames::TempAccountCreationThrottle );
		if ( !$tempAccountCreationThrottle ) {
			$this->output( "none set\n" );
			return;
		}
		$throttler = new Throttler( $tempAccountCreationThrottle, [
			'type' => 'tempacctcreate',
			'cache' => $this->getServiceContainer()->getObjectCacheFactory()
				->getLocalClusterInstance(),
		] );

		$throttler->clear( null, $ip );

		$this->output( "done\n" );
	}

	protected function clearTempAccountNameAcquisitionThrottle( string $ip ): void {
		$this->output( 'Clearing temp account name acquisition throttle...' );

		$tempAccountNameAcquisitionThrottle = $this->getConfig()->get(
			MainConfigNames::TempAccountNameAcquisitionThrottle
		);
		if ( !$tempAccountNameAcquisitionThrottle ) {
			$this->output( "none set\n" );
			return;
		}
		$throttler = new Throttler( $tempAccountNameAcquisitionThrottle, [
			'type' => 'tempacctnameacquisition',
			'cache' => $this->getServiceContainer()->getObjectCacheFactory()
				->getLocalClusterInstance(),
		] );

		$throttler->clear( null, $ip );

		$this->output( "done\n" );
	}

}

// @codeCoverageIgnoreStart
$maintClass = ResetAuthenticationThrottle::class;
require_once RUN_MAINTENANCE_IF_MAIN;
// @codeCoverageIgnoreEnd
