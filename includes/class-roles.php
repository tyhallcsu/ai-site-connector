<?php
/**
 * Custom AI Site Operator role.
 *
 * @package AI_Site_Connector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Site_Connector_Roles {

	public static function register_hooks() {
		add_action( 'init', array( __CLASS__, 'ensure_role' ) );
	}

	public static function default_caps() {
		// Least-privilege defaults. The AI agent can read, create + edit its OWN
		// posts/pages, edit posts/pages it has already published, upload media,
		// and moderate comments. It cannot:
		//   - list, edit, or delete other users
		//   - install/activate/edit plugins
		//   - install/switch/edit themes
		//   - edit files
		//   - manage_options
		//   - delete any content
		//   - touch other authors' content
		//
		// To grant more — e.g. edit_others_posts so the AI can revise content
		// authored by a human teammate — use the filter:
		//
		//   add_filter( 'ai_site_connector_operator_caps', function ( $caps ) {
		//       $caps['edit_others_posts'] = true;
		//       $caps['edit_others_pages'] = true;
		//       $caps['list_users']        = true; // needed for /site-info legacy callers
		//       return $caps;
		//   } );
		$caps = array(
			'read'                   => true,
			'edit_posts'             => true,
			'edit_pages'             => true,
			'edit_published_posts'   => true,
			'edit_published_pages'   => true,
			'upload_files'           => true,
			'moderate_comments'      => true,
			'edit_others_posts'      => false,
			'edit_others_pages'      => false,
			'list_users'             => false,
			'edit_users'             => false,
			'delete_users'           => false,
			'create_users'           => false,
			'install_plugins'        => false,
			'activate_plugins'       => false,
			'edit_plugins'           => false,
			'install_themes'         => false,
			'switch_themes'          => false,
			'edit_themes'            => false,
			'edit_files'             => false,
			'manage_options'         => false,
			'unfiltered_html'        => false,
			'delete_posts'           => false,
			'delete_pages'           => false,
			'delete_published_posts' => false,
			'delete_published_pages' => false,
			'delete_others_posts'    => false,
			'delete_others_pages'    => false,
		);

		/**
		 * Filter the capabilities granted to the AI Site Operator role.
		 *
		 * @param array $caps Map of capability => bool.
		 */
		return apply_filters( 'ai_site_connector_operator_caps', $caps );
	}

	public static function ensure_role() {
		$caps = self::default_caps();
		$role = get_role( AI_SITE_CONNECTOR_OPERATOR_ROLE );

		if ( ! $role ) {
			add_role( AI_SITE_CONNECTOR_OPERATOR_ROLE, __( 'AI Site Operator', 'ai-site-connector' ), $caps );
			return;
		}

		foreach ( $caps as $cap => $grant ) {
			if ( $grant ) {
				$role->add_cap( $cap );
			} else {
				$role->remove_cap( $cap );
			}
		}
	}
}
