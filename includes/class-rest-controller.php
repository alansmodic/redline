<?php

namespace Redline;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

class Rest_Controller {

	/**
	 * Initialize the REST route.
	 */
	public function init(): void {
		\add_action( 'rest_api_init', [ $this, 'register_routes' ] );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes(): void {
		\register_rest_route( 'redline/v1', '/check', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_check' ],
			'permission_callback' => [ $this, 'check_permissions' ],
			'args'                => [
				'post_id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $value ) {
						return is_numeric( $value ) && (int) $value > 0;
					},
				],
			],
		] );

		\register_rest_route( 'redline/v1', '/clear', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ $this, 'handle_clear' ],
			'permission_callback' => [ $this, 'check_permissions' ],
			'args'                => [
				'post_id' => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
					'validate_callback' => function ( $value ) {
						return is_numeric( $value ) && (int) $value > 0;
					},
				],
			],
		] );
	}

	/**
	 * Permission callback for both routes.
	 */
	public function check_permissions( WP_REST_Request $request ): bool|WP_Error {
		$post_id = $request->get_param( 'post_id' );

		if ( ! \current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error(
				'redline_forbidden',
				\__( 'You do not have permission to edit this post.', 'redline' ),
				[ 'status' => 403 ]
			);
		}

		// Only require prompt_ai if the capability is registered.
		if ( get_role( 'administrator' ) && get_role( 'administrator' )->has_cap( 'prompt_ai' ) ) {
			if ( ! \current_user_can( 'prompt_ai' ) ) {
				return new WP_Error(
					'redline_no_ai_access',
					\__( 'You do not have permission to use AI features.', 'redline' ),
					[ 'status' => 403 ]
				);
			}
		}

		return true;
	}

	/**
	 * Handle the check request.
	 */
	public function handle_check( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id = $request->get_param( 'post_id' );
		$checker = new Block_Checker();

		$result = $checker->check( $post_id );

		if ( \is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Handle clearing all checker-created notes.
	 */
	public function handle_clear( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$post_id      = $request->get_param( 'post_id' );
		$note_creator = new Note_Creator();
		$cleared      = $note_creator->clear_notes( $post_id );

		return new WP_REST_Response( [
			'success'       => true,
			'notes_cleared' => $cleared,
		], 200 );
	}
}
