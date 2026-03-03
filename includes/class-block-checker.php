<?php

namespace Redline;

use WP_Error;

class Block_Checker {

	/**
	 * Block types to check for content issues.
	 */
	private const CONTENT_BLOCK_TYPES = [
		'core/paragraph',
		'core/heading',
		'core/list',
		'core/list-item',
		'core/quote',
		'core/button',
		'core/image',
	];

	/**
	 * Run content guidelines check on a post.
	 *
	 * @param int $post_id Post ID.
	 * @return array|WP_Error Results array or error.
	 */
	public function check( int $post_id ): array|WP_Error {
		$post = \get_post( $post_id );

		if ( ! $post ) {
			return new WP_Error( 'redline_no_post', 'Post not found.', [ 'status' => 404 ] );
		}

		// Parse blocks from post content.
		$blocks = \parse_blocks( $post->post_content );
		$blocks = $this->flatten_blocks( $blocks );

		// Filter to content blocks only.
		$content_blocks = [];
		foreach ( $blocks as $index => $block ) {
			if ( in_array( $block['blockName'], self::CONTENT_BLOCK_TYPES, true ) ) {
				$content_blocks[] = [
					'index'      => $index,
					'block_name' => $block['blockName'],
					'content'    => $this->get_block_text( $block ),
					'raw_block'  => $block,
				];
			}
		}

		if ( empty( $content_blocks ) ) {
			return [
				'success'       => true,
				'results'       => [],
				'notes_created' => 0,
				'message'       => 'No content blocks found to check.',
			];
		}

		// Get content guidelines for this post.
		if ( \function_exists( 'wp_get_content_guidelines_for_post' ) ) {
			$guidelines = \wp_get_content_guidelines_for_post( $post_id );
		} elseif ( \function_exists( 'ContentGuidelines\\wp_get_content_guidelines_for_post' ) ) {
			$guidelines = \ContentGuidelines\wp_get_content_guidelines_for_post( $post_id );
		} else {
			return new WP_Error( 'redline_no_guidelines_plugin', 'Content Guidelines plugin is not active.', [ 'status' => 422 ] );
		}

		if ( empty( $guidelines ) ) {
			return new WP_Error( 'redline_no_guidelines', 'No content guidelines configured for this post.', [ 'status' => 422 ] );
		}

		// Run free lint checks first.
		$lint_results = $this->run_lint_checks( $content_blocks, $guidelines );

		// Build and send AI prompt.
		$ai_results = $this->run_ai_check( $content_blocks, $guidelines, $lint_results );

		if ( \is_wp_error( $ai_results ) ) {
			return $ai_results;
		}

		// Merge lint and AI results.
		$merged = $this->merge_results( $content_blocks, $lint_results, $ai_results );

		return [
			'success' => true,
			'results' => $merged,
		];
	}

	/**
	 * Flatten nested blocks into a single-level array.
	 */
	private function flatten_blocks( array $blocks ): array {
		$flat = [];
		foreach ( $blocks as $block ) {
			if ( empty( $block['blockName'] ) ) {
				continue;
			}
			$flat[] = $block;
			if ( ! empty( $block['innerBlocks'] ) ) {
				$flat = array_merge( $flat, $this->flatten_blocks( $block['innerBlocks'] ) );
			}
		}
		return $flat;
	}

	/**
	 * Extract readable text from a block.
	 */
	private function get_block_text( array $block ): string {
		if ( $block['blockName'] === 'core/image' ) {
			return $block['attrs']['alt'] ?? '(no alt text)';
		}
		return \wp_strip_all_tags( $block['innerHTML'] );
	}

	/**
	 * Run Lint_Checker on each content block.
	 */
	private function run_lint_checks( array $content_blocks, array $guidelines ): array {
		$results = [];

		if ( ! \class_exists( 'ContentGuidelines\\Lint_Checker' ) ) {
			return $results;
		}

		// Lint_Checker::check() takes content string + guidelines array.
		if ( \function_exists( 'wp_get_content_guidelines' ) ) {
			$raw_guidelines = \wp_get_content_guidelines( 'active' );
		} elseif ( \function_exists( 'ContentGuidelines\\wp_get_content_guidelines' ) ) {
			$raw_guidelines = \ContentGuidelines\wp_get_content_guidelines( 'active' );
		} else {
			$raw_guidelines = [];
		}

		if ( empty( $raw_guidelines ) ) {
			return $results;
		}

		foreach ( $content_blocks as $cb ) {
			$lint = \ContentGuidelines\Lint_Checker::check( $cb['content'], $raw_guidelines );

			if ( ! empty( $lint['issues'] ) ) {
				$results[ $cb['index'] ] = array_map( function ( $issue ) {
					return [
						'message'           => $issue['message'] ?? ( is_string( $issue ) ? $issue : '' ),
						'severity'          => 'warning',
						'guideline_section' => $issue['type'] ?? 'Vocabulary / Readability',
						'source'            => 'lint',
					];
				}, $lint['issues'] );
			}
		}

		return $results;
	}

	/**
	 * Run AI check on all content blocks.
	 */
	private function run_ai_check( array $content_blocks, array $guidelines, array $lint_results ): array|WP_Error {
		$api_key = $this->get_anthropic_api_key();
		if ( ! $api_key ) {
			return new WP_Error( 'redline_no_ai', 'No Anthropic API key configured. Add one in Settings → AI Client Credentials.', [ 'status' => 422 ] );
		}

		$guidelines_text = $this->format_guidelines( $guidelines );

		// Build numbered block list.
		$block_list = '';
		foreach ( $content_blocks as $i => $cb ) {
			$block_list .= sprintf(
				"[Block %d] (%s): %s\n",
				$i,
				$cb['block_name'],
				$cb['content']
			);
		}

		// Note existing lint issues so AI doesn't duplicate.
		$lint_context = '';
		if ( ! empty( $lint_results ) ) {
			$lint_context = "\n\nNote: The following lint issues have already been detected (do not duplicate these):\n";
			foreach ( $lint_results as $block_index => $issues ) {
				foreach ( $issues as $issue ) {
					$lint_context .= sprintf( "- Block index %d: %s\n", $block_index, $issue['message'] );
				}
			}
		}

		$system = 'You are an editorial reviewer for a WordPress site. Check each block of content against the provided content guidelines. Focus on tone, voice, brand alignment, and subjective quality issues. Return only valid JSON.';

		$user_message = sprintf(
			"## Content Guidelines\n\n%s\n\n## Post Blocks\n\n%s%s\n\n## Instructions\n\nReview each block against the guidelines. For blocks with issues, return them in the JSON response. Only flag genuine issues. If a block is fine, omit it.\n\nReturn a JSON array where each element has:\n- \"block_index\" (integer): the block number from above\n- \"issues\" (array): each with \"message\" (string), \"severity\" (\"error\"|\"warning\"|\"info\"), and \"guideline_section\" (string referencing which guideline was violated)",
			$guidelines_text,
			$block_list,
			$lint_context
		);

		$schema = [
			'type'  => 'array',
			'items' => [
				'type'                 => 'object',
				'additionalProperties' => false,
				'properties'           => [
					'block_index' => [ 'type' => 'integer' ],
					'issues'      => [
						'type'  => 'array',
						'items' => [
							'type'                 => 'object',
							'additionalProperties' => false,
							'properties'           => [
								'message'           => [ 'type' => 'string' ],
								'severity'          => [ 'type' => 'string', 'enum' => [ 'error', 'warning', 'info' ] ],
								'guideline_section' => [ 'type' => 'string' ],
							],
							'required'   => [ 'message', 'severity', 'guideline_section' ],
						],
					],
				],
				'required'   => [ 'block_index', 'issues' ],
			],
		];

		try {
			$response = $this->call_anthropic_api( $system, $user_message, $schema );

			if ( \is_wp_error( $response ) ) {
				return $response;
			}

			// Strip markdown code fences if the model wrapped the JSON.
			$json = trim( $response );
			if ( preg_match( '/^```(?:json)?\s*\n(.*)\n```$/s', $json, $m ) ) {
				$json = trim( $m[1] );
			}

			$decoded = json_decode( $json, true );

			if ( json_last_error() !== JSON_ERROR_NONE ) {
				return new WP_Error(
					'redline_ai_parse_error',
					'Failed to parse AI response as JSON: ' . json_last_error_msg(),
					[ 'status' => 500, 'raw' => mb_substr( $response, 0, 500 ) ]
				);
			}

			// Key results by block_index for the content_blocks mapping.
			$keyed = [];
			foreach ( $decoded as $item ) {
				$block_index = $item['block_index'];
				// Map from the AI's sequential index back to the original block index.
				if ( isset( $content_blocks[ $block_index ] ) ) {
					$original_index = $content_blocks[ $block_index ]['index'];
					$keyed[ $original_index ] = array_map( function ( $issue ) {
						$issue['source'] = 'ai';
						return $issue;
					}, $item['issues'] );
				}
			}

			return $keyed;
		} catch ( \Exception $e ) {
			return new WP_Error( 'redline_ai_error', $e->getMessage(), [ 'status' => 500 ] );
		}
	}

	/**
	 * Format guidelines into readable text for the AI prompt.
	 */
	private function format_guidelines( array $guidelines ): string {
		if ( isset( $guidelines['packet_text'] ) ) {
			return $guidelines['packet_text'];
		}

		// Fallback: serialize guidelines into a readable format.
		$text = '';
		foreach ( $guidelines as $key => $value ) {
			if ( is_string( $value ) ) {
				$text .= "### {$key}\n{$value}\n\n";
			} elseif ( is_array( $value ) ) {
				$text .= "### {$key}\n" . \wp_json_encode( $value, JSON_PRETTY_PRINT ) . "\n\n";
			}
		}
		return $text;
	}

	/**
	 * Merge lint and AI results into a unified results array.
	 */
	private function merge_results( array $content_blocks, array $lint_results, array $ai_results ): array {
		$merged = [];

		// Collect all block indices that have issues.
		$all_indices = array_unique( array_merge( array_keys( $lint_results ), array_keys( $ai_results ) ) );

		foreach ( $all_indices as $block_index ) {
			$issues = [];

			if ( isset( $lint_results[ $block_index ] ) ) {
				$issues = array_merge( $issues, $lint_results[ $block_index ] );
			}

			if ( isset( $ai_results[ $block_index ] ) ) {
				$issues = array_merge( $issues, $ai_results[ $block_index ] );
			}

			// Find the content block info.
			$block_info = null;
			foreach ( $content_blocks as $cb ) {
				if ( $cb['index'] === $block_index ) {
					$block_info = $cb;
					break;
				}
			}

			$merged[] = [
				'block_index' => $block_index,
				'block_name'  => $block_info['block_name'] ?? 'unknown',
				'excerpt'     => mb_substr( $block_info['content'] ?? '', 0, 120 ),
				'issues'      => $issues,
			];
		}

		return $merged;
	}

	/**
	 * Get the Anthropic API key from the WP AI Client credentials.
	 */
	private function get_anthropic_api_key(): ?string {
		$credentials = \get_option( 'wp_ai_client_provider_credentials', [] );
		if ( ! is_array( $credentials ) || empty( $credentials['anthropic'] ) ) {
			return null;
		}
		return $credentials['anthropic'];
	}

	/**
	 * Call the Anthropic Messages API directly via wp_remote_post.
	 */
	private function call_anthropic_api( string $system, string $user_message, array $schema ): string|WP_Error {
		$api_key = $this->get_anthropic_api_key();
		if ( ! $api_key ) {
			return new WP_Error( 'redline_no_api_key', 'No Anthropic API key found.' );
		}

		$body = [
			'model'      => 'claude-sonnet-4-6',
			'max_tokens' => 4096,
			'system'     => $system . "\n\nYou MUST respond with valid JSON only. No other text.",
			'messages'   => [
				[
					'role'    => 'user',
					'content' => $user_message,
				],
			],
		];

		// Disable WordPress's low-speed cURL check — AI responses stream slowly.
		$disable_low_speed = function ( $handle ) {
			curl_setopt( $handle, CURLOPT_LOW_SPEED_LIMIT, 0 );
			curl_setopt( $handle, CURLOPT_LOW_SPEED_TIME, 0 );
		};
		\add_action( 'http_api_curl', $disable_low_speed );

		$response = \wp_remote_post( 'https://api.anthropic.com/v1/messages', [
			'headers' => [
				'Content-Type'      => 'application/json',
				'x-api-key'         => $api_key,
				'anthropic-version'  => '2023-06-01',
			],
			'body'    => \wp_json_encode( $body ),
			'timeout' => 120,
		] );

		\remove_action( 'http_api_curl', $disable_low_speed );

		if ( \is_wp_error( $response ) ) {
			return $response;
		}

		$status = \wp_remote_retrieve_response_code( $response );
		$body   = \wp_remote_retrieve_body( $response );

		if ( $status !== 200 ) {
			return new WP_Error( 'redline_api_error', sprintf( 'Anthropic API returned %d: %s', $status, $body ), [ 'status' => 502 ] );
		}

		$data = json_decode( $body, true );
		if ( ! isset( $data['content'][0]['text'] ) ) {
			return new WP_Error( 'redline_api_parse', 'Unexpected API response format.', [ 'status' => 500 ] );
		}

		return $data['content'][0]['text'];
	}
}
