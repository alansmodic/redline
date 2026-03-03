<?php

namespace Redline;

class List_Table {

	/**
	 * Initialize list table hooks.
	 */
	public function init(): void {
		// Add bulk action to post list screens.
		add_filter( 'bulk_actions-edit-post', [ $this, 'register_bulk_action' ] );
		add_filter( 'bulk_actions-edit-page', [ $this, 'register_bulk_action' ] );

		// Handle bulk action.
		add_filter( 'handle_bulk_actions-edit-post', [ $this, 'handle_bulk_action' ], 10, 3 );
		add_filter( 'handle_bulk_actions-edit-page', [ $this, 'handle_bulk_action' ], 10, 3 );

		// Show admin notice with results.
		add_action( 'admin_notices', [ $this, 'bulk_action_notice' ] );

		// Add row action per post.
		add_filter( 'post_row_actions', [ $this, 'add_row_action' ], 10, 2 );
		add_filter( 'page_row_actions', [ $this, 'add_row_action' ], 10, 2 );

		// Handle single row action.
		add_action( 'admin_action_redline_check', [ $this, 'handle_row_action' ] );
	}

	/**
	 * Register the bulk action dropdown option.
	 */
	public function register_bulk_action( array $actions ): array {
		$actions['redline_check'] = __( 'Redline: Check Content', 'redline' );
		return $actions;
	}

	/**
	 * Handle the bulk action.
	 */
	public function handle_bulk_action( string $redirect_url, string $action, array $post_ids ): string {
		if ( 'redline_check' !== $action ) {
			return $redirect_url;
		}

		$checker       = new Block_Checker();
		$total_issues  = 0;
		$total_notes   = 0;
		$posts_checked = 0;
		$errors        = [];

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;

			if ( ! current_user_can( 'edit_post', $post_id ) ) {
				continue;
			}

			$result = $checker->check( $post_id );

			if ( is_wp_error( $result ) ) {
				$errors[] = sprintf( 'Post %d: %s', $post_id, $result->get_error_message() );
				continue;
			}

			$posts_checked++;
			$total_notes += $result['notes_created'];
			foreach ( $result['results'] as $block_result ) {
				$total_issues += count( $block_result['issues'] );
			}
		}

		return add_query_arg( [
			'redline_checked' => $posts_checked,
			'redline_issues'  => $total_issues,
			'redline_notes'   => $total_notes,
			'redline_errors'  => count( $errors ),
		], $redirect_url );
	}

	/**
	 * Display results notice after bulk action.
	 */
	public function bulk_action_notice(): void {
		if ( ! isset( $_GET['redline_checked'] ) ) {
			return;
		}

		$checked = (int) $_GET['redline_checked'];
		$issues  = (int) ( $_GET['redline_issues'] ?? 0 );
		$notes   = (int) ( $_GET['redline_notes'] ?? 0 );
		$errors  = (int) ( $_GET['redline_errors'] ?? 0 );

		$message = sprintf(
			__( 'Redline checked %d post(s): %d issue(s) found, %d note(s) created.', 'redline' ),
			$checked,
			$issues,
			$notes
		);

		if ( $errors > 0 ) {
			$message .= ' ' . sprintf( __( '%d post(s) had errors.', 'redline' ), $errors );
		}

		$status = $issues > 0 ? 'warning' : 'success';
		printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $status ), esc_html( $message ) );
	}

	/**
	 * Add "Redline" row action to each post.
	 */
	public function add_row_action( array $actions, \WP_Post $post ): array {
		if ( current_user_can( 'edit_post', $post->ID ) ) {
			$url = wp_nonce_url(
				admin_url( sprintf( 'admin.php?action=redline_check&post_id=%d', $post->ID ) ),
				'redline_check_' . $post->ID
			);
			$actions['redline'] = sprintf(
				'<a href="%s" aria-label="%s">%s</a>',
				esc_url( $url ),
				esc_attr( sprintf( __( 'Run Redline check on "%s"', 'redline' ), $post->post_title ) ),
				__( 'Redline', 'redline' )
			);
		}
		return $actions;
	}

	/**
	 * Handle single post row action.
	 */
	public function handle_row_action(): void {
		$post_id = isset( $_GET['post_id'] ) ? (int) $_GET['post_id'] : 0;

		if ( ! $post_id || ! wp_verify_nonce( $_GET['_wpnonce'] ?? '', 'redline_check_' . $post_id ) ) {
			wp_die( __( 'Invalid request.', 'redline' ) );
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( __( 'You do not have permission to check this post.', 'redline' ) );
		}

		$checker = new Block_Checker();
		$result  = $checker->check( $post_id );

		$post = get_post( $post_id );
		$redirect = admin_url( 'edit.php' );
		if ( $post ) {
			$redirect = add_query_arg( 'post_type', $post->post_type, $redirect );
		}

		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( [
				'redline_checked' => 0,
				'redline_errors'  => 1,
			], $redirect );
		} else {
			$issues = 0;
			foreach ( $result['results'] as $block_result ) {
				$issues += count( $block_result['issues'] );
			}
			$redirect = add_query_arg( [
				'redline_checked' => 1,
				'redline_issues'  => $issues,
				'redline_notes'   => $result['notes_created'],
				'redline_errors'  => 0,
			], $redirect );
		}

		wp_safe_redirect( $redirect );
		exit;
	}
}
