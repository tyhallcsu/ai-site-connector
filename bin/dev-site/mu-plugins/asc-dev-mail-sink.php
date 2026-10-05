<?php
/**
 * Plugin Name: AI Site Connector dev mail sink
 * Description: Local dev site only. Records every wp_mail() call in /asc-dev-state/mail.log and never sends it. Installed by bin/dev-site.sh; not part of the plugin.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_wp_mail',
	static function ( $short_circuit, $atts ) {
		$entry = array(
			'time'        => gmdate( 'c' ),
			'to'          => $atts['to'],
			'subject'     => $atts['subject'],
			'headers'     => $atts['headers'],
			'attachments' => $atts['attachments'],
			'message'     => $atts['message'],
		);
		file_put_contents( '/asc-dev-state/mail.log', wp_json_encode( $entry ) . "\n", FILE_APPEND | LOCK_EX );
		return true;
	},
	10,
	2
);
