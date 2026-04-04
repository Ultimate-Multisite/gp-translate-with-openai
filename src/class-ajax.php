<?php
/**
 * Ajax class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/**
 * Ajax class.
 */
class Ajax {

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'wp_ajax_gpoai_translate', array( $this, 'translate' ), 10 );
		add_action( 'wp_ajax_gpoai_refresh_models', array( $this, 'refresh_models' ), 10 );
	}

	/**
	 * Translate a string.
	 *
	 * @return void
	 */
	public function translate() {
		global $gpoai_translate;

		if ( ! isset( $gpoai_translate ) ) {
			wp_send_json(
				array(
					'success' => false,
					'error'   => array(
						'message' => 'GlotPress not yet loaded.',
						'reason'  => '',
					),
				)
			);
		}

		if ( ! isset( $_POST['original'] ) || ! isset( $_POST['locale'] ) ) {
			wp_send_json(
				array(
					'success' => false,
					'error'   => array(
						'message' => 'Missing parameters.',
						'reason'  => '',
					),
				)
			);
		}

		if ( ! isset( $_POST['nonce'] ) ) {
			wp_send_json(
				array(
					'success' => false,
					'error'   => array(
						'message' => 'Missing nonce.',
						'reason'  => '',
					),
				)
			);
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'gpoai_nonce' ) ) {
			wp_send_json(
				array(
					'success' => false,
					'error'   => array(
						'message' => 'Invalid nonce.',
						'reason'  => '',
					),
				)
			);
		}

		$locale = sanitize_text_field( wp_unslash( $_POST['locale'] ) );
		$string = sanitize_text_field( wp_unslash( $_POST['original'] ) );

		$translate  = Translate::instance();
		$new_string = $translate->translate( $string, $locale );

		if ( is_wp_error( $new_string ) ) {
			$response = array(
				'success' => false,
				'error'   => array(
					'message' => $new_string->get_error_message(),
					'reason'  => $new_string->get_error_data(),
				),
			);
		} else {
			$response = array(
				'success' => true,
				'data'    => array( 'translatedText' => $new_string ),
			);
		}

		wp_send_json( $response );
	}

	/**
	 * Refresh models from the API.
	 *
	 * @return void
	 */
	public function refresh_models(): void {
		// Check nonce.
		if ( ! isset( $_POST['nonce'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Missing nonce.', 'gp-translate-with-openai' ) ) );
		}

		$nonce = sanitize_text_field( wp_unslash( $_POST['nonce'] ) );
		if ( ! wp_verify_nonce( $nonce, 'gpoai_refresh_models' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'gp-translate-with-openai' ) ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-translate-with-openai' ) ) );
		}

		// Clear cache and fetch fresh models.
		Api::clear_models_cache();
		$result = Api::get_available_models( true );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Failed to fetch models: %s', 'gp-translate-with-openai' ),
						$result->get_error_message()
					),
				)
			);
		}

		wp_send_json_success(
			array(
				'models'  => $result,
				'message' => sprintf(
					/* translators: %d: number of models */
					__( 'Found %d models.', 'gp-translate-with-openai' ),
					count( $result )
				),
			)
		);
	}
}
