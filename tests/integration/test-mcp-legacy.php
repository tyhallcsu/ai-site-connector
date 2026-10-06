<?php
/**
 * Legacy MCP post tools: failed REST calls are tool errors (#111) and
 * unsupported post types are refused instead of becoming blog posts (#118).
 *
 * @package AI_Site_Connector_Tests
 */

asc_it(
	'mcp legacy: failed core REST requests come back as isError with status and code (#111)',
	function () {
		$missing = asc_it_mcp_call( 'wp_get_post', array( 'id' => 987654321 ) );
		asc_assert( $missing['is_error'], 'nonexistent id reported as success' );
		asc_assert_same( 404, $missing['data']['status'], 'nonexistent id status' );
		asc_assert_same( 'rest_post_invalid_id', $missing['data']['code'], 'nonexistent id code' );

		$bad_list = asc_it_mcp_call( 'wp_list_posts', array( 'status' => 'not-a-status' ) );
		asc_assert( $bad_list['is_error'], 'invalid list parameter reported as success' );
		asc_assert_same( 400, $bad_list['data']['status'], 'invalid list status' );

		$post = asc_it_post( array( 'post_title' => 'Legacy MCP read' ) );
		try {
			$ok = asc_it_mcp_call( 'wp_get_post', array( 'id' => $post ) );
			asc_assert( ! $ok['is_error'], 'existing post reported as error' );
			asc_assert_same( $post, (int) $ok['data']['id'], 'existing post id' );

			$denied = asc_it_with_permissions(
				array( 'write_content' => true ),
				function () use ( $post ) {
					// A contributor passes the plugin's write gate (edit_posts) but core
					// REST refuses edits to the admin's post: the #111 path.
					$subscriber = asc_it_user( 'contributor' );
					try {
						return asc_it_as_user(
							$subscriber,
							function () use ( $post ) {
								return asc_it_mcp_call( 'wp_update_post', array( 'id' => $post, 'title' => 'Not allowed' ) );
							}
						);
					} finally {
						asc_it_delete_user( $subscriber );
					}
				}
			);
			asc_assert( $denied['is_error'], 'capability denial reported as success' );
			asc_assert( $denied['data']['status'] >= 400, 'capability denial status' );
			asc_assert_same( 'Legacy MCP read', get_post_field( 'post_title', $post ), 'denied update changed the post' );
		} finally {
			wp_delete_post( $post, true );
		}
	}
);

asc_it(
	'mcp legacy: unknown or typo post types are refused and never create a blog post (#118)',
	function () {
		$count = static function () {
			return (int) wp_count_posts( 'post' )->draft;
		};
		asc_it_with_permissions(
			array( 'write_content' => true ),
			function () use ( $count ) {
				$before = $count();
				foreach ( array( 'product', 'pages', 'nav_menu_item' ) as $type ) {
					$res = asc_it_mcp_call( 'wp_create_post', array( 'title' => 'Should not exist', 'content' => 'x', 'post_type' => $type ) );
					asc_assert( $res['is_error'], "post_type {$type} accepted" );
					asc_assert_same( 'asc_unsupported_post_type', $res['data']['code'], "post_type {$type} code" );
				}
				asc_assert_same( $before, $count(), 'a blog post was created as a fallback' );

				$get = asc_it_mcp_call( 'wp_get_post', array( 'id' => 1, 'post_type' => 'pages' ) );
				asc_assert_same( 'asc_unsupported_post_type', $get['data']['code'], 'typo on read' );
				$list = asc_it_mcp_call( 'wp_list_posts', array( 'post_type' => 'product' ) );
				asc_assert_same( 'asc_unsupported_post_type', $list['data']['code'], 'unknown type on list' );
			}
		);
	}
);

asc_it(
	'mcp legacy: core aliases and REST-enabled custom types use their own routes (#118)',
	function () {
		register_post_type(
			'asc_it_book',
			array(
				'public'       => true,
				'show_in_rest' => true,
				'rest_base'    => 'asc-it-books',
				'label'        => 'ASC IT Books',
			)
		);
		rest_get_server(); // Routes are registered on first use; add only this type's.
		( new WP_REST_Posts_Controller( 'asc_it_book' ) )->register_routes();
		$page = asc_it_post( array( 'post_type' => 'page', 'post_title' => 'Legacy page' ) );
		$book = 0;
		try {
			$pages = asc_it_mcp_call( 'wp_list_pages', array( 'per_page' => 100 ) );
			asc_assert( ! $pages['is_error'], 'wp_list_pages failed' );
			asc_assert( in_array( $page, array_map( 'intval', wp_list_pluck( $pages['data'], 'id' ) ), true ), 'page missing from wp_list_pages' );

			$media = asc_it_mcp_call( 'wp_list_posts', array( 'post_type' => 'media', 'status' => 'inherit' ) );
			asc_assert( ! $media['is_error'], 'media alias failed: ' . wp_json_encode( $media['data'] ) );

			$created = asc_it_with_permissions(
				array( 'write_content' => true ),
				function () {
					return asc_it_mcp_call( 'wp_create_post', array( 'title' => 'Custom type', 'content' => 'x', 'post_type' => 'asc_it_book' ) );
				}
			);
			asc_assert( ! $created['is_error'], 'REST-enabled custom type refused: ' . wp_json_encode( $created['data'] ) );
			$book = (int) $created['data']['id'];
			asc_assert_same( 'asc_it_book', get_post_type( $book ), 'created with the requested type' );
		} finally {
			wp_delete_post( $page, true );
			if ( $book ) {
				wp_delete_post( $book, true );
			}
			unregister_post_type( 'asc_it_book' );
		}
	}
);
