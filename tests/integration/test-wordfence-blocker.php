<?php
/**
 * Wordfence "Disable WordPress application passwords" diagnosis (#133).
 *
 * Wordfence is not installed in CI, so a minimal wfConfig stand-in exposes
 * the real option key (loginSec_disableApplicationPasswords, read via
 * wfConfig::get() in Wordfence 9.x). When that option is on, Wordfence
 * hooks wp_is_application_passwords_available to false; the test applies
 * that filter itself.
 *
 * @package AI_Site_Connector_Tests
 */

if ( ! class_exists( 'wfConfig' ) ) {
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound,Squiz.Classes.ValidClassName.NotCamelCaps -- Test stand-in for Wordfence's class.
	class wfConfig {
		/** @var array<string, mixed> */
		public static $values = array();

		public static function get( $key, $default = false ) {
			return array_key_exists( $key, self::$values ) ? self::$values[ $key ] : $default;
		}
	}
}

asc_it(
	'wordfence: a confirmed app-password block is named with its fix; anything else stays generic (#133)',
	function () {
		$stand_in = isset( wfConfig::$values ) && is_array( wfConfig::$values );
		asc_assert( $stand_in, 'a real Wordfence wfConfig is loaded; this test needs the stand-in' );

		asc_assert_same( null, AI_Site_Connector_Plugin::app_passwords_blocker(), 'blocker reported while passwords are available' );

		wfConfig::$values['loginSec_disableApplicationPasswords'] = true;
		try {
			// Option on but passwords still available (something re-enabled them): nothing to report.
			asc_assert_same( null, AI_Site_Connector_Plugin::app_passwords_blocker(), 'blocker reported while passwords are available' );

			$blocked = asc_it_with_filter(
				'wp_is_application_passwords_available',
				'__return_false',
				static function () {
					return array(
						'blocker' => AI_Site_Connector_Plugin::app_passwords_blocker(),
						'create'  => AI_Site_Connector_Application_Passwords::create_for_user( get_current_user_id(), 'asc-it wordfence' ),
						'report'  => AI_Site_Connector_Diagnostics::generate(),
					);
				}
			);
			asc_assert_same( 'wordfence', $blocked['blocker']['source'], 'blocker source' );
			asc_assert( false !== strpos( $blocked['blocker']['fix'], 'Disable WordPress application passwords' ), 'fix does not name the option' );
			asc_assert( false !== strpos( $blocked['blocker']['settings_url'], 'page=WordfenceWAF' ), 'settings link' );
			asc_assert( 0 === strpos( $blocked['blocker']['doc_url'], 'https://www.wordfence.com/help/' ), 'documentation link' );
			asc_assert( is_wp_error( $blocked['create'] ), 'a password was created while blocked' );
			asc_assert( false !== strpos( $blocked['create']->get_error_message(), 'Wordfence' ), 'creation error does not name Wordfence' );
			asc_assert_same( 'wordfence', $blocked['report']['wordpress']['app_passwords_blocked_by'], 'diagnostics report attribution' );

			// Option off but passwords unavailable for another reason: cause unknown, no attribution.
			wfConfig::$values['loginSec_disableApplicationPasswords'] = false;
			$other = asc_it_with_filter(
				'wp_is_application_passwords_available',
				'__return_false',
				static function () {
					return array(
						'blocker' => AI_Site_Connector_Plugin::app_passwords_blocker(),
						'create'  => AI_Site_Connector_Application_Passwords::create_for_user( get_current_user_id(), 'asc-it other' ),
					);
				}
			);
			asc_assert_same( null, $other['blocker'], 'another cause was attributed to Wordfence' );
			asc_assert( false === strpos( $other['create']->get_error_message(), 'Wordfence' ), 'generic error names Wordfence' );
		} finally {
			wfConfig::$values = array();
		}
	}
);
