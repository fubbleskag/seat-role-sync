<?php
/**
 * Plugin Name: SeAT Role Sync
 * Description: Synchronize roles from SeAT to WordPress
 * Version:     1.0.0
 * Author:      Aether Syndicate
 * Author URI:  http://aethersyn.space
 */

/* Add/update list of SeAT roles available to WP */

	add_action( 'admin_init', 'srs_register_seat_roles' );
	function srs_register_seat_roles() {
		if ( $seat_roles = srs_get_seat_roles() ) {
			foreach ( $seat_roles as $id => $role ) {
				$slug = srs_sanitize_seat_role( $role );
				add_role( $slug, $role );
			}
		}
	}

/* Sync a WP user's SeAT roles */

	add_action( 'init', 'srs_sync_seat_roles' );
	function srs_sync_seat_roles() {
		if ( $user = wp_get_current_user() ) {
			$roles = srs_get_character_roles();
			foreach ( $roles as $role_name => $role_has ) {
				$slug = srs_sanitize_seat_role( $role_name );
				if ( $roles[ $role_name ] ) {
					$user->add_role( $slug );
				} else {
					$user->remove_role( $slug );
				}
			}
		}
	}

/* Settings */

	add_action( 'admin_menu', 'srs_settings_menu' );
	function srs_settings_menu() {
		add_options_page( 'Sync SeAT Roles', 'Sync SeAT Roles', 'manage_options', 'sync_seat_roles', 'srs_options_page' );
	}

	add_action( 'admin_init', 'srs_settings_init' );
	function srs_settings_init() { 
		add_settings_section(
			'srs_settings_page_section', 
			'',
			'__return_empty_string', 
			'srs_settings_page'
		);
		add_settings_field( 
			'seat_api_url', 
			'SeAT API URL',
			'seat_api_url_render', 
			'srs_settings_page', 
			'srs_settings_page_section' 
		);
		register_setting( 'srs_settings_page', 'seat_api_url' );
		add_settings_field( 
			'seat_api_key', 
			'SeAT API Key',
			'seat_api_key_render', 
			'srs_settings_page', 
			'srs_settings_page_section' 
		);
		register_setting( 'srs_settings_page', 'seat_api_key' );
		add_settings_field( 
			'seat_api_hours', 
			'SeAT API Cache Expiry (hours)',
			'seat_api_hours_render', 
			'srs_settings_page', 
			'srs_settings_page_section' 
		);
		register_setting( 'srs_settings_page', 'seat_api_hours' );
	}

	function seat_api_url_render(  ) { 
		$seat_api_url = get_option( 'seat_api_url' );
		echo "<input type='url' name='seat_api_url' value='$seat_api_url'>";
	}

	function seat_api_key_render(  ) { 
		$seat_api_key = get_option( 'seat_api_key' );
		echo "<input type='text' name='seat_api_key' value='$seat_api_key'>";
	}

	function seat_api_hours_render(  ) { 
		$seat_api_hours = get_option( 'seat_api_hours' );
		echo "<input type='number' name='seat_api_hours' value='$seat_api_hours'>";
	}

	function srs_options_page(  ) { 
		echo "<form action='options.php' method='post'>";
			echo "<h2>Sync SeAT Roles</h2>";
			settings_fields( 'srs_settings_page' );
			do_settings_sections( 'srs_settings_page' );
			submit_button();
		echo "</form>";
	}

/**
 * Send request to SeAT API
 *
 * Wrapper function for interacting with the SeAT API
 *
 * @param string $endpoint The API endpoint to use.
 * @param array $args Optional. Arguments to over-ride default WP_Http::request() arguments (https://developer.wordpress.org/reference/classes/WP_Http/request/#parameters)
 */

	function srs_seat_api_request( $endpoint = '/', $args = array() ) {
		$key = get_option( 'seat_api_key' );
		$base = get_option( 'seat_api_url' );
		$hours = get_option( 'seat_api_hours', 1 );
		if ( ! $key || ! $base ) return; // short-circuit if the API key/url are not defined in settings
		$url = $base . $endpoint;
		$default = array(
			'method' => 'GET',
			'headers' => array(
				'accept' => 'application/json',
				'X-Token' => $key,
				'X-CSRF-TOKEN' => ' '
			),
		);
		$query = array_merge( $default, $args );
		$hash = "seatapi_" . hash( 'sha512', $url . serialize( $query ) );
		if ( false === ( $body = get_transient( $hash ) ) ) {
			$response = wp_remote_request( $url, $query );
			if ( is_array( $response ) && ! is_wp_error( $response ) ) {
				$body = json_decode( $response['body'] );
				set_transient( $hash, $body, ( $hours * HOUR_IN_SECONDS ) );
			}
		}
		return $body;
	}

/**
 * Get SeAT roles
 *
 * Returns all roles defined in SeAT
 *
 * @return array List of defined roles.
 */

	function srs_get_seat_roles() {
		if ( $response = srs_seat_api_request( '/roles' ) ) {
			foreach ( $response as $role ) {
				$roles[ $role->id ] = $role->title;
			}
			return $roles;
		}
	}

/**
 * Get character ID
 *
 * Returns the Eve character ID associated with the current (or optionally specified) user.
 *
 * @param integer $user_id Optional. The ID of the user. Default current user.
 * @return integer The Eve character ID, or 0 if not found.
 */

	function srs_get_character_id( $user_id = NULL ) {
		if ( ! $user_id ) $user_id = get_current_user_id();
		if ( $wsl_current_user_image = get_user_meta( $user_id, 'wsl_current_user_image', TRUE ) ) {
			if ( preg_match( '@.*?/(\d*)_128.jpg@m', $wsl_current_user_image, $matches ) ) {
				$character_id = $matches[1];
			}
		}
		return (int) $character_id;
	}

/**
 * Get character roles
 *
 * Returns a list of SeAT roles with a boolean to indicate which are applicable to the character.
 *
 * @param integer $character_id Optional. The ID of the character. Default current user.
 * @return array List of roles. Character has the roles with a true value.
 */

	function srs_get_character_roles( $user_id = NULL ) {
		if ( $character_id = srs_get_character_id( $user_id ) ) {
			if ( $seat_roles = srs_get_seat_roles() ) {
				foreach ( $seat_roles as $role_id => $role_name ) {
					$endpoint = "/roles/query/role-check/{$character_id}/{$role_name}";
					$result = srs_seat_api_request( $endpoint );
					$roles[ $role_name] = ( is_bool( $result ) && $result ) ? TRUE : FALSE;
				}
			}
		}
		return $roles;
	}

/* Helper functions */

	function srs_sanitize_seat_role( $role ) {
		return strtolower( sanitize_html_class( $role ) );
	}