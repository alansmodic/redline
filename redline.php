<?php
/**
 * Plugin Name: Redline
 * Description: AI-powered editorial review that checks post content against content guidelines and leaves Notes on flagged blocks.
 * Version: 1.0.0
 * Requires at least: 6.9
 * Requires PHP: 8.1
 * Author: Alan Smodic
 * License: GPL-2.0-or-later
 * Text Domain: redline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'REDLINE_VERSION', '1.0.0' );
define( 'REDLINE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'REDLINE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Autoloader for plugin classes.
 */
spl_autoload_register( function ( $class ) {
	$namespace = 'Redline\\';
	if ( strpos( $class, $namespace ) !== 0 ) {
		return;
	}

	$relative = substr( $class, strlen( $namespace ) );
	$file     = REDLINE_PLUGIN_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

/**
 * Check plugin dependencies on plugins_loaded.
 */
add_action( 'plugins_loaded', function () {
	$missing = [];

	if ( ! function_exists( 'wp_get_content_guidelines_for_post' ) ) {
		$missing[] = 'Content Guidelines';
	}

	if ( ! function_exists( 'wp_ai_client_prompt' ) ) {
		$missing[] = 'WP AI Client';
	}

	if ( ! empty( $missing ) ) {
		add_action( 'admin_notices', function () use ( $missing ) {
			$list = implode( ', ', $missing );
			printf(
				'<div class="notice notice-error"><p><strong>Redline</strong> requires the following plugins to be active: %s</p></div>',
				esc_html( $list )
			);
		} );
		return;
	}

	// Initialize REST controller.
	$controller = new Redline\Rest_Controller();
	$controller->init();
} );

/**
 * Enqueue block editor assets.
 */
add_action( 'enqueue_block_editor_assets', function () {
	if ( ! function_exists( 'wp_get_content_guidelines_for_post' ) || ! function_exists( 'wp_ai_client_prompt' ) ) {
		return;
	}

	$asset_file = REDLINE_PLUGIN_DIR . 'build/index.asset.php';
	$asset      = file_exists( $asset_file ) ? require $asset_file : [
		'dependencies' => [],
		'version'      => REDLINE_VERSION,
	];

	wp_enqueue_script(
		'redline',
		REDLINE_PLUGIN_URL . 'build/index.js',
		$asset['dependencies'],
		$asset['version'],
		true
	);

	wp_enqueue_style(
		'redline',
		REDLINE_PLUGIN_URL . 'build/style-index.css',
		[],
		$asset['version']
	);
} );
