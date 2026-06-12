<?php
/**
 * Claude API client.
 *
 * Sends the parsed data table and the user's explanation to the Anthropic
 * Messages API and turns the response into validated Chart.js chart
 * suggestions. Also lists the account's available models for the Settings
 * screen and runs the connection test.
 *
 * @package CoywolfDataVisualizer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Coywolf_CDV_AI {

	const API_BASE        = 'https://api.anthropic.com';
	const API_VERSION     = '2023-06-01';
	const DEFAULT_MODEL   = 'claude-opus-4-8';
	const MODELS_CACHE    = 'coywolf_cdv_models';
	const MAX_TOKENS      = 16000;
	const REQUEST_TIMEOUT = 180;

	/**
	 * Stored API key.
	 *
	 * @return string
	 */
	public function api_key() {
		return (string) get_option( 'coywolf_cdv_api_key', '' );
	}

	/**
	 * Whether a key is saved.
	 *
	 * @return bool
	 */
	public function is_configured() {
		return '' !== $this->api_key();
	}

	/**
	 * Model to use for analysis.
	 *
	 * @return string
	 */
	public function model() {
		$settings = get_option( 'coywolf_cdv_settings', array() );
		$model    = is_array( $settings ) && ! empty( $settings['model'] ) ? (string) $settings['model'] : '';
		return '' !== $model ? $model : self::DEFAULT_MODEL;
	}

	/**
	 * Ask Claude for chart suggestions.
	 *
	 * @param string $csv         Normalized CSV excerpt of the data.
	 * @param string $explanation User's description of the data.
	 * @param array  $meta        { total_rows: int, truncated: bool }
	 * @return array[]|WP_Error Sanitized suggestions: { title, caption, type, config }.
	 */
	public function analyze( $csv, $explanation, array $meta = array() ) {
		if ( ! $this->is_configured() ) {
			return new WP_Error(
				'coywolf_cdv_no_key',
				__( 'Add your Claude API key on the Settings page first.', 'coywolf-data-visualizer' )
			);
		}

		$system = 'You are a data-visualization assistant embedded in a WordPress plugin. '
			. 'You design Chart.js v4 charts from tabular data. '
			. 'You respond with raw JSON only: a single JSON array, no markdown fences, no commentary.';

		$truncated_note = ! empty( $meta['truncated'] )
			? sprintf( "\nNote: the table below is a truncated excerpt; the full dataset has %d rows. Design charts that make sense for the full dataset.", (int) ( isset( $meta['total_rows'] ) ? $meta['total_rows'] : 0 ) )
			: '';

		$prompt = "The user uploaded a data table and explained what it represents.\n\n"
			. "USER'S EXPLANATION:\n" . $explanation . "\n"
			. $truncated_note . "\n"
			. "DATA TABLE (CSV):\n" . $csv . "\n\n"
			. "Propose 3 to 6 distinct charts, most insightful first. Respond with a JSON array where each item is:\n"
			. '{"title": string, "caption": string, "type": string, "config": object}' . "\n\n"
			. "Rules:\n"
			. '- "type" must be one of: ' . implode( ', ', Coywolf_CDV_Charts::ALLOWED_TYPES ) . "\n"
			. '- "title" is a short, descriptive chart title. "caption" is a 1-2 sentence takeaway a reader should get from the chart.' . "\n"
			. '- "config" is a complete Chart.js v4 configuration: {"type": ..., "data": {"labels": [...], "datasets": [...]}, "options": {...}}.' . "\n"
			. "- The config must be pure JSON. Never include functions, callbacks, or expressions.\n"
			. "- Embed the actual data values in the config. Aggregate, group, or derive values when the raw table is too granular for a readable chart.\n"
			. '- Use this color palette for datasets (background/border): ["#2563eb","#f59e0b","#10b981","#ef4444","#8b5cf6","#06b6d4","#f97316","#84cc16"]. Use translucent backgrounds (e.g. "#2563eb33") with solid borders for line and radar charts.' . "\n"
			. '- Set "options.responsive" to true and "options.maintainAspectRatio" to true.' . "\n"
			. '- Do NOT set "options.plugins.title" — the plugin renders the title itself. Configure "options.plugins.legend" sensibly (hide it for single-dataset bar charts).' . "\n"
			. '- Label axes with "options.scales.x.title" / "options.scales.y.title" when units or meaning are not obvious.';

		$body = array(
			'model'      => $this->model(),
			'max_tokens' => self::MAX_TOKENS,
			'system'     => $system,
			'messages'   => array(
				array(
					'role'    => 'user',
					'content' => $prompt,
				),
			),
		);

		$response = wp_remote_post(
			self::API_BASE . '/v1/messages',
			array(
				'timeout'    => self::REQUEST_TIMEOUT,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $this->api_key(),
					'anthropic-version' => self::API_VERSION,
					'content-type'      => 'application/json',
				),
				'body'       => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'coywolf_cdv_api', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error( 'coywolf_cdv_api', $this->api_error( $response ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		$text = '';
		foreach ( (array) ( isset( $data['content'] ) ? $data['content'] : array() ) as $block ) {
			if ( is_array( $block ) && isset( $block['type'] ) && 'text' === $block['type'] ) {
				$text .= (string) $block['text'];
			}
		}
		if ( '' === $text ) {
			return new WP_Error( 'coywolf_cdv_api', __( 'Claude returned an empty response. Try again.', 'coywolf-data-visualizer' ) );
		}

		$suggestions = $this->decode_json( $text );
		if ( ! is_array( $suggestions ) ) {
			return new WP_Error( 'coywolf_cdv_api', __( 'Claude returned a response that could not be parsed as chart suggestions. Try again.', 'coywolf-data-visualizer' ) );
		}

		$clean = array();
		foreach ( $suggestions as $suggestion ) {
			$valid = $this->sanitize_suggestion( $suggestion );
			if ( null !== $valid ) {
				$clean[] = $valid;
			}
			if ( count( $clean ) >= 8 ) {
				break;
			}
		}
		if ( empty( $clean ) ) {
			return new WP_Error( 'coywolf_cdv_api', __( 'Claude did not return any usable chart suggestions. Add more detail to your explanation and try again.', 'coywolf-data-visualizer' ) );
		}
		return $clean;
	}

	/**
	 * Validate and sanitize one chart suggestion (also used when the Add
	 * Chart screen posts the user's selection back for saving).
	 *
	 * @param mixed $suggestion Decoded suggestion.
	 * @return array|null { title, caption, type, config } or null when unusable.
	 */
	public function sanitize_suggestion( $suggestion ) {
		if ( ! is_array( $suggestion ) || empty( $suggestion['title'] ) || empty( $suggestion['config'] ) || ! is_array( $suggestion['config'] ) ) {
			return null;
		}
		$config = $this->sanitize_config( $suggestion['config'] );
		if ( null === $config ) {
			return null;
		}
		$type = isset( $config['type'] ) ? (string) $config['type'] : '';
		if ( ! in_array( $type, Coywolf_CDV_Charts::ALLOWED_TYPES, true ) ) {
			return null;
		}
		if ( empty( $config['data'] ) || ! is_array( $config['data'] ) || empty( $config['data']['datasets'] ) || ! is_array( $config['data']['datasets'] ) ) {
			return null;
		}
		return array(
			'title'   => sanitize_text_field( (string) $suggestion['title'] ),
			'caption' => isset( $suggestion['caption'] ) ? sanitize_textarea_field( (string) $suggestion['caption'] ) : '',
			'type'    => $type,
			'config'  => $config,
		);
	}

	/**
	 * Recursively constrain a Chart.js config to JSON-safe values: scalars
	 * and arrays only, depth-limited, valid UTF-8 strings. JSON can't carry
	 * functions, but this also guards against pathological nesting.
	 *
	 * @param mixed $value Config branch.
	 * @param int   $depth Current depth.
	 * @return mixed|null Sanitized branch, or null when invalid.
	 */
	private function sanitize_config( $value, $depth = 0 ) {
		if ( $depth > 12 ) {
			return null;
		}
		if ( is_array( $value ) ) {
			$out = array();
			foreach ( $value as $key => $item ) {
				if ( ! is_string( $key ) && ! is_int( $key ) ) {
					continue;
				}
				$clean = $this->sanitize_config( $item, $depth + 1 );
				if ( null === $clean && ! is_null( $item ) ) {
					continue;
				}
				$out[ $key ] = $clean;
			}
			return $out;
		}
		if ( is_string( $value ) ) {
			return (string) wp_check_invalid_utf8( $value, true );
		}
		if ( is_int( $value ) || is_float( $value ) || is_bool( $value ) || null === $value ) {
			return $value;
		}
		return null;
	}

	/**
	 * Decode a JSON payload from model output, tolerating markdown fences
	 * and leading prose.
	 *
	 * @param string $text Model output.
	 * @return mixed|null
	 */
	private function decode_json( $text ) {
		$text  = trim( (string) $text );
		$text  = preg_replace( '/^```(?:json)?\s*/i', '', $text );
		$text  = preg_replace( '/\s*```$/', '', (string) $text );
		$start = strpbrk( $text, '[{' );
		if ( false === $start ) {
			return null;
		}
		return json_decode( $start, true );
	}

	/**
	 * Claude models available to the stored key, cached for 12 hours.
	 *
	 * @return array[] { id, name }
	 */
	public function models() {
		if ( ! $this->is_configured() ) {
			return array();
		}
		$cached = get_transient( self::MODELS_CACHE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$response = wp_remote_get(
			self::API_BASE . '/v1/models?limit=100',
			array(
				'timeout'    => 8,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $this->api_key(),
					'anthropic-version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}
		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$models = array();
		foreach ( (array) ( isset( $body['data'] ) ? $body['data'] : array() ) as $row ) {
			if ( is_array( $row ) && ! empty( $row['id'] ) && 0 === strpos( (string) $row['id'], 'claude-' ) ) {
				$models[] = array(
					'id'   => (string) $row['id'],
					'name' => ! empty( $row['display_name'] ) ? (string) $row['display_name'] : (string) $row['id'],
				);
			}
		}
		if ( ! empty( $models ) ) {
			set_transient( self::MODELS_CACHE, $models, 12 * HOUR_IN_SECONDS );
		}
		return $models;
	}

	/**
	 * Cheap connection test: list models with the stored key.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		if ( ! $this->is_configured() ) {
			return new WP_Error( 'coywolf_cdv_no_key', __( 'No API key is saved yet.', 'coywolf-data-visualizer' ) );
		}
		$response = wp_remote_get(
			self::API_BASE . '/v1/models?limit=1',
			array(
				'timeout'    => 10,
				'user-agent' => $this->user_agent(),
				'headers'    => array(
					'x-api-key'         => $this->api_key(),
					'anthropic-version' => self::API_VERSION,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'coywolf_cdv_api', $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error( 'coywolf_cdv_api', $this->api_error( $response ) );
		}
		return true;
	}

	/**
	 * Drop the cached model list (called when the key changes).
	 */
	public function flush_models_cache() {
		delete_transient( self::MODELS_CACHE );
	}

	/**
	 * Extract a readable error message from an Anthropic error response.
	 *
	 * @param array $response wp_remote_* response.
	 * @return string
	 */
	private function api_error( $response ) {
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		$msg  = '';
		if ( is_array( $body ) && isset( $body['error']['message'] ) ) {
			$msg = (string) $body['error']['message'];
		}
		if ( '' === $msg ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'The Claude API returned HTTP %d.', 'coywolf-data-visualizer' ), $code );
		}
		if ( 401 === $code ) {
			/* translators: %s: API error message */
			return sprintf( __( 'Invalid API key (%s). Check the key on the Settings page.', 'coywolf-data-visualizer' ), $msg );
		}
		return $msg;
	}

	/**
	 * Identify the site in outbound requests.
	 *
	 * @return string
	 */
	private function user_agent() {
		return 'CoywolfDataVisualizer/' . Coywolf_Data_Visualizer::VERSION . '; ' . home_url();
	}
}
