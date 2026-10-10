<?php
/**
 * The single gateway for every AI call made by this plugin.
 *
 * @package AILSUG
 */

namespace AILSUG\AI;

use AILSUG\Settings;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * All AI traffic goes through this class and only through wp_ai_client_prompt().
 * There is no other HTTP or provider integration anywhere in the plugin.
 */
class Gateway {

	/**
	 * Shared instance.
	 *
	 * @var Gateway|null
	 */
	private static $instance = null;

	/**
	 * Returns the gateway (the mock gateway when AILSUG_MOCK_AI is true).
	 *
	 * @return Gateway
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = Availability::is_mock() ? new MockGateway() : new Gateway();
		}
		return self::$instance;
	}

	/**
	 * Replace the shared instance (tests).
	 *
	 * @param Gateway|null $gateway Gateway.
	 * @return void
	 */
	public static function set_instance( $gateway ) {
		self::$instance = $gateway;
	}

	/**
	 * Send one request to the AI Client.
	 *
	 * @param array $request Request. {
	 *     @type string $system     System instruction.
	 *     @type string $prompt     User prompt.
	 *     @type array  $schema     JSON schema for the answer.
	 *     @type int    $max_tokens Output ceiling.
	 *     @type string $task       keyword|rank.
	 *     @type array  $data       Structured payload (used by the mock).
	 * }
	 * @return array{text:string,input_tokens:int,output_tokens:int,provider:string,model:string}|WP_Error
	 */
	protected function transport( array $request ) {
		if ( ! Availability::core_present() ) {
			return new WP_Error( 'ailsug_no_core', __( 'AI features need WordPress 7.0 or newer.', 'ai-internal-link-suggester' ) );
		}

		$builder = wp_ai_client_prompt( $request['prompt'] )
			->using_system_instruction( $request['system'] )
			->using_temperature( (float) apply_filters( 'ailsug_temperature', 0.2, $request['task'] ) )
			->using_max_tokens( (int) $request['max_tokens'] )
			->as_json_response( $request['schema'] );

		$only = Availability::only_model();
		if ( $only ) {
			try {
				$model = \WordPress\AiClient\AiClient::defaultRegistry()->getProviderModel( $only[0], $only[1] );
			} catch ( \Throwable $e ) {
				return new WP_Error(
					'ailsug_model_unavailable',
					sprintf(
						/* translators: %s: model id. */
						__( 'The model "%s" you chose in Settings → AI & Cost is not available. Pick another model or turn off "Use only this model".', 'ai-internal-link-suggester' ),
						$only[1]
					)
				);
			}
			$builder = $builder->using_model( $model );
		} else {
			$preferences = ModelResolver::preferences();
			if ( $preferences ) {
				$builder = $builder->using_model_preference( ...$preferences );
			}
		}

		$result = $builder->generate_text_result();
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		try {
			$usage = $result->getTokenUsage();
			return array(
				'text'          => (string) $result->toText(),
				'input_tokens'  => (int) $usage->getPromptTokens(),
				'output_tokens' => (int) $usage->getCompletionTokens(),
				'provider'      => (string) $result->getProviderMetadata()->getId(),
				'model'         => (string) $result->getModelMetadata()->getId(),
			);
		} catch ( \Throwable $e ) {
			return new WP_Error( 'ailsug_bad_result', __( 'The AI answer could not be read.', 'ai-internal-link-suggester' ) );
		}
	}

	/**
	 * Decode a JSON answer, tolerating code fences.
	 *
	 * @param string $text Raw model text.
	 * @return array|null
	 */
	public static function decode_json( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/s', $text, $m ) ) {
			$text = $m[1];
		}
		$data = json_decode( $text, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Map a WP_Error from the AI Client to a friendly message.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	public static function friendly_error( WP_Error $error ) {
		$code   = $error->get_error_code();
		$status = (int) ( $error->get_error_data()['status'] ?? 0 );

		if ( 'prompt_prevented' === $code ) {
			return __( 'AI requests are currently blocked on this site (by the site configuration or another plugin). Nothing was sent.', 'ai-internal-link-suggester' );
		}
		if ( 429 === $status ) {
			return __( 'The AI provider is busy (rate limit). The plugin will try again later.', 'ai-internal-link-suggester' );
		}
		if ( 'prompt_network_error' === $code ) {
			return __( 'Could not reach the AI provider. Check your internet connection and try again.', 'ai-internal-link-suggester' );
		}
		if ( 'prompt_invalid_argument' === $code && false !== strpos( $error->get_error_message(), 'No models found' ) ) {
			return __( 'No connected AI model can handle this request. Open Settings → Connectors to check your provider.', 'ai-internal-link-suggester' );
		}
		if ( 401 === $status || 403 === $status ) {
			return __( 'The AI provider rejected the request. Check your key in Settings → Connectors.', 'ai-internal-link-suggester' );
		}
		if ( 0 === strpos( (string) $code, 'ailsug_' ) ) {
			return $error->get_error_message();
		}
		return __( 'The AI request failed. Please try again later.', 'ai-internal-link-suggester' );
	}

	/**
	 * Whether the error might still have been billed by the provider (e.g. a timeout).
	 *
	 * @param WP_Error $error Error.
	 * @return bool
	 */
	public static function may_be_billed( WP_Error $error ) {
		return in_array( $error->get_error_code(), array( 'prompt_network_error', 'prompt_upstream_server_error' ), true );
	}

	/**
	 * Current quality tier.
	 *
	 * @return string
	 */
	protected function tier() {
		return (string) Settings::get( 'quality', 'economy' );
	}
}
