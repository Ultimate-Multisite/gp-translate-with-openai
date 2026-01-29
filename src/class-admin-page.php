<?php
/**
 * Admin Page class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/**
 * Admin Page class.
 */
class Admin_Page {

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 10 );
		add_action( 'admin_post_gpoai_import_glossary', array( $this, 'handle_glossary_import' ) );
		add_action( 'admin_notices', array( $this, 'admin_notices' ) );
	}

	/**
	 * Add menu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'GP Translate with OpenAI', 'gp-translate-with-openai' ),
			__( 'GP Translate with OpenAI', 'gp-translate-with-openai' ),
			'manage_options',
			'gp-translate-with-openai',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'GP Translate with OpenAI', 'gp-translate-with-openai' ); ?></h1>

			<form method="post" action="options.php">
				<?php
				settings_fields( 'gpoai_settings' );
				do_settings_sections( 'gpoai_settings' );
				submit_button();
				?>
			</form>

			<hr>

			<?php $this->render_glossary_import_section(); ?>

			<hr>

			<?php $this->render_manual_translate_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render the glossary import section.
	 *
	 * @return void
	 */
	protected function render_glossary_import_section(): void {
		$available_locales = Locales::get_supported_locales();
		?>
		<h2><?php esc_html_e( 'Import Glossary from WordPress.org', 'gp-translate-with-openai' ); ?></h2>
		<p><?php esc_html_e( 'Import glossary entries from translate.wordpress.org for a specific locale.', 'gp-translate-with-openai' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gpoai_import_glossary">
			<?php wp_nonce_field( 'gpoai_import_glossary', 'gpoai_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="import_locale"><?php esc_html_e( 'Locale', 'gp-translate-with-openai' ); ?></label>
					</th>
					<td>
						<select name="import_locale" id="import_locale">
							<?php foreach ( $available_locales as $locale_slug => $locale_name ) { ?>
								<option value="<?php echo esc_attr( $locale_slug ); ?>"><?php echo esc_html( $locale_name ); ?></option>
							<?php } ?>
						</select>
						<?php
						$import_times = get_option( 'gpoai_glossary_import_times', array() );
						if ( ! empty( $import_times ) ) {
							echo '<p class="description">';
							esc_html_e( 'Last imports:', 'gp-translate-with-openai' );
							echo '<br>';
							foreach ( $import_times as $locale => $timestamp ) {
								$locale_name = $available_locales[ $locale ] ?? $locale;
								echo esc_html( $locale_name ) . ': ' . esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $timestamp ) ) . '<br>';
							}
							echo '</p>';
						}
						?>
					</td>
				</tr>
			</table>

			<?php submit_button( __( 'Import Glossary', 'gp-translate-with-openai' ), 'secondary' ); ?>
		</form>
		<?php
	}

	/**
	 * Render the manual translate section.
	 *
	 * @return void
	 */
	protected function render_manual_translate_section(): void {
		$projects          = Automation::get_projects();
		$available_locales = Locales::get_supported_locales();
		$pending_jobs      = Automation::get_pending_jobs_count();

		// Check if Action Scheduler is available.
		$action_scheduler_available = function_exists( 'as_schedule_single_action' );
		?>
		<h2><?php esc_html_e( 'Manual Translation', 'gp-translate-with-openai' ); ?></h2>

		<?php if ( ! $action_scheduler_available ) { ?>
			<div class="notice notice-warning inline">
				<p><?php esc_html_e( 'Action Scheduler is not available. Please install it via Composer to enable automated translations.', 'gp-translate-with-openai' ); ?></p>
			</div>
		<?php } ?>

		<?php if ( $pending_jobs > 0 ) { ?>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %d: number of pending jobs */
						esc_html__( 'There are %d pending translation jobs in the queue.', 'gp-translate-with-openai' ),
						$pending_jobs
					);
					?>
				</p>
			</div>
		<?php } ?>

		<p><?php esc_html_e( 'Manually trigger translation for a specific project and locale.', 'gp-translate-with-openai' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="gpoai_manual_translate">
			<?php wp_nonce_field( 'gpoai_manual_translate', 'gpoai_nonce' ); ?>

			<table class="form-table">
				<tr>
					<th scope="row">
						<label for="project_id"><?php esc_html_e( 'Project', 'gp-translate-with-openai' ); ?></label>
					</th>
					<td>
						<select name="project_id" id="project_id">
							<?php if ( empty( $projects ) ) { ?>
								<option value=""><?php esc_html_e( 'No projects available', 'gp-translate-with-openai' ); ?></option>
							<?php } else { ?>
								<?php foreach ( $projects as $project ) { ?>
									<option value="<?php echo esc_attr( $project->id ); ?>"><?php echo esc_html( $project->name ); ?></option>
								<?php } ?>
							<?php } ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row">
						<label for="translate_locale"><?php esc_html_e( 'Target Locale', 'gp-translate-with-openai' ); ?></label>
					</th>
					<td>
						<select name="locale" id="translate_locale">
							<?php foreach ( $available_locales as $locale_slug => $locale_name ) { ?>
								<option value="<?php echo esc_attr( $locale_slug ); ?>"><?php echo esc_html( $locale_name ); ?></option>
							<?php } ?>
						</select>
					</td>
				</tr>
			</table>

			<?php
			submit_button(
				__( 'Start Translation', 'gp-translate-with-openai' ),
				'secondary',
				'submit',
				true,
				$action_scheduler_available && ! empty( $projects ) ? array() : array( 'disabled' => 'disabled' )
			);
			?>
		</form>
		<?php
	}

	/**
	 * Handle glossary import action.
	 *
	 * @return void
	 */
	public function handle_glossary_import(): void {
		// Verify nonce.
		if ( ! isset( $_POST['gpoai_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['gpoai_nonce'] ) ), 'gpoai_import_glossary' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'gp-translate-with-openai' ) );
		}

		// Check capabilities.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'gp-translate-with-openai' ) );
		}

		$locale = isset( $_POST['import_locale'] ) ? sanitize_text_field( wp_unslash( $_POST['import_locale'] ) ) : '';

		if ( empty( $locale ) ) {
			wp_safe_redirect( add_query_arg( 'gpoai_error', 'missing_locale', admin_url( 'options-general.php?page=gp-translate-with-openai' ) ) );
			exit;
		}

		$imported = Glossary::import_from_wporg( $locale );

		if ( -1 === $imported ) {
			wp_safe_redirect( add_query_arg( 'gpoai_error', 'import_failed', admin_url( 'options-general.php?page=gp-translate-with-openai' ) ) );
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'gpoai_success'  => 'glossary_imported',
					'gpoai_imported' => $imported,
				),
				admin_url( 'options-general.php?page=gp-translate-with-openai' )
			)
		);
		exit;
	}

	/**
	 * Display admin notices.
	 *
	 * @return void
	 */
	public function admin_notices(): void {
		$screen = get_current_screen();
		if ( ! $screen || 'settings_page_gp-translate-with-openai' !== $screen->id ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gpoai_success'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$success = sanitize_text_field( wp_unslash( $_GET['gpoai_success'] ) );

			if ( 'glossary_imported' === $success ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$imported = isset( $_GET['gpoai_imported'] ) ? absint( $_GET['gpoai_imported'] ) : 0;
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						printf(
							/* translators: %d: number of entries imported */
							esc_html__( 'Glossary imported successfully. %d entries imported.', 'gp-translate-with-openai' ),
							$imported
						);
						?>
					</p>
				</div>
				<?php
			} elseif ( 'scheduled' === $success ) {
				?>
				<div class="notice notice-success is-dismissible">
					<p><?php esc_html_e( 'Translation jobs have been scheduled successfully.', 'gp-translate-with-openai' ); ?></p>
				</div>
				<?php
			}
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['gpoai_error'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$error = sanitize_text_field( wp_unslash( $_GET['gpoai_error'] ) );

			$messages = array(
				'missing_locale' => __( 'Please select a locale.', 'gp-translate-with-openai' ),
				'missing_params' => __( 'Please select both a project and locale.', 'gp-translate-with-openai' ),
				'import_failed'  => __( 'Glossary import failed. GlotPress glossary classes may not be available.', 'gp-translate-with-openai' ),
			);

			$message = $messages[ $error ] ?? __( 'An error occurred.', 'gp-translate-with-openai' );
			?>
			<div class="notice notice-error is-dismissible">
				<p><?php echo esc_html( $message ); ?></p>
			</div>
			<?php
		}
	}
}
