<?php
/**
 * The single gateway for every AI call made by this plugin.
 *
 * @package AILSUG
 */

namespace AILSUG\AI;

use AILSUG\Cost\Budget;
use AILSUG\Cost\Calibrator;
use AILSUG\Cost\Estimator;
use AILSUG\Cost\Pricing;
use AILSUG\Cost\UsageLog;
use AILSUG\Logger;
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

	const PRICE_BLOCK = 'ailsug_price_block';

	/**
	 * Run one AI task end to end: checks → reserve budget → call → price → reconcile → log.
	 *
	 * @param string $task    keyword|rank.
	 * @param array  $data    Payload for the prompt.
	 * @param array  $context run_id, post_id.
	 * @return array{data:array,provider:string,model:string,cost:int}|WP_Error WP_Error data may contain 'hold' or 'retry'.
	 */
	public function run( $task, array $data, array $context = array() ) {
		$status = Availability::status();
		if ( empty( $status['available'] ) ) {
			return new WP_Error( 'ailsug_unavailable', $status['message'], array( 'retry' => false ) );
		}

		$strict = (bool) Settings::get( 'strict_budget' ) && Budget::cap() > 0;
		$block  = (string) get_option( self::PRICE_BLOCK, '' );
		if ( '' !== $block ) {
			if ( Pricing::price_for( '', $block ) ) {
				delete_option( self::PRICE_BLOCK );
			} elseif ( $strict ) {
				return new WP_Error(
					'ailsug_price_unknown',
					/* translators: %s: model id. */
					sprintf( __( 'The AI model "%s" answered earlier but its price is unknown. Enter its price in Settings → AI & Cost to continue.', 'ai-internal-link-suggester' ), $block ),
					array( 'hold' => 'budget' )
				);
			}
		}

		$worst = Estimator::worst_price();
		if ( null === $worst && $strict ) {
			return new WP_Error( 'ailsug_price_unknown', __( 'The price of the AI model that may answer is unknown, so the budget cannot be enforced. Enter its price in Settings → AI & Cost, or pick a model with a known price.', 'ai-internal-link-suggester' ), array( 'hold' => 'budget' ) );
		}

		$built      = Prompts::build( $task, $data );
		$schema     = Schemas::get( $task );
		$max_tokens = Estimator::max_output( $task, (int) ( $data['max'] ?? Settings::get( 'max_suggestions', 5 ) ) );
		$factors    = Calibrator::factors( $task );
		$est_raw    = Estimator::tokens( $built['system'] . "\n" . $built['prompt'] ) + 20;
		$est_in     = (int) ceil( $est_raw * $factors['input'] );
		$planned    = Estimator::planned_tokens( $task, array( 'suggestions' => (int) ( $data['max'] ?? 0 ) ) );
		$est_out    = $planned['output'];
		$prediction = ModelResolver::predict();
		$requested  = $prediction['likely'] ? (string) $prediction['likely'][1] : '';
		$pre_cost   = $prediction['likely'] ? (int) Pricing::call_cost( $prediction['likely'][0], $prediction['likely'][1], $est_in, $est_out ) : 0;

		Logger::verbose( 'Prompt for ' . $task, $built['system'] . "\n---\n" . $built['prompt'] );

		for ( $attempt = 1; $attempt <= 3; $attempt++ ) {
			$reserve = $worst ? Pricing::cost( (int) ceil( $est_in * 1.3 ), $worst['input'] ) + Pricing::cost( $max_tokens, $worst['output'] ) : 0;
			$res_id  = Budget::reserve( max( 1, $reserve ) );
			if ( ! $res_id ) {
				return new WP_Error( 'ailsug_budget', __( 'The estimated monthly AI budget is reached. Raise the cap in Settings → AI & Cost or wait until next month.', 'ai-internal-link-suggester' ), array( 'hold' => 'budget' ) );
			}

			$response = $this->transport(
				array(
					'system'     => $built['system'],
					'prompt'     => $built['prompt'],
					'schema'     => $schema,
					'max_tokens' => $max_tokens,
					'task'       => $task,
					'data'       => $data,
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( self::may_be_billed( $response ) ) {
					Budget::hold( $res_id );
				} else {
					Budget::release( $res_id );
				}
				$err_data    = (array) $response->get_error_data();
				$status_code = (int) ( $err_data['status'] ?? 0 );
				$err_msg     = $response->get_error_message();
				$is_429      = 429 === $status_code
					|| false !== strpos( $err_msg, '429' )
					|| false !== stripos( $err_msg, 'rate limit' )
					|| false !== stripos( $err_msg, 'quota' )
					|| false !== stripos( $err_msg, 'resource has been exhausted' );

				Logger::debug(
					'AI call failed',
					array(
						'task'    => $task,
						'code'    => $response->get_error_code(),
						'status'  => $status_code,
						'attempt' => $attempt,
					)
				);

				// If rate limited on earlier attempt, pause with backoff and retry.
				if ( $is_429 && $attempt < 3 ) {
					$delay_seconds = 2 * $attempt; // 2s on 1st attempt, 4s on 2nd attempt.
					Logger::debug(
						'Rate limited (429), pausing before retry',
						array(
							'task'    => $task,
							'attempt' => $attempt,
							'delay'   => $delay_seconds,
						)
					);
					sleep( $delay_seconds );
					continue;
				}

				$retry = $is_429 || in_array( $response->get_error_code(), array( 'prompt_network_error', 'prompt_upstream_server_error' ), true );
				return new WP_Error( $response->get_error_code(), self::friendly_error( $response ), array( 'retry' => $retry ) );
			}

			$is_estimate = false;
			$in_tokens   = (int) $response['input_tokens'];
			$out_tokens  = (int) $response['output_tokens'];
			if ( $in_tokens <= 0 ) {
				$in_tokens   = $est_in;
				$is_estimate = true;
			}
			if ( $out_tokens <= 0 ) {
				$out_tokens  = $est_out;
				$is_estimate = true;
			}

			// Always price from the model that actually answered.
			$cost        = Pricing::call_cost( $response['provider'], $response['model'], $in_tokens, $out_tokens );
			$price_known = null !== $cost;
			if ( ! $price_known ) {
				// Unknown price: count the worst-case reservation so the cap stays conservative.
				$cost = max( 1, $reserve );
				if ( $strict ) {
					update_option( self::PRICE_BLOCK, $response['model'], false );
				}
			}
			Budget::reconcile( $res_id, (int) $cost );

			UsageLog::log(
				array(
					'run_id'            => (string) ( $context['run_id'] ?? '' ),
					'task'              => $task,
					'provider'          => $response['provider'],
					'model'             => $response['model'],
					'requested_model'   => $requested,
					'input_tokens'      => $in_tokens,
					'output_tokens'     => $out_tokens,
					'est_input_tokens'  => $est_in,
					'est_output_tokens' => $est_out,
					'pre_cost_micros'   => $pre_cost,
					'est_cost_micros'   => (int) $cost,
					'is_estimate'       => $is_estimate,
					'price_known'       => $price_known,
					'post_id'           => (int) ( $context['post_id'] ?? 0 ),
				)
			);
			update_option(
				'ailsug_last_model',
				array(
					'provider' => $response['provider'],
					'model'    => $response['model'],
					'time'     => time(),
				),
				false
			);
			if ( ! $is_estimate ) {
				Calibrator::record( $task, $est_raw, $in_tokens, $planned['output'], $out_tokens );
			}

			$decoded = self::decode_json( $response['text'] );
			$clean   = 'keyword' === $task ? Schemas::clean_keyword( $decoded ) : ( is_array( $decoded ) && isset( $decoded['suggestions'] ) && is_array( $decoded['suggestions'] ) ? $decoded : null );
			if ( null !== $clean ) {
				return array(
					'data'     => $clean,
					'provider' => $response['provider'],
					'model'    => $response['model'],
					'cost'     => (int) $cost,
				);
			}
			Logger::debug(
				'AI answer was not valid JSON',
				array(
					'task'    => $task,
					'attempt' => $attempt,
				)
			);
		}//end for

		return new WP_Error( 'ailsug_bad_json', __( 'The AI answer was not in the expected format, so this page was skipped.', 'ai-internal-link-suggester' ), array( 'retry' => false ) );
	}

	/**
	 * Send one request to the AI Client.
	 *
	 * @param array $request Request: system, prompt, schema, max_tokens, task (keyword|rank) and data (structured payload, used by the mock).
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
		$err_msg = $error->get_error_message();
		if ( 429 === $status || false !== strpos( $err_msg, '429' ) || false !== stripos( $err_msg, 'rate limit' ) || false !== stripos( $err_msg, 'quota' ) || false !== stripos( $err_msg, 'resource has been exhausted' ) ) {
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
