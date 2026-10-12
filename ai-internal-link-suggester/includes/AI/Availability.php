<?php
/**
 * Detects whether AI features can be used, via the WordPress AI Client only.
 *
 * @package AILSUG
 */

namespace AILSUG\AI;

use AILSUG\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Availability checks. Some checks make core list the provider's models (a network call
 * cached by core), so the full check is only run on explicit admin screens and cached here.
 */
final class Availability {

	const TRANSIENT = 'ailsug_ai_status';
	const TTL       = 12 * HOUR_IN_SECONDS;

	/**
	 * Whether the AI Client functions exist (WordPress 7.0+).
	 *
	 * @return bool
	 */
	public static function core_present() {
		return function_exists( 'wp_ai_client_prompt' ) && class_exists( '\\WordPress\\AiClient\\AiClient' );
	}

	/**
	 * Whether mock mode is on (no network calls, fake results).
	 *
	 * @return bool
	 */
	public static function is_mock() {
		return defined( 'AILSUG_MOCK_AI' ) && AILSUG_MOCK_AI;
	}

	/**
	 * Cached status without running any check. Safe to call on every page.
	 *
	 * @return array|null Null when no check has run yet.
	 */
	public static function cached() {
		if ( self::is_mock() ) {
			return self::mock_status();
		}
		$status = get_transient( self::TRANSIENT );
		return is_array( $status ) ? $status : null;
	}

	/**
	 * Full status. Uses the cache unless $refresh is true.
	 *
	 * @param bool $refresh Force a fresh check.
	 * @return array{available:bool,code:string,message:string,providers:string[],registered:string[],checked_at:int}
	 */
	public static function status( $refresh = false ) {
		if ( self::is_mock() ) {
			return self::mock_status();
		}
		if ( ! $refresh ) {
			$cached = self::cached();
			if ( null !== $cached ) {
				return $cached;
			}
		}
		$status = self::check();
		set_transient( self::TRANSIENT, $status, self::TTL );
		return $status;
	}

	/**
	 * Forget the cached status (e.g. after the admin changes connectors).
	 *
	 * @return void
	 */
	public static function forget() {
		delete_transient( self::TRANSIENT );
	}

	/**
	 * Quick yes/no for gating AI UI, from cache only. Unknown counts as unavailable.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$status = self::cached();
		return is_array( $status ) && ! empty( $status['available'] );
	}

	/**
	 * Perform the check.
	 *
	 * @return array
	 */
	private static function check() {
		$status = array(
			'available'  => false,
			'code'       => '',
			'message'    => '',
			'providers'  => array(),
			'registered' => array(),
			'checked_at' => time(),
		);

		if ( ! self::core_present() ) {
			$status['code']    = 'no_core';
			$status['message'] = __( 'This version of WordPress has no built-in AI support. AI features need WordPress 7.0 or newer.', 'interlink-ai' );
			return $status;
		}

		if ( function_exists( 'wp_supports_ai' ) && ! wp_supports_ai() ) {
			$status['code']    = 'disabled';
			$status['message'] = __( 'AI features are turned off on this site (by the site configuration or another plugin).', 'interlink-ai' );
			return $status;
		}

		try {
			$registry = \WordPress\AiClient\AiClient::defaultRegistry();
			foreach ( $registry->getRegisteredProviderIds() as $id ) {
				$status['registered'][] = (string) $id;
				if ( $registry->isProviderConfigured( $id ) ) {
					$status['providers'][] = (string) $id;
				}
			}
		} catch ( \Throwable $e ) {
			$status['code']    = 'registry_error';
			$status['message'] = __( 'WordPress could not check your AI connections. Try again in a moment.', 'interlink-ai' );
			return $status;
		}

		if ( ! $status['providers'] ) {
			$status['code']    = 'no_provider';
			$status['message'] = __( 'No AI provider is connected. Open Settings → Connectors to add one.', 'interlink-ai' );
			return $status;
		}

		// This also applies the wp_ai_client_prevent_prompt filter.
		$supported = wp_ai_client_prompt( 'ping' )->is_supported_for_text_generation();
		if ( ! $supported ) {
			$status['code']    = 'unsupported';
			$status['message'] = __( 'Your connected AI provider cannot be used for text right now, or another plugin is blocking AI requests.', 'interlink-ai' );
			return $status;
		}

		$status['available'] = true;
		$status['code']      = 'ok';
		$status['message']   = __( 'AI is connected and ready.', 'interlink-ai' );
		return $status;
	}

	/**
	 * Text-capable models per configured provider, in core's selection order.
	 *
	 * @return array<string,string[]> provider id => model ids.
	 */
	public static function text_models() {
		if ( self::is_mock() ) {
			return array( 'mock' => array( MockGateway::MODEL ) );
		}
		$cache = get_transient( 'ailsug_ai_models' );
		if ( is_array( $cache ) ) {
			return $cache;
		}
		$out = array();
		if ( ! self::core_present() ) {
			return $out;
		}
		try {
			$requirements = new \WordPress\AiClient\Providers\Models\DTO\ModelRequirements(
				array( \WordPress\AiClient\Providers\Models\Enums\CapabilityEnum::textGeneration() ),
				array()
			);
			$registry     = \WordPress\AiClient\AiClient::defaultRegistry();
			foreach ( $registry->findModelsMetadataForSupport( $requirements ) as $provider_models ) {
				$pid = (string) $provider_models->getProvider()->getId();
				foreach ( $provider_models->getModels() as $meta ) {
					$out[ $pid ][] = (string) $meta->getId();
				}
			}
		} catch ( \Throwable $e ) {
			return array();
		}
		set_transient( 'ailsug_ai_models', $out, self::TTL );
		return $out;
	}

	/**
	 * Admin URL of the Connectors screen.
	 *
	 * @return string
	 */
	public static function connectors_url() {
		return admin_url( 'options-connectors.php' );
	}

	/**
	 * Official provider plugins (descriptive mention only).
	 *
	 * @return array<string,string> slug => label.
	 */
	public static function provider_plugins() {
		return array(
			'ai-provider-for-anthropic' => 'AI Provider for Anthropic',
			'ai-provider-for-google'    => 'AI Provider for Google',
			'ai-provider-for-openai'    => 'AI Provider for OpenAI',
		);
	}

	/**
	 * Status reported while mock mode is on.
	 *
	 * @return array
	 */
	private static function mock_status() {
		return array(
			'available'  => true,
			'code'       => 'mock',
			'message'    => __( 'Test mode: AI answers are simulated and nothing is sent or billed.', 'interlink-ai' ),
			'providers'  => array( 'mock' ),
			'registered' => array( 'mock' ),
			'checked_at' => time(),
		);
	}

	/**
	 * Settings-derived ids for the "only this model" mode.
	 *
	 * @return array{0:string,1:string}|null [provider, model] or null.
	 */
	public static function only_model() {
		$value = (string) Settings::get( 'only_model', '' );
		if ( '' === $value || false === strpos( $value, ':' ) ) {
			return null;
		}
		list( $provider, $model ) = explode( ':', $value, 2 );
		return array( $provider, $model );
	}
}
