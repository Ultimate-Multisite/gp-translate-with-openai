<?php
/**
 * Settings class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/**
 * Settings class.
 */
class Settings {

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_init', array( $this, 'init_settings' ), 10 );
	}

	/**
	 * Initialize settings.
	 *
	 * @return void
	 */
	public function init_settings(): void {
		// Section: OpenAI API.
		add_settings_section(
			'gpoai_section',
			__( 'OpenAI API', 'gp-translate-with-openai' ),
			array( $this, 'render_section' ),
			'gpoai_settings'
		);

		// Option: API Key.
		$this->register_field_api_key();

		// Option: Base URL.
		$this->register_field_base_url();

		// Option: Model.
		$this->register_field_model();

		// Option: Custom Prompt.
		$this->register_field_custom_prompt();

		// Option: Locale Instructions.
		$this->register_field_locale_instructions();

		// Option: Temperature.
		$this->register_field_temperature();

		// Option: Use Glossary.
		$this->register_field_use_glossary();

		// Option: Max Concurrent Requests.
		$this->register_field_max_concurrent();

		// Section: Automation.
		add_settings_section(
			'gpoai_automation_section',
			__( 'Automation', 'gp-translate-with-openai' ),
			array( $this, 'render_automation_section' ),
			'gpoai_settings'
		);

		// Option: Automation Enabled.
		$this->register_field_automation_enabled();

		// Option: Automation Locales.
		$this->register_field_automation_locales();
	}

	/**
	 * Render section.
	 *
	 * @return void
	 */
	public function render_section(): void {
		esc_html_e( 'Settings for OpenAI API access.', 'gp-translate-with-openai' );
	}

	/**
	 * Render automation section.
	 *
	 * @return void
	 */
	public function render_automation_section(): void {
		esc_html_e( 'Settings for automated translation when new strings are imported.', 'gp-translate-with-openai' );
	}

	/**
	 * Register settings field API Key.
	 *
	 * @return void
	 */
	public function register_field_api_key(): void {
		$field_name    = 'gpoai_api_key';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'OpenAI API Key', 'gp-translate-with-openai' ),
				'description'       => __( 'Enter the OpenAI API Key.', 'gp-translate-with-openai' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'OpenAI API Key', 'gp-translate-with-openai' ),
			array( $this, 'render_field_api_key' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Register settings for Model.
	 *
	 * @return void
	 */
	public function register_field_model(): void {
		$field_name    = 'gpoai_model';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'OpenAI Model', 'gp-translate-with-openai' ),
				'description'       => __( 'Select the OpenAI Model.', 'gp-translate-with-openai' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'OpenAI Model', 'gp-translate-with-openai' ),
			array( $this, 'render_field_model' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Register settings for OpenAI Custom Prompt.
	 *
	 * @return void
	 */
	public function register_field_custom_prompt(): void {
		$field_name    = 'gpoai_custom_prompt';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'System Prompt', 'gp-translate-with-openai' ),
				'description'       => __( 'The system prompt template sent to the AI model.', 'gp-translate-with-openai' ),
				'type'              => 'string',
				'sanitize_callback' => array( $this, 'sanitize_system_prompt' ),
				'default'           => '',
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'System Prompt', 'gp-translate-with-openai' ),
			array( $this, 'render_field_custom_prompt' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Sanitize the system prompt. Allow newlines but sanitize for safety.
	 *
	 * @param string $value The value to sanitize.
	 *
	 * @return string
	 */
	public function sanitize_system_prompt( $value ): string {
		return wp_kses( $value, array() );
	}

	/**
	 * Register settings for OpenAI Temperature.
	 *
	 * @return void
	 */
	public function register_field_temperature(): void {
		$field_name    = 'gpoai_temperature';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'OpenAI Temperature', 'gp-translate-with-openai' ),
				'description'       => __( 'Enter the OpenAI Temperature.', 'gp-translate-with-openai' ),
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
				'default'           => '',
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'OpenAI Temperature', 'gp-translate-with-openai' ),
			array( $this, 'render_field_temperature' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Render settings field API Key.
	 *
	 * @return void
	 */
	public function render_field_api_key(): void {
		$field_name = 'gpoai_api_key';

		$api_key = get_option( $field_name, '' );
		?>
		<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $api_key ); ?>" class="regular-text">
		<p class="description"><?php esc_html_e( 'Enter the OpenAI API Key.', 'gp-translate-with-openai' ); ?></p>
		<?php
	}

	/**
	 * Render settings for OpenAI Model.
	 *
	 * @return void
	 */
	public function render_field_model(): void {
		$field_name = 'gpoai_model';

		// Get models from API (with caching).
		$models        = Api::get_available_models();
		if ( is_wp_error( $models ) ) {
			$models = Api::DEFAULT_MODELS;
		}
		$current_model = get_option( $field_name, 'gpt-3.5-turbo' );

		// Ensure current model is in the list.
		if ( ! empty( $current_model ) && ! in_array( $current_model, $models, true ) ) {
			array_unshift( $models, $current_model );
		}
		?>
		<select name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>">
		<?php foreach ( $models as $model_name ) { ?>
			<option value="<?php echo esc_attr( $model_name ); ?>" <?php selected( $current_model, $model_name ); ?>><?php echo esc_html( $model_name ); ?></option>
		<?php } ?>
		</select>
		<button type="button" class="button button-secondary" id="gpoai-refresh-models" style="margin-left: 10px;">
			<?php esc_html_e( 'Refresh Models', 'gp-translate-with-openai' ); ?>
		</button>
		<span id="gpoai-refresh-status" style="margin-left: 10px;"></span>
		<p class="description">
			<?php esc_html_e( 'Select the model to use for translations. Models are fetched from the configured API.', 'gp-translate-with-openai' ); ?>
			<?php if ( count( $models ) > 0 ) : ?>
				<br>
				<?php
				printf(
					/* translators: %d: number of available models */
					esc_html__( '%d models available.', 'gp-translate-with-openai' ),
					count( $models )
				);
				?>
			<?php endif; ?>
		</p>
		<script>
		jQuery(document).ready(function($) {
			$('#gpoai-refresh-models').on('click', function() {
				var $button = $(this);
				var $status = $('#gpoai-refresh-status');
				var $select = $('#<?php echo esc_js( $field_name ); ?>');
				var currentValue = $select.val();

				$button.prop('disabled', true);
				$status.text('<?php echo esc_js( __( 'Refreshing...', 'gp-translate-with-openai' ) ); ?>');

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'gpoai_refresh_models',
						nonce: '<?php echo esc_js( wp_create_nonce( 'gpoai_refresh_models' ) ); ?>'
					},
					success: function(response) {
						if (response.success && response.data.models) {
							$select.empty();
							$.each(response.data.models, function(index, model) {
								$select.append($('<option>', {
									value: model,
									text: model,
									selected: model === currentValue
								}));
							});
							$status.text(response.data.message).css('color', 'green');
						} else {
							$status.text(response.data.message || '<?php echo esc_js( __( 'Error refreshing models.', 'gp-translate-with-openai' ) ); ?>').css('color', 'red');
						}
					},
					error: function() {
						$status.text('<?php echo esc_js( __( 'Error refreshing models.', 'gp-translate-with-openai' ) ); ?>').css('color', 'red');
					},
					complete: function() {
						$button.prop('disabled', false);
						setTimeout(function() { $status.text(''); }, 5000);
					}
				});
			});
		});
		</script>
		<?php
	}

	/**
	 * Render settings for OpenAI Custom Prompt.
	 *
	 * @return void
	 */
	public function render_field_custom_prompt(): void {
		$field_name = 'gpoai_custom_prompt';

		$custom_prompt = get_option( $field_name, '' );
		$default       = Config::DEFAULT_SYSTEM_PROMPT;

		// If empty, show default so users can easily tweak it.
		$display_value = ! empty( $custom_prompt ) ? $custom_prompt : $default;
		?>
		<textarea name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" class="large-text" rows="6"><?php echo esc_textarea( $display_value ); ?></textarea>
		<p class="description">
			<?php esc_html_e( 'The system prompt sent to the AI model. The user message will contain only the text to translate.', 'gp-translate-with-openai' ); ?>
			<br>
			<?php esc_html_e( 'Available placeholders:', 'gp-translate-with-openai' ); ?>
			<code>{SOURCE_LANGUAGE}</code>, <code>{TARGET_LANGUAGE}</code>, <code>{CONTEXT}</code>, <code>{GLOSSARY}</code>, <code>{LOCALE_INSTRUCTIONS}</code>, <code>{NEIGHBORING_STRINGS}</code>
		</p>
		<?php
	}

	/**
	 * Register settings field Locale Instructions.
	 *
	 * @return void
	 */
	public function register_field_locale_instructions(): void {
		$field_name    = 'gpoai_locale_instructions';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'Locale Instructions', 'gp-translate-with-openai' ),
				'description'       => __( 'Per-locale translation instructions included in the prompt.', 'gp-translate-with-openai' ),
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_locale_instructions' ),
				'default'           => array(),
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'Locale Instructions', 'gp-translate-with-openai' ),
			array( $this, 'render_field_locale_instructions' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Sanitize locale instructions.
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return array
	 */
	public function sanitize_locale_instructions( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		$sanitized = array();
		foreach ( $value as $locale => $instructions ) {
			$locale       = sanitize_text_field( $locale );
			$instructions = sanitize_textarea_field( $instructions );
			if ( ! empty( $instructions ) ) {
				$sanitized[ $locale ] = $instructions;
			}
		}

		return $sanitized;
	}

	/**
	 * Render settings field Locale Instructions.
	 *
	 * @return void
	 */
	public function render_field_locale_instructions(): void {
		$field_name = 'gpoai_locale_instructions';
		$saved      = get_option( $field_name, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$defaults          = Locale_Instructions::get_default_instructions();
		$available_locales = Locales::get_supported_locales();

		// Show locales that have saved instructions or defaults.
		$shown_locales = array_unique( array_merge( array_keys( $saved ), array_keys( $defaults ) ) );
		sort( $shown_locales );

		// Remaining locales for the "Add locale" dropdown.
		$remaining_locales = array_diff( array_keys( $available_locales ), $shown_locales );
		?>
		<div id="gpoai-locale-instructions-wrap">
		<?php foreach ( $shown_locales as $locale ) :
			$locale_name  = $available_locales[ $locale ] ?? $locale;
			$saved_value  = $saved[ $locale ] ?? '';
			$default_text = $defaults[ $locale ] ?? '';
		?>
			<div class="gpoai-locale-instruction" style="margin-bottom: 10px;">
				<label><strong><?php echo esc_html( $locale_name ); ?> (<?php echo esc_html( $locale ); ?>)</strong></label><br>
				<textarea name="<?php echo esc_attr( $field_name ); ?>[<?php echo esc_attr( $locale ); ?>]" class="large-text" rows="2" placeholder="<?php echo esc_attr( $default_text ); ?>"><?php echo esc_textarea( $saved_value ); ?></textarea>
			</div>
		<?php endforeach; ?>
		</div>

		<?php if ( ! empty( $remaining_locales ) ) : ?>
		<div style="margin-top: 10px;">
			<select id="gpoai-add-locale-select">
				<option value=""><?php esc_html_e( '— Add locale —', 'gp-translate-with-openai' ); ?></option>
				<?php foreach ( $remaining_locales as $locale ) : ?>
					<option value="<?php echo esc_attr( $locale ); ?>"><?php echo esc_html( ( $available_locales[ $locale ] ?? $locale ) . ' (' . $locale . ')' ); ?></option>
				<?php endforeach; ?>
			</select>
			<button type="button" class="button" id="gpoai-add-locale-btn"><?php esc_html_e( 'Add', 'gp-translate-with-openai' ); ?></button>
		</div>
		<script>
		jQuery(document).ready(function($) {
			$('#gpoai-add-locale-btn').on('click', function() {
				var $sel = $('#gpoai-add-locale-select');
				var locale = $sel.val();
				if (!locale) return;
				var label = $sel.find('option:selected').text();
				var html = '<div class="gpoai-locale-instruction" style="margin-bottom: 10px;">' +
					'<label><strong>' + label + '</strong></label><br>' +
					'<textarea name="<?php echo esc_attr( $field_name ); ?>[' + locale + ']" class="large-text" rows="2"></textarea>' +
					'</div>';
				$('#gpoai-locale-instructions-wrap').append(html);
				$sel.find('option:selected').remove();
				$sel.val('');
			});
		});
		</script>
		<?php endif; ?>

		<p class="description"><?php esc_html_e( 'Per-locale instructions injected into the prompt via {LOCALE_INSTRUCTIONS}. Leave empty to use the default. Clear text to remove.', 'gp-translate-with-openai' ); ?></p>
		<?php
	}

	/**
	 * Render settings for OpenAI Temperature.
	 *
	 * @return void
	 */
	public function render_field_temperature(): void {
		$field_name = 'gpoai_temperature';

		$temp = get_option( $field_name, '' );
		$display_temp = '' !== $temp && false !== $temp ? $temp : '0.0';
		?>
		<input type="text" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $display_temp ); ?>" class="small-text">
		<p class="description"><?php esc_html_e( 'Controls randomness. Lower values produce more consistent translations.', 'gp-translate-with-openai' ); ?> <?php esc_html_e( 'Default:', 'gp-translate-with-openai' ); ?> <code>0.0</code></p>
		<?php
	}

	/**
	 * Register settings field Base URL.
	 *
	 * @return void
	 */
	public function register_field_base_url(): void {
		$field_name    = 'gpoai_base_url';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'OpenAI Base URL', 'gp-translate-with-openai' ),
				'description'       => __( 'Enter a custom base URL for OpenAI-compatible APIs (e.g., Ollama, Azure OpenAI).', 'gp-translate-with-openai' ),
				'type'              => 'string',
				'sanitize_callback' => 'esc_url_raw',
				'default'           => '',
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'OpenAI Base URL', 'gp-translate-with-openai' ),
			array( $this, 'render_field_base_url' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Render settings field Base URL.
	 *
	 * @return void
	 */
	public function render_field_base_url(): void {
		$field_name = 'gpoai_base_url';

		$base_url = get_option( $field_name, '' );
		?>
		<input type="url" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $base_url ); ?>" class="regular-text" placeholder="https://api.openai.com">
		<p class="description"><?php esc_html_e( 'Leave empty to use the default OpenAI API. Enter a custom URL for alternative providers like Ollama or Azure OpenAI.', 'gp-translate-with-openai' ); ?></p>
		<?php
	}

	/**
	 * Register settings field Use Glossary.
	 *
	 * @return void
	 */
	public function register_field_use_glossary(): void {
		$field_name    = 'gpoai_use_glossary';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'Use Glossary', 'gp-translate-with-openai' ),
				'description'       => __( 'Include glossary terms in translation prompts.', 'gp-translate-with-openai' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => true,
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'Use Glossary', 'gp-translate-with-openai' ),
			array( $this, 'render_field_use_glossary' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Render settings field Use Glossary.
	 *
	 * @return void
	 */
	public function render_field_use_glossary(): void {
		$field_name = 'gpoai_use_glossary';

		$use_glossary = get_option( $field_name, true );
		?>
		<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="1" <?php checked( $use_glossary, true ); ?>>
		<p class="description"><?php esc_html_e( 'When enabled, matching glossary terms will be included in the translation prompt to improve consistency.', 'gp-translate-with-openai' ); ?></p>
		<?php
	}

	/**
	 * Register settings field Max Concurrent Requests.
	 *
	 * @return void
	 */
	public function register_field_max_concurrent(): void {
		$field_name    = 'gpoai_max_concurrent_requests';
		$section_name  = 'gpoai_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'Max Concurrent Requests', 'gp-translate-with-openai' ),
				'description'       => __( 'Maximum number of parallel translation requests.', 'gp-translate-with-openai' ),
				'type'              => 'integer',
				'sanitize_callback' => array( $this, 'sanitize_max_concurrent' ),
				'default'           => 1,
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'Max Concurrent Requests', 'gp-translate-with-openai' ),
			array( $this, 'render_field_max_concurrent' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Sanitize max concurrent requests value.
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return int
	 */
	public function sanitize_max_concurrent( $value ): int {
		$value = (int) $value;

		return max( 1, min( 50, $value ) );
	}

	/**
	 * Render settings field Max Concurrent Requests.
	 *
	 * @return void
	 */
	public function render_field_max_concurrent(): void {
		$field_name = 'gpoai_max_concurrent_requests';

		$value = get_option( $field_name, 1 );
		?>
		<input type="number" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="small-text" min="1" max="50">
		<p class="description"><?php esc_html_e( 'Number of translation requests to send in parallel. Higher values speed up batch operations but increase API load.', 'gp-translate-with-openai' ); ?> <?php esc_html_e( 'Default:', 'gp-translate-with-openai' ); ?> <code>1</code></p>
		<?php
	}

	/**
	 * Register settings field Automation Enabled.
	 *
	 * @return void
	 */
	public function register_field_automation_enabled(): void {
		$field_name    = 'gpoai_automation_enabled';
		$section_name  = 'gpoai_automation_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'Enable Automation', 'gp-translate-with-openai' ),
				'description'       => __( 'Automatically translate new strings when they are imported.', 'gp-translate-with-openai' ),
				'type'              => 'boolean',
				'sanitize_callback' => 'rest_sanitize_boolean',
				'default'           => false,
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'Enable Automation', 'gp-translate-with-openai' ),
			array( $this, 'render_field_automation_enabled' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Render settings field Automation Enabled.
	 *
	 * @return void
	 */
	public function render_field_automation_enabled(): void {
		$field_name = 'gpoai_automation_enabled';

		$enabled = get_option( $field_name, false );
		?>
		<input type="checkbox" name="<?php echo esc_attr( $field_name ); ?>" id="<?php echo esc_attr( $field_name ); ?>" value="1" <?php checked( $enabled, true ); ?>>
		<p class="description"><?php esc_html_e( 'When enabled, new strings will be automatically translated to the selected locales when imported.', 'gp-translate-with-openai' ); ?></p>
		<?php
	}

	/**
	 * Register settings field Automation Locales.
	 *
	 * @return void
	 */
	public function register_field_automation_locales(): void {
		$field_name    = 'gpoai_automation_locales';
		$section_name  = 'gpoai_automation_section';
		$settings_name = 'gpoai_settings';

		register_setting(
			$settings_name,
			$field_name,
			array(
				'label'             => __( 'Automation Locales', 'gp-translate-with-openai' ),
				'description'       => __( 'Select the locales to automatically translate to.', 'gp-translate-with-openai' ),
				'type'              => 'array',
				'sanitize_callback' => array( $this, 'sanitize_automation_locales' ),
				'default'           => array(),
				'show_in_rest'      => false,
			),
		);

		add_settings_field(
			$field_name,
			__( 'Target Locales', 'gp-translate-with-openai' ),
			array( $this, 'render_field_automation_locales' ),
			$settings_name,
			$section_name,
			array(
				'label_for' => $field_name,
			),
		);
	}

	/**
	 * Sanitize automation locales.
	 *
	 * @param mixed $value The value to sanitize.
	 *
	 * @return array
	 */
	public function sanitize_automation_locales( $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_map( 'sanitize_text_field', $value );
	}

	/**
	 * Render settings field Automation Locales.
	 *
	 * @return void
	 */
	public function render_field_automation_locales(): void {
		$field_name = 'gpoai_automation_locales';

		$selected_locales = get_option( $field_name, array() );
		if ( ! is_array( $selected_locales ) ) {
			$selected_locales = array();
		}

		$available_locales = Locales::get_supported_locales();
		?>
		<select name="<?php echo esc_attr( $field_name ); ?>[]" id="<?php echo esc_attr( $field_name ); ?>" multiple style="min-width: 300px; min-height: 150px;">
		<?php foreach ( $available_locales as $locale_slug => $locale_name ) { ?>
			<option value="<?php echo esc_attr( $locale_slug ); ?>" <?php selected( in_array( $locale_slug, $selected_locales, true ), true ); ?>><?php echo esc_html( $locale_name ); ?></option>
		<?php } ?>
		</select>
		<p class="description"><?php esc_html_e( 'Select the locales to automatically translate new strings to. Hold Ctrl/Cmd to select multiple.', 'gp-translate-with-openai' ); ?></p>
		<?php
	}
}
