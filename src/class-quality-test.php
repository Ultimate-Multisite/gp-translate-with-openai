<?php
/**
 * Quality Test class file.
 *
 * @package Meloniq\GpOpenaiTranslate
 */

namespace Meloniq\GpOpenaiTranslate;

/**
 * Quality Test class for comparing AI translations with human translations.
 */
class Quality_Test {

	/**
	 * Transient prefix for caching PO data.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'gpoai_po_cache_';

	/**
	 * Cache expiry in seconds (24 hours).
	 *
	 * @var int
	 */
	const CACHE_EXPIRY = DAY_IN_SECONDS;

	/**
	 * Option name for saved test results.
	 *
	 * @var string
	 */
	const RESULTS_OPTION = 'gpoai_quality_test_results';

	/**
	 * Constructor.
	 *
	 * @return void
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 20 );
		add_action( 'wp_ajax_gpoai_fetch_test_strings', array( $this, 'ajax_fetch_test_strings' ) );
		add_action( 'wp_ajax_gpoai_translate_test_string', array( $this, 'ajax_translate_test_string' ) );
		add_action( 'wp_ajax_gpoai_save_test_results', array( $this, 'ajax_save_test_results' ) );
		add_action( 'wp_ajax_gpoai_delete_test_result', array( $this, 'ajax_delete_test_result' ) );
		add_action( 'wp_ajax_gpoai_load_test_result', array( $this, 'ajax_load_test_result' ) );
	}

	/**
	 * Add submenu page for quality testing.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		add_submenu_page(
			'options-general.php',
			__( 'GP OpenAI Quality Test', 'gp-translate-with-openai' ),
			__( 'GP OpenAI Quality Test', 'gp-translate-with-openai' ),
			'manage_options',
			'gp-openai-quality-test',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Render the quality test page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		$available_locales = Locales::get_supported_locales();
		$models            = Api::get_available_models();
		$default_model     = Config::get_model();
		$default_prompt    = Config::get_system_prompt();
		$saved_results     = get_option( self::RESULTS_OPTION, array() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Translation Quality Test', 'gp-translate-with-openai' ); ?></h1>
			<p><?php esc_html_e( 'Compare AI translations with human translations from WordPress.org. Select multiple models and locales to compare performance.', 'gp-translate-with-openai' ); ?></p>

			<!-- Saved Results -->
			<?php if ( ! empty( $saved_results ) ) : ?>
			<div id="gpoai-saved-results" style="margin-bottom: 30px;">
				<h2><?php esc_html_e( 'Saved Results', 'gp-translate-with-openai' ); ?></h2>
				<table class="widefat striped" id="gpoai-saved-results-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Model', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Locale', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Strings', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Avg Similarity', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Exact Matches', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Avg Speed', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Total Tokens', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'gp-translate-with-openai' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( array_reverse( $saved_results, true ) as $id => $result ) : ?>
						<tr data-result-id="<?php echo esc_attr( $id ); ?>">
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i', $result['timestamp'] ) ); ?></td>
							<td><code><?php echo esc_html( $result['model'] ); ?></code></td>
							<td><?php echo esc_html( $result['locale_name'] ?? $result['locale'] ); ?></td>
							<td><?php echo esc_html( $result['total_strings'] ); ?></td>
							<td>
								<span class="gpoai-similarity <?php echo esc_attr( $this->get_similarity_class( $result['avg_similarity'] ) ); ?>">
									<?php echo esc_html( number_format( $result['avg_similarity'], 1 ) ); ?>%
								</span>
							</td>
							<td><?php echo esc_html( $result['exact_matches'] ); ?> (<?php echo esc_html( round( $result['exact_matches'] / max( 1, $result['total_strings'] ) * 100 ) ); ?>%)</td>
							<td><?php echo esc_html( number_format( $result['avg_duration_ms'] ) ); ?>ms</td>
							<td><?php echo esc_html( number_format( $result['total_tokens'] ) ); ?></td>
							<td>
								<button type="button" class="button button-small gpoai-view-result" data-id="<?php echo esc_attr( $id ); ?>">
									<?php esc_html_e( 'View', 'gp-translate-with-openai' ); ?>
								</button>
								<button type="button" class="button button-small gpoai-delete-result" data-id="<?php echo esc_attr( $id ); ?>">
									<?php esc_html_e( 'Delete', 'gp-translate-with-openai' ); ?>
								</button>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php $this->render_saved_aggregate( $saved_results ); ?>
			<?php endif; ?>

			<h2><?php esc_html_e( 'New Test', 'gp-translate-with-openai' ); ?></h2>

			<form id="gpoai-quality-test-form">
				<?php wp_nonce_field( 'gpoai_quality_test', 'gpoai_quality_nonce' ); ?>

				<table class="form-table">
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Locales', 'gp-translate-with-openai' ); ?></label>
						</th>
						<td>
							<select name="locales[]" id="test_locales" multiple style="min-width: 300px; min-height: 120px;">
								<?php foreach ( $available_locales as $locale_slug => $locale_name ) : ?>
									<option value="<?php echo esc_attr( $locale_slug ); ?>" <?php selected( $locale_slug, 'de' ); ?>>
										<?php echo esc_html( $locale_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple locales.', 'gp-translate-with-openai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label><?php esc_html_e( 'Models', 'gp-translate-with-openai' ); ?></label>
						</th>
						<td>
							<select name="models[]" id="test_models" multiple style="min-width: 300px; min-height: 120px;">
								<?php foreach ( $models as $model_name ) : ?>
									<option value="<?php echo esc_attr( $model_name ); ?>" <?php selected( $model_name, $default_model ); ?>>
										<?php echo esc_html( $model_name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'Hold Ctrl/Cmd to select multiple models for comparison.', 'gp-translate-with-openai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="test_prompt"><?php esc_html_e( 'System Prompt', 'gp-translate-with-openai' ); ?></label>
						</th>
						<td>
							<textarea name="prompt" id="test_prompt" class="large-text" rows="4"><?php echo esc_textarea( $default_prompt ); ?></textarea>
							<p class="description">
								<?php esc_html_e( 'Placeholders:', 'gp-translate-with-openai' ); ?>
								<code>{SOURCE_LANGUAGE}</code>, <code>{TARGET_LANGUAGE}</code>, <code>{CONTEXT}</code>, <code>{GLOSSARY}</code>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="test_count"><?php esc_html_e( 'Number of Strings', 'gp-translate-with-openai' ); ?></label>
						</th>
						<td>
							<input type="number" name="count" id="test_count" value="20" min="1" max="500" class="small-text">
							<p class="description"><?php esc_html_e( 'Per locale. (1-500)', 'gp-translate-with-openai' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="test_use_glossary"><?php esc_html_e( 'Use Glossary', 'gp-translate-with-openai' ); ?></label>
						</th>
						<td>
							<input type="checkbox" name="use_glossary" id="test_use_glossary" value="1" <?php checked( Config::get_use_glossary() ); ?>>
						</td>
					</tr>
				</table>

				<p class="submit">
					<button type="submit" class="button button-primary" id="gpoai-start-test">
						<?php esc_html_e( 'Start Test', 'gp-translate-with-openai' ); ?>
					</button>
					<button type="button" class="button button-secondary" id="gpoai-stop-test" style="display: none;">
						<?php esc_html_e( 'Stop Test', 'gp-translate-with-openai' ); ?>
					</button>
					<span id="gpoai-test-status" style="margin-left: 15px;"></span>
				</p>
			</form>

			<div id="gpoai-test-progress" style="display: none; margin: 20px 0;">
				<div style="background: #f0f0f0; border-radius: 4px; height: 24px; width: 100%; max-width: 600px;">
					<div id="gpoai-progress-bar" style="background: #0073aa; height: 100%; border-radius: 4px; width: 0%; transition: width 0.3s;"></div>
				</div>
				<p id="gpoai-progress-text" style="margin-top: 5px;"></p>
			</div>

			<!-- Comparison summary table -->
			<div id="gpoai-comparison-summary" style="display: none; margin: 20px 0;">
				<h2><?php esc_html_e( 'Comparison Summary', 'gp-translate-with-openai' ); ?></h2>
				<table class="widefat striped" id="gpoai-comparison-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Model', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Locale', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Strings', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Avg Similarity', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Exact Matches', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'High (≥90%)', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Med (70-89%)', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Low (<70%)', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Avg Speed', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Total Tokens', 'gp-translate-with-openai' ); ?></th>
							<th><?php esc_html_e( 'Status', 'gp-translate-with-openai' ); ?></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>

			<!-- Aggregate Analysis -->
			<div id="gpoai-aggregate-analysis" style="display: none; margin: 20px 0;">
				<h2><?php esc_html_e( 'Aggregate Analysis', 'gp-translate-with-openai' ); ?></h2>

				<div style="display: flex; gap: 20px; flex-wrap: wrap;">
					<!-- Model Rankings -->
					<div style="flex: 1; min-width: 400px;">
						<h3><?php esc_html_e( 'Model Rankings (Overall)', 'gp-translate-with-openai' ); ?></h3>
						<table class="widefat striped" id="gpoai-model-rankings">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Rank', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Model', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Avg Similarity', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Exact Match %', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Avg Speed', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Tokens/String', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Locales Tested', 'gp-translate-with-openai' ); ?></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>

					<!-- Best Model Per Locale -->
					<div style="flex: 1; min-width: 400px;">
						<h3><?php esc_html_e( 'Best Model Per Locale', 'gp-translate-with-openai' ); ?></h3>
						<table class="widefat striped" id="gpoai-best-per-locale">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Locale', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Best Model (Quality)', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Similarity', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Fastest Model', 'gp-translate-with-openai' ); ?></th>
									<th><?php esc_html_e( 'Speed', 'gp-translate-with-openai' ); ?></th>
								</tr>
							</thead>
							<tbody></tbody>
						</table>
					</div>
				</div>
			</div>

			<!-- Detail results per run -->
			<div id="gpoai-test-results" style="margin-top: 20px;"></div>

			<!-- Modal for viewing saved results -->
			<div id="gpoai-result-modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.5); z-index:100000;">
				<div style="background:#fff; margin:30px auto; max-width:1200px; max-height:calc(100vh - 60px); overflow-y:auto; padding:20px; border-radius:4px;">
					<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
						<h2 id="gpoai-modal-title" style="margin:0;"></h2>
						<button type="button" class="button" id="gpoai-close-modal"><?php esc_html_e( 'Close', 'gp-translate-with-openai' ); ?></button>
					</div>
					<div id="gpoai-modal-content"></div>
				</div>
			</div>
		</div>

		<?php $this->render_styles(); ?>
		<?php $this->render_scripts( $available_locales ); ?>
		<?php
	}

	/**
	 * Render CSS styles.
	 *
	 * @return void
	 */
	protected function render_styles(): void {
		?>
		<style>
			.gpoai-result-item { border: 1px solid #ddd; margin-bottom: 10px; background: #fff; }
			.gpoai-result-header { background: #f5f5f5; padding: 8px 15px; border-bottom: 1px solid #ddd; display: flex; justify-content: space-between; align-items: center; }
			.gpoai-result-body { padding: 12px 15px; }
			.gpoai-result-row { margin-bottom: 6px; display: flex; gap: 10px; }
			.gpoai-result-label { min-width: 100px; font-weight: bold; color: #666; font-size: 12px; }
			.gpoai-result-value { flex: 1; word-break: break-word; }
			.gpoai-match { border-left: 4px solid #46b450; }
			.gpoai-mismatch { border-left: 4px solid #dc3232; }
			.gpoai-diff-add { background: #d4edda; }
			.gpoai-diff-del { background: #f8d7da; text-decoration: line-through; }
			.gpoai-similarity { display: inline-block; padding: 2px 8px; border-radius: 3px; font-size: 12px; font-weight: bold; }
			.gpoai-similarity-high { background: #d4edda; color: #155724; }
			.gpoai-similarity-medium { background: #fff3cd; color: #856404; }
			.gpoai-similarity-low { background: #f8d7da; color: #721c24; }
			.gpoai-run-section { margin: 25px 0; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; }
			.gpoai-run-section h3 { margin-top: 0; }
			.gpoai-meta { display: inline-block; margin-right: 15px; font-size: 12px; color: #666; }
			#gpoai-comparison-table td, #gpoai-comparison-table th { text-align: center; }
			#gpoai-comparison-table td:first-child, #gpoai-comparison-table th:first-child { text-align: left; }
			#gpoai-model-rankings td, #gpoai-model-rankings th,
			#gpoai-best-per-locale td, #gpoai-best-per-locale th,
			#gpoai-saved-model-rankings td, #gpoai-saved-model-rankings th,
			#gpoai-saved-best-per-locale td, #gpoai-saved-best-per-locale th { text-align: center; }
			#gpoai-model-rankings td:nth-child(2), #gpoai-model-rankings th:nth-child(2),
			#gpoai-best-per-locale td:first-child, #gpoai-best-per-locale th:first-child,
			#gpoai-saved-model-rankings td:nth-child(2), #gpoai-saved-model-rankings th:nth-child(2),
			#gpoai-saved-best-per-locale td:first-child, #gpoai-saved-best-per-locale th:first-child { text-align: left; }
		</style>
		<?php
	}

	/**
	 * Render aggregate analysis from saved results.
	 *
	 * @param array $saved_results All saved test results.
	 *
	 * @return void
	 */
	protected function render_saved_aggregate( array $saved_results ): void {
		if ( count( $saved_results ) < 2 ) {
			return;
		}

		// Aggregate by model.
		$model_data = array();
		foreach ( $saved_results as $result ) {
			$model = $result['model'];
			if ( ! isset( $model_data[ $model ] ) ) {
				$model_data[ $model ] = array(
					'total_sim'     => 0,
					'total_exact'   => 0,
					'total_strings' => 0,
					'total_duration' => 0,
					'total_tokens'  => 0,
					'locales'       => array(),
				);
			}
			$m = &$model_data[ $model ];
			$m['total_sim']     += $result['avg_similarity'] * $result['total_strings'];
			$m['total_exact']   += $result['exact_matches'];
			$m['total_strings'] += $result['total_strings'];
			$m['total_duration'] += $result['avg_duration_ms'] * $result['total_strings'];
			$m['total_tokens']  += $result['total_tokens'];
			$locale_label        = $result['locale_name'] ?? $result['locale'];
			if ( ! in_array( $locale_label, $m['locales'], true ) ) {
				$m['locales'][] = $locale_label;
			}
		}

		// Sort by avg similarity descending.
		uasort( $model_data, function( $a, $b ) {
			$avg_a = $a['total_strings'] > 0 ? $a['total_sim'] / $a['total_strings'] : 0;
			$avg_b = $b['total_strings'] > 0 ? $b['total_sim'] / $b['total_strings'] : 0;
			return $avg_b <=> $avg_a;
		} );

		// Aggregate by locale.
		$locale_data = array();
		foreach ( $saved_results as $result ) {
			$locale = $result['locale'];
			if ( ! isset( $locale_data[ $locale ] ) ) {
				$locale_data[ $locale ] = array(
					'name'   => $result['locale_name'] ?? $result['locale'],
					'models' => array(),
				);
			}
			$avg_speed = $result['total_strings'] > 0 ? $result['avg_duration_ms'] : 0;
			$locale_data[ $locale ]['models'][] = array(
				'model'    => $result['model'],
				'avg_sim'  => (float) $result['avg_similarity'],
				'avg_speed' => $avg_speed,
			);
		}

		?>
		<div id="gpoai-saved-aggregate" style="margin-bottom: 30px;">
			<h2><?php esc_html_e( 'Aggregate Analysis (All Saved Results)', 'gp-translate-with-openai' ); ?></h2>

			<div style="display: flex; gap: 20px; flex-wrap: wrap;">
				<div style="flex: 1; min-width: 400px;">
					<h3><?php esc_html_e( 'Model Rankings (Overall)', 'gp-translate-with-openai' ); ?></h3>
					<table class="widefat striped" id="gpoai-saved-model-rankings">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Rank', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Model', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Avg Similarity', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Exact Match %', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Avg Speed', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Tokens/String', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Locales Tested', 'gp-translate-with-openai' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						$rank = 0;
						foreach ( $model_data as $model => $m ) :
							++$rank;
							$avg_sim          = $m['total_strings'] > 0 ? $m['total_sim'] / $m['total_strings'] : 0;
							$exact_pct        = $m['total_strings'] > 0 ? round( $m['total_exact'] / $m['total_strings'] * 100 ) : 0;
							$avg_speed        = $m['total_strings'] > 0 ? round( $m['total_duration'] / $m['total_strings'] ) : 0;
							$tokens_per_str   = $m['total_strings'] > 0 ? round( $m['total_tokens'] / $m['total_strings'] ) : 0;
							$sim_class        = $this->get_similarity_class( $avg_sim );
							$row_style        = 1 === $rank ? ' style="font-weight:bold; background:#d4edda;"' : '';
							?>
							<tr<?php echo $row_style; ?>>
								<td><?php echo esc_html( $rank ); ?></td>
								<td><code><?php echo esc_html( $model ); ?></code></td>
								<td><span class="gpoai-similarity <?php echo esc_attr( $sim_class ); ?>"><?php echo esc_html( number_format( $avg_sim, 1 ) ); ?>%</span></td>
								<td><?php echo esc_html( $exact_pct ); ?>%</td>
								<td><?php echo esc_html( number_format( $avg_speed ) ); ?>ms</td>
								<td><?php echo esc_html( number_format( $tokens_per_str ) ); ?></td>
								<td><?php echo esc_html( count( $m['locales'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>

				<div style="flex: 1; min-width: 400px;">
					<h3><?php esc_html_e( 'Best Model Per Locale', 'gp-translate-with-openai' ); ?></h3>
					<table class="widefat striped" id="gpoai-saved-best-per-locale">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Locale', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Best Model (Quality)', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Similarity', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Fastest Model', 'gp-translate-with-openai' ); ?></th>
								<th><?php esc_html_e( 'Speed', 'gp-translate-with-openai' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php
						ksort( $locale_data );
						foreach ( $locale_data as $locale => $ld ) :
							if ( count( $ld['models'] ) < 1 ) {
								continue;
							}
							$by_quality = $ld['models'];
							usort( $by_quality, function( $a, $b ) {
								return $b['avg_sim'] <=> $a['avg_sim'];
							} );
							$by_speed = $ld['models'];
							usort( $by_speed, function( $a, $b ) {
								return $a['avg_speed'] <=> $b['avg_speed'];
							} );
							$best    = $by_quality[0];
							$fastest = $by_speed[0];
							$sim_class = $this->get_similarity_class( $best['avg_sim'] );
							?>
							<tr>
								<td><?php echo esc_html( $ld['name'] ); ?></td>
								<td><code><?php echo esc_html( $best['model'] ); ?></code></td>
								<td><span class="gpoai-similarity <?php echo esc_attr( $sim_class ); ?>"><?php echo esc_html( number_format( $best['avg_sim'], 1 ) ); ?>%</span></td>
								<td><code><?php echo esc_html( $fastest['model'] ); ?></code></td>
								<td><?php echo esc_html( number_format( $fastest['avg_speed'] ) ); ?>ms</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render JavaScript.
	 *
	 * @param array $available_locales Available locales.
	 *
	 * @return void
	 */
	protected function render_scripts( array $available_locales ): void {
		?>
		<script>
		jQuery(document).ready(function($) {
			var isRunning = false;
			var shouldStop = false;
			var allRuns = []; // Array of {model, locale, locale_name, strings[], results[], currentIndex}
			var currentRunIndex = 0;
			var totalTranslations = 0;
			var completedTranslations = 0;
			var maxConcurrent = <?php echo (int) Config::get_max_concurrent_requests(); ?>;

			var localeNames = <?php echo wp_json_encode( $available_locales ); ?>;

			$('#gpoai-quality-test-form').on('submit', function(e) {
				e.preventDefault();
				startTest();
			});

			$('#gpoai-stop-test').on('click', function() {
				shouldStop = true;
				$(this).prop('disabled', true).text('<?php echo esc_js( __( 'Stopping...', 'gp-translate-with-openai' ) ); ?>');
			});

			// View saved result
			$(document).on('click', '.gpoai-view-result', function() {
				var id = $(this).data('id');
				loadSavedResult(id);
			});

			// Delete saved result
			$(document).on('click', '.gpoai-delete-result', function() {
				var id = $(this).data('id');
				if (!confirm('<?php echo esc_js( __( 'Delete this result?', 'gp-translate-with-openai' ) ); ?>')) return;
				$.post(ajaxurl, {
					action: 'gpoai_delete_test_result',
					nonce: $('#gpoai_quality_nonce').val(),
					result_id: id
				}, function(response) {
					if (response.success) {
						$('tr[data-result-id="' + id + '"]').fadeOut(300, function() { $(this).remove(); });
					}
				});
			});

			// Close modal
			$('#gpoai-close-modal').on('click', function() { $('#gpoai-result-modal').hide(); });
			$('#gpoai-result-modal').on('click', function(e) {
				if (e.target === this) $(this).hide();
			});

			function startTest() {
				if (isRunning) return;

				var selectedLocales = $('#test_locales').val();
				var selectedModels = $('#test_models').val();

				if (!selectedLocales || selectedLocales.length === 0) {
					alert('<?php echo esc_js( __( 'Please select at least one locale.', 'gp-translate-with-openai' ) ); ?>');
					return;
				}
				if (!selectedModels || selectedModels.length === 0) {
					alert('<?php echo esc_js( __( 'Please select at least one model.', 'gp-translate-with-openai' ) ); ?>');
					return;
				}

				isRunning = true;
				shouldStop = false;
				allRuns = [];
				currentRunIndex = 0;
				completedTranslations = 0;

				// Build run matrix: each model x each locale
				var count = parseInt($('#test_count').val()) || 20;
				selectedModels.forEach(function(model) {
					selectedLocales.forEach(function(locale) {
						allRuns.push({
							model: model,
							locale: locale,
							locale_name: localeNames[locale] || locale,
							strings: [],
							results: [],
							currentIndex: 0,
							totalDuration: 0,
							totalTokens: 0,
							count: count
						});
					});
				});

				totalTranslations = allRuns.length * count;

				$('#gpoai-start-test').prop('disabled', true);
				$('#gpoai-stop-test').show().prop('disabled', false).text('<?php echo esc_js( __( 'Stop Test', 'gp-translate-with-openai' ) ); ?>');
				$('#gpoai-test-progress').show();
				$('#gpoai-test-results').empty();
				$('#gpoai-progress-bar').css('width', '0%');
				$('#gpoai-comparison-summary').show();
				$('#gpoai-comparison-table tbody').empty();

				// Add rows to comparison table
				allRuns.forEach(function(run, idx) {
					$('#gpoai-comparison-table tbody').append(
						'<tr id="gpoai-run-row-' + idx + '">' +
						'<td><code>' + escapeHtml(run.model) + '</code></td>' +
						'<td>' + escapeHtml(run.locale_name) + '</td>' +
						'<td>' + run.count + '</td>' +
						'<td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td><td>—</td>' +
						'<td><em><?php echo esc_js( __( 'Pending', 'gp-translate-with-openai' ) ); ?></em></td>' +
						'</tr>'
					);
				});

				// Start first run
				fetchStringsForRun(currentRunIndex);
			}

			function fetchStringsForRun(runIdx) {
				if (shouldStop || runIdx >= allRuns.length) {
					finishAllTests();
					return;
				}

				var run = allRuns[runIdx];
				currentRunIndex = runIdx;

				$('#gpoai-test-status').text(
					'<?php echo esc_js( __( 'Fetching strings for', 'gp-translate-with-openai' ) ); ?> ' +
					run.locale_name + '...'
				);
				updateRunStatus(runIdx, '<?php echo esc_js( __( 'Fetching...', 'gp-translate-with-openai' ) ); ?>');

				// Add result section
				$('#gpoai-test-results').append(
					'<div class="gpoai-run-section" id="gpoai-run-' + runIdx + '">' +
					'<h3>' + escapeHtml(run.model) + ' — ' + escapeHtml(run.locale_name) + '</h3>' +
					'<div class="gpoai-run-items"></div>' +
					'</div>'
				);

				$.ajax({
					url: ajaxurl,
					type: 'POST',
					data: {
						action: 'gpoai_fetch_test_strings',
						nonce: $('#gpoai_quality_nonce').val(),
						locale: run.locale,
						count: run.count
					},
					success: function(response) {
						if (response.success && response.data.strings) {
							run.strings = response.data.strings;
							updateRunStatus(runIdx, '<?php echo esc_js( __( 'Translating...', 'gp-translate-with-openai' ) ); ?>');
							processNextStringInRun(runIdx);
						} else {
							updateRunStatus(runIdx, '<?php echo esc_js( __( 'Failed to fetch', 'gp-translate-with-openai' ) ); ?>');
							startNextRun(runIdx);
						}
					},
					error: function() {
						updateRunStatus(runIdx, '<?php echo esc_js( __( 'Network error', 'gp-translate-with-openai' ) ); ?>');
						startNextRun(runIdx);
					}
				});
			}

			function processNextStringInRun(runIdx) {
				if (shouldStop) { finishAllTests(); return; }

				var run = allRuns[runIdx];
				var activeCount = 0;
				var nextIndex = 0;
				var displayIndex = 0;

				function launchNext() {
					while (activeCount < maxConcurrent && nextIndex < run.strings.length && !shouldStop) {
						(function(idx) {
							var testString = run.strings[idx];
							activeCount++;
							completedTranslations++;
							var progress = Math.round((completedTranslations / totalTranslations) * 100);
							$('#gpoai-progress-bar').css('width', progress + '%');
							$('#gpoai-progress-text').text(completedTranslations + ' / ' + totalTranslations +
								' (' + escapeHtml(run.model) + ' — ' + escapeHtml(run.locale_name) + ')');

							$.ajax({
								url: ajaxurl,
								type: 'POST',
								data: {
									action: 'gpoai_translate_test_string',
									nonce: $('#gpoai_quality_nonce').val(),
									source: testString.source,
									human: testString.translation,
									locale: run.locale,
									model: run.model,
									prompt: $('#test_prompt').val(),
									use_glossary: $('#test_use_glossary').is(':checked') ? 1 : 0
								},
								success: function(response) {
									if (response.success) {
										displayIndex++;
										var result = {
											source: testString.source,
											human: testString.translation,
											ai: response.data.translation,
											similarity: response.data.similarity,
											duration_ms: response.data.duration_ms || 0,
											prompt_tokens: response.data.prompt_tokens || 0,
											completion_tokens: response.data.completion_tokens || 0,
											total_tokens: response.data.total_tokens || 0
										};
										run.results.push(result);
										run.totalDuration += result.duration_ms;
										run.totalTokens += result.total_tokens;
										displayRunResult(runIdx, result, displayIndex);
										updateRunSummaryRow(runIdx);
									}
									onComplete();
								},
								error: function() {
									onComplete();
								}
							});
						})(nextIndex);
						nextIndex++;
					}
				}

				function onComplete() {
					activeCount--;
					if (shouldStop) {
						if (activeCount === 0) finishAllTests();
						return;
					}
					if (nextIndex >= run.strings.length && activeCount === 0) {
						finishRun(runIdx);
						startNextRun(runIdx);
					} else {
						launchNext();
					}
				}

				launchNext();
			}

			function displayRunResult(runIdx, result, index) {
				var isMatch = result.human.trim() === result.ai.trim();
				var matchClass = isMatch ? 'gpoai-match' : 'gpoai-mismatch';
				var simClass = getSimilarityClass(result.similarity);
				var diff = isMatch ? result.ai : highlightDifferences(result.human, result.ai);

				var meta = '<span class="gpoai-meta">' + result.duration_ms + 'ms</span>';
				if (result.total_tokens > 0) {
					meta += '<span class="gpoai-meta">' + result.total_tokens + ' tokens</span>';
				}

				var html = '<div class="gpoai-result-item ' + matchClass + '">' +
					'<div class="gpoai-result-header">' +
						'<span>#' + index + ' <span class="gpoai-similarity ' + simClass + '">' + result.similarity + '%</span></span>' +
						'<span>' + meta + '</span>' +
					'</div>' +
					'<div class="gpoai-result-body">' +
						'<div class="gpoai-result-row"><div class="gpoai-result-label">Source:</div><div class="gpoai-result-value"><code>' + escapeHtml(result.source) + '</code></div></div>' +
						'<div class="gpoai-result-row"><div class="gpoai-result-label">AI:</div><div class="gpoai-result-value">' + escapeHtml(result.ai) + '</div></div>' +
						'<div class="gpoai-result-row"><div class="gpoai-result-label">Human:</div><div class="gpoai-result-value">' + escapeHtml(result.human) + '</div></div>' +
						(isMatch ? '' : '<div class="gpoai-result-row"><div class="gpoai-result-label">Diff:</div><div class="gpoai-result-value">' + diff + '</div></div>') +
					'</div></div>';

				$('#gpoai-run-' + runIdx + ' .gpoai-run-items').append(html);
			}

			function updateRunSummaryRow(runIdx) {
				var run = allRuns[runIdx];
				if (run.results.length === 0) return;
				var stats = calculateStats(run.results);
				var avgDuration = Math.round(run.totalDuration / run.results.length);
				var $row = $('#gpoai-run-row-' + runIdx);
				$row.find('td:eq(3)').html('<span class="gpoai-similarity ' + getSimilarityClass(stats.avg) + '">' + stats.avg.toFixed(1) + '%</span>');
				$row.find('td:eq(4)').text(stats.exact + ' (' + Math.round(stats.exact / run.results.length * 100) + '%)');
				$row.find('td:eq(5)').text(stats.high);
				$row.find('td:eq(6)').text(stats.med);
				$row.find('td:eq(7)').text(stats.low);
				$row.find('td:eq(8)').text(avgDuration + 'ms');
				$row.find('td:eq(9)').text(run.totalTokens.toLocaleString());
			}

			function finishRun(runIdx) {
				var run = allRuns[runIdx];
				if (run.results.length === 0) {
					updateRunStatus(runIdx, '<?php echo esc_js( __( 'No results', 'gp-translate-with-openai' ) ); ?>');
					return;
				}
				var stats = calculateStats(run.results);
				updateRunStatus(runIdx, '✓ ' + stats.avg.toFixed(1) + '%');

				// Save result
				var avgDuration = Math.round(run.totalDuration / run.results.length);
				$.post(ajaxurl, {
					action: 'gpoai_save_test_results',
					nonce: $('#gpoai_quality_nonce').val(),
					model: run.model,
					locale: run.locale,
					locale_name: run.locale_name,
					prompt: $('#test_prompt').val(),
					total_strings: run.results.length,
					avg_similarity: stats.avg.toFixed(1),
					exact_matches: stats.exact,
					high_similarity: stats.high,
					med_similarity: stats.med,
					low_similarity: stats.low,
					avg_duration_ms: avgDuration,
					total_tokens: run.totalTokens,
					results: JSON.stringify(run.results)
				});
			}

			function startNextRun(runIdx) {
				fetchStringsForRun(runIdx + 1);
			}

			function finishAllTests() {
				isRunning = false;
				$('#gpoai-start-test').prop('disabled', false);
				$('#gpoai-stop-test').hide();
				$('#gpoai-progress-bar').css('width', '100%');
				$('#gpoai-test-status').text('<?php echo esc_js( __( 'Complete! Results saved.', 'gp-translate-with-openai' ) ); ?>');
				buildAggregateAnalysis();
			}

			function buildAggregateAnalysis() {
				var runsWithResults = allRuns.filter(function(r) { return r.results.length > 0; });
				if (runsWithResults.length < 2) return;

				$('#gpoai-aggregate-analysis').show();

				// Aggregate by model
				var modelData = {};
				runsWithResults.forEach(function(run) {
					if (!modelData[run.model]) {
						modelData[run.model] = { totalSim: 0, totalExact: 0, totalStrings: 0, totalDuration: 0, totalTokens: 0, locales: [] };
					}
					var m = modelData[run.model];
					var stats = calculateStats(run.results);
					m.totalSim += stats.avg * run.results.length;
					m.totalExact += stats.exact;
					m.totalStrings += run.results.length;
					m.totalDuration += run.totalDuration;
					m.totalTokens += run.totalTokens;
					m.locales.push(run.locale_name);
				});

				var modelRanking = Object.keys(modelData).map(function(model) {
					var m = modelData[model];
					return {
						model: model,
						avgSim: m.totalSim / m.totalStrings,
						exactPct: Math.round(m.totalExact / m.totalStrings * 100),
						avgSpeed: Math.round(m.totalDuration / m.totalStrings),
						tokensPerString: Math.round(m.totalTokens / m.totalStrings),
						localeCount: m.locales.length
					};
				}).sort(function(a, b) { return b.avgSim - a.avgSim; });

				var $modelBody = $('#gpoai-model-rankings tbody').empty();
				modelRanking.forEach(function(m, i) {
					var simClass = getSimilarityClass(m.avgSim);
					$modelBody.append(
						'<tr' + (i === 0 ? ' style="font-weight:bold; background:#d4edda;"' : '') + '>' +
						'<td>' + (i + 1) + '</td>' +
						'<td><code>' + escapeHtml(m.model) + '</code></td>' +
						'<td><span class="gpoai-similarity ' + simClass + '">' + m.avgSim.toFixed(1) + '%</span></td>' +
						'<td>' + m.exactPct + '%</td>' +
						'<td>' + m.avgSpeed + 'ms</td>' +
						'<td>' + m.tokensPerString + '</td>' +
						'<td>' + m.localeCount + '</td>' +
						'</tr>'
					);
				});

				// Best model per locale
				var localeData = {};
				runsWithResults.forEach(function(run) {
					if (!localeData[run.locale]) {
						localeData[run.locale] = { name: run.locale_name, models: [] };
					}
					var stats = calculateStats(run.results);
					var avgSpeed = Math.round(run.totalDuration / run.results.length);
					localeData[run.locale].models.push({
						model: run.model,
						avgSim: stats.avg,
						avgSpeed: avgSpeed
					});
				});

				var $localeBody = $('#gpoai-best-per-locale tbody').empty();
				Object.keys(localeData).sort().forEach(function(locale) {
					var ld = localeData[locale];
					if (ld.models.length < 1) return;
					var bestQuality = ld.models.slice().sort(function(a, b) { return b.avgSim - a.avgSim; })[0];
					var fastest = ld.models.slice().sort(function(a, b) { return a.avgSpeed - b.avgSpeed; })[0];
					var simClass = getSimilarityClass(bestQuality.avgSim);
					$localeBody.append(
						'<tr>' +
						'<td>' + escapeHtml(ld.name) + '</td>' +
						'<td><code>' + escapeHtml(bestQuality.model) + '</code></td>' +
						'<td><span class="gpoai-similarity ' + simClass + '">' + bestQuality.avgSim.toFixed(1) + '%</span></td>' +
						'<td><code>' + escapeHtml(fastest.model) + '</code></td>' +
						'<td>' + fastest.avgSpeed + 'ms</td>' +
						'</tr>'
					);
				});
			}

			function updateRunStatus(runIdx, status) {
				$('#gpoai-run-row-' + runIdx + ' td:last').html(status);
			}

			function calculateStats(results) {
				var total = results.length;
				var sum = 0;
				var exact = 0, high = 0, med = 0, low = 0;
				results.forEach(function(r) {
					sum += r.similarity;
					if (r.human.trim() === r.ai.trim()) exact++;
					if (r.similarity >= 90) high++;
					else if (r.similarity >= 70) med++;
					else low++;
				});
				return { avg: sum / total, exact: exact, high: high, med: med, low: low };
			}

			function getSimilarityClass(sim) {
				if (sim >= 90) return 'gpoai-similarity-high';
				if (sim >= 70) return 'gpoai-similarity-medium';
				return 'gpoai-similarity-low';
			}

			function highlightDifferences(human, ai) {
				var hWords = human.split(/\s+/);
				var aWords = ai.split(/\s+/);
				var out = [];
				var maxLen = Math.max(hWords.length, aWords.length);
				for (var i = 0; i < maxLen; i++) {
					var h = hWords[i] || '', a = aWords[i] || '';
					if (h === a) out.push(escapeHtml(a));
					else {
						if (a) out.push('<span class="gpoai-diff-add">' + escapeHtml(a) + '</span>');
						if (h && h !== a) out.push('<span class="gpoai-diff-del">' + escapeHtml(h) + '</span>');
					}
				}
				return out.join(' ');
			}

			function loadSavedResult(id) {
				$.post(ajaxurl, {
					action: 'gpoai_load_test_result',
					nonce: $('#gpoai_quality_nonce').val(),
					result_id: id
				}, function(response) {
					if (!response.success) return;
					var data = response.data;
					$('#gpoai-modal-title').text(data.model + ' — ' + (data.locale_name || data.locale));
					var html = '<p><strong>Date:</strong> ' + data.date +
						' | <strong>Avg Similarity:</strong> ' + data.avg_similarity + '%' +
						' | <strong>Exact:</strong> ' + data.exact_matches + '/' + data.total_strings +
						' | <strong>Avg Speed:</strong> ' + data.avg_duration_ms + 'ms' +
						' | <strong>Tokens:</strong> ' + Number(data.total_tokens).toLocaleString() + '</p>';
					if (data.results) {
						data.results.forEach(function(r, i) {
							var isMatch = r.human && r.human.trim() === r.ai.trim();
							var mc = isMatch ? 'gpoai-match' : 'gpoai-mismatch';
							var sc = getSimilarityClass(r.similarity);
							var meta = (r.duration_ms ? r.duration_ms + 'ms' : '') + (r.total_tokens ? ' | ' + r.total_tokens + ' tok' : '');
							html += '<div class="gpoai-result-item ' + mc + '"><div class="gpoai-result-header"><span>#' + (i+1) + ' <span class="gpoai-similarity ' + sc + '">' + r.similarity + '%</span></span><span class="gpoai-meta">' + meta + '</span></div><div class="gpoai-result-body">' +
								'<div class="gpoai-result-row"><div class="gpoai-result-label">Source:</div><div class="gpoai-result-value"><code>' + escapeHtml(r.source) + '</code></div></div>' +
								'<div class="gpoai-result-row"><div class="gpoai-result-label">AI:</div><div class="gpoai-result-value">' + escapeHtml(r.ai) + '</div></div>' +
								'<div class="gpoai-result-row"><div class="gpoai-result-label">Human:</div><div class="gpoai-result-value">' + escapeHtml(r.human || '') + '</div></div>' +
								'</div></div>';
						});
					}
					$('#gpoai-modal-content').html(html);
					$('#gpoai-result-modal').show();
				});
			}

			function escapeHtml(text) {
				if (!text) return '';
				var div = document.createElement('div');
				div.textContent = text;
				return div.innerHTML;
			}
		});
		</script>
		<?php
	}

	/**
	 * Get CSS class for similarity value.
	 *
	 * @param float $similarity The similarity percentage.
	 *
	 * @return string CSS class.
	 */
	protected function get_similarity_class( float $similarity ): string {
		if ( $similarity >= 90 ) {
			return 'gpoai-similarity-high';
		}
		if ( $similarity >= 70 ) {
			return 'gpoai-similarity-medium';
		}
		return 'gpoai-similarity-low';
	}

	/**
	 * AJAX handler to fetch test strings from WordPress.org.
	 *
	 * @return void
	 */
	public function ajax_fetch_test_strings(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gpoai_quality_test' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'gp-translate-with-openai' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-translate-with-openai' ) ) );
		}

		$locale = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';
		$count  = isset( $_POST['count'] ) ? absint( $_POST['count'] ) : 20;

		if ( empty( $locale ) ) {
			wp_send_json_error( array( 'message' => __( 'Locale is required.', 'gp-translate-with-openai' ) ) );
		}

		$count   = max( 1, min( 500, $count ) );
		$strings = $this->fetch_wordpress_translations( $locale, $count );

		if ( empty( $strings ) ) {
			wp_send_json_error( array( 'message' => __( 'Could not fetch translations from WordPress.org.', 'gp-translate-with-openai' ) ) );
		}

		wp_send_json_success( array( 'strings' => $strings ) );
	}

	/**
	 * AJAX handler to translate a single test string.
	 *
	 * Returns translation with timing and token usage.
	 *
	 * @return void
	 */
	public function ajax_translate_test_string(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gpoai_quality_test' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'gp-translate-with-openai' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-translate-with-openai' ) ) );
		}

		$source       = isset( $_POST['source'] ) ? wp_unslash( $_POST['source'] ) : '';
		$locale       = isset( $_POST['locale'] ) ? sanitize_text_field( wp_unslash( $_POST['locale'] ) ) : '';
		$model        = isset( $_POST['model'] ) ? sanitize_text_field( wp_unslash( $_POST['model'] ) ) : '';
		$prompt       = isset( $_POST['prompt'] ) ? wp_unslash( $_POST['prompt'] ) : '';
		$use_glossary = isset( $_POST['use_glossary'] ) && '1' === $_POST['use_glossary'];
		$human        = isset( $_POST['human'] ) ? wp_unslash( $_POST['human'] ) : '';

		if ( empty( $source ) || empty( $locale ) ) {
			wp_send_json_error( array( 'message' => __( 'Source and locale are required.', 'gp-translate-with-openai' ) ) );
		}

		// Temporarily override config.
		$original_model    = get_option( 'gpoai_model' );
		$original_prompt   = get_option( 'gpoai_custom_prompt' );
		$original_glossary = get_option( 'gpoai_use_glossary' );

		if ( ! empty( $model ) ) {
			update_option( 'gpoai_model', $model );
		}
		if ( $prompt !== $original_prompt ) {
			update_option( 'gpoai_custom_prompt', $prompt );
		}
		update_option( 'gpoai_use_glossary', $use_glossary );

		// Capture token usage via debug callback.
		$usage_data = array(
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
			'total_tokens'      => 0,
		);

		Translate::set_debug( true, function( string $label, $data ) use ( &$usage_data ) {
			if ( 'RESULT' === $label && is_array( $data ) && isset( $data['usage'] ) ) {
				$usage = (array) $data['usage'];
				$usage_data['prompt_tokens']     = $usage['prompt_tokens'] ?? 0;
				$usage_data['completion_tokens'] = $usage['completion_tokens'] ?? 0;
				$usage_data['total_tokens']      = $usage['total_tokens'] ?? 0;
			}
		} );

		// Perform translation with timing.
		$start_time  = microtime( true );
		$translate   = Translate::instance();
		$translation = $translate->translate( $source, $locale );
		$duration_ms = round( ( microtime( true ) - $start_time ) * 1000 );

		// Restore.
		Translate::set_debug( false );
		update_option( 'gpoai_model', $original_model );
		update_option( 'gpoai_custom_prompt', $original_prompt );
		update_option( 'gpoai_use_glossary', $original_glossary );

		$similarity = 0;
		if ( ! empty( $human ) ) {
			$similarity = $this->calculate_similarity( $human, $translation );
		}

		wp_send_json_success(
			array(
				'translation'       => $translation,
				'similarity'        => $similarity,
				'duration_ms'       => (int) $duration_ms,
				'prompt_tokens'     => $usage_data['prompt_tokens'],
				'completion_tokens' => $usage_data['completion_tokens'],
				'total_tokens'      => $usage_data['total_tokens'],
			)
		);
	}

	/**
	 * AJAX handler to save test results.
	 *
	 * @return void
	 */
	public function ajax_save_test_results(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gpoai_quality_test' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'gp-translate-with-openai' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-translate-with-openai' ) ) );
		}

		$result = array(
			'timestamp'       => time(),
			'model'           => sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ),
			'locale'          => sanitize_text_field( wp_unslash( $_POST['locale'] ?? '' ) ),
			'locale_name'     => sanitize_text_field( wp_unslash( $_POST['locale_name'] ?? '' ) ),
			'prompt'          => wp_unslash( $_POST['prompt'] ?? '' ),
			'total_strings'   => absint( $_POST['total_strings'] ?? 0 ),
			'avg_similarity'  => (float) ( $_POST['avg_similarity'] ?? 0 ),
			'exact_matches'   => absint( $_POST['exact_matches'] ?? 0 ),
			'high_similarity' => absint( $_POST['high_similarity'] ?? 0 ),
			'med_similarity'  => absint( $_POST['med_similarity'] ?? 0 ),
			'low_similarity'  => absint( $_POST['low_similarity'] ?? 0 ),
			'avg_duration_ms' => absint( $_POST['avg_duration_ms'] ?? 0 ),
			'total_tokens'    => absint( $_POST['total_tokens'] ?? 0 ),
			'results'         => json_decode( wp_unslash( $_POST['results'] ?? '[]' ), true ),
		);

		$saved = get_option( self::RESULTS_OPTION, array() );
		$id    = uniqid( 'qt_' );

		$saved[ $id ] = $result;

		// Keep only last 50 results.
		if ( count( $saved ) > 50 ) {
			$saved = array_slice( $saved, -50, 50, true );
		}

		update_option( self::RESULTS_OPTION, $saved, false );

		wp_send_json_success( array( 'id' => $id ) );
	}

	/**
	 * AJAX handler to delete a saved test result.
	 *
	 * @return void
	 */
	public function ajax_delete_test_result(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gpoai_quality_test' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'gp-translate-with-openai' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-translate-with-openai' ) ) );
		}

		$id    = sanitize_text_field( wp_unslash( $_POST['result_id'] ?? '' ) );
		$saved = get_option( self::RESULTS_OPTION, array() );

		if ( isset( $saved[ $id ] ) ) {
			unset( $saved[ $id ] );
			update_option( self::RESULTS_OPTION, $saved, false );
		}

		wp_send_json_success();
	}

	/**
	 * AJAX handler to load a saved test result for viewing.
	 *
	 * @return void
	 */
	public function ajax_load_test_result(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'gpoai_quality_test' ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid nonce.', 'gp-translate-with-openai' ) ) );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'gp-translate-with-openai' ) ) );
		}

		$id    = sanitize_text_field( wp_unslash( $_POST['result_id'] ?? '' ) );
		$saved = get_option( self::RESULTS_OPTION, array() );

		if ( ! isset( $saved[ $id ] ) ) {
			wp_send_json_error( array( 'message' => __( 'Result not found.', 'gp-translate-with-openai' ) ) );
		}

		$result         = $saved[ $id ];
		$result['date'] = wp_date( 'Y-m-d H:i', $result['timestamp'] );

		wp_send_json_success( $result );
	}

	/**
	 * Fetch WordPress core translations from translate.wordpress.org.
	 *
	 * @param string $locale The locale slug.
	 * @param int    $count  Number of strings to fetch.
	 *
	 * @return array Array of source/translation pairs.
	 */
	protected function fetch_wordpress_translations( string $locale, int $count ): array {
		$cache_key = self::CACHE_PREFIX . $locale;
		$cached    = get_transient( $cache_key );

		if ( false !== $cached && is_array( $cached ) ) {
			return array_slice( $cached, 0, $count );
		}

		$wporg_locale = $this->convert_locale_to_wporg( $locale );

		$po_url = sprintf(
			'https://translate.wordpress.org/projects/wp/dev/%s/default/export-translations/?format=po',
			$wporg_locale
		);

		$response = wp_remote_get(
			$po_url,
			array(
				'timeout' => 60,
				'headers' => array(
					'Accept' => 'text/x-po, application/x-gettext',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $status_code ) {
			return array();
		}

		$po_content = wp_remote_retrieve_body( $response );
		if ( empty( $po_content ) ) {
			return array();
		}

		$strings = $this->parse_po_file( $po_content );

		if ( ! empty( $strings ) ) {
			set_transient( $cache_key, $strings, self::CACHE_EXPIRY );
		}

		return array_slice( $strings, 0, $count );
	}

	/**
	 * Parse a PO file and extract source/translation pairs.
	 *
	 * @param string $content The PO file content.
	 *
	 * @return array Array of source/translation pairs.
	 */
	protected function parse_po_file( string $content ): array {
		$strings = array();

		$blocks = preg_split( '/\n\s*\n/', $content );

		foreach ( $blocks as $block ) {
			$block = trim( $block );
			if ( empty( $block ) ) {
				continue;
			}

			if ( strpos( $block, 'msgid ""' ) === 0 && strpos( $block, 'Project-Id-Version' ) !== false ) {
				continue;
			}

			$msgid  = $this->extract_po_string( $block, 'msgid' );
			$msgstr = $this->extract_po_string( $block, 'msgstr' );

			if ( empty( $msgid ) || empty( $msgstr ) ) {
				continue;
			}

			if ( strpos( $block, 'msgid_plural' ) !== false ) {
				continue;
			}

			if ( strlen( $msgid ) < 3 ) {
				continue;
			}

			if ( preg_match( '/^[%\d\s\.\-\_]+$/', $msgid ) ) {
				continue;
			}

			$strings[] = array(
				'source'      => $msgid,
				'translation' => $msgstr,
			);
		}

		return $strings;
	}

	/**
	 * Extract a string value from a PO block.
	 *
	 * @param string $block The PO block.
	 * @param string $key   The key to extract (msgid or msgstr).
	 *
	 * @return string The extracted string.
	 */
	protected function extract_po_string( string $block, string $key ): string {
		$lines      = explode( "\n", $block );
		$in_key     = false;
		$full_value = '';

		foreach ( $lines as $line ) {
			$line = trim( $line );

			if ( preg_match( '/^' . preg_quote( $key, '/' ) . '\s+"((?:[^"\\\\]|\\\\.)*)"/', $line, $m ) ) {
				$in_key      = true;
				$full_value .= $m[1];
			} elseif ( $in_key && preg_match( '/^"((?:[^"\\\\]|\\\\.)*)"$/', $line, $m ) ) {
				$full_value .= $m[1];
			} elseif ( $in_key && preg_match( '/^(msgid|msgstr|msgctxt)/', $line ) ) {
				break;
			}
		}

		return str_replace( array( '\\n', '\\t', '\\"', '\\\\' ), array( "\n", "\t", '"', '\\' ), $full_value );
	}

	/**
	 * Calculate similarity between two strings.
	 *
	 * @param string $str1 First string.
	 * @param string $str2 Second string.
	 *
	 * @return int Similarity percentage (0-100).
	 */
	protected function calculate_similarity( string $str1, string $str2 ): int {
		$str1 = trim( $str1 );
		$str2 = trim( $str2 );

		if ( $str1 === $str2 ) {
			return 100;
		}

		if ( empty( $str1 ) || empty( $str2 ) ) {
			return 0;
		}

		similar_text( $str1, $str2, $percent );

		return (int) round( $percent );
	}

	/**
	 * Convert a GlotPress locale slug to WordPress.org format.
	 *
	 * @param string $locale The GlotPress locale slug.
	 *
	 * @return string The WordPress.org locale slug.
	 */
	protected function convert_locale_to_wporg( string $locale ): string {
		$mappings = array(
			'de' => 'de',
			'fr' => 'fr',
			'es' => 'es',
			'it' => 'it',
			'nl' => 'nl',
			'pt' => 'pt-br',
			'ru' => 'ru',
			'ja' => 'ja',
			'zh' => 'zh-cn',
			'ko' => 'ko',
			'ar' => 'ar',
			'he' => 'he',
			'pl' => 'pl',
			'tr' => 'tr',
			'sv' => 'sv',
			'da' => 'da',
			'fi' => 'fi',
			'no' => 'nb',
			'cs' => 'cs',
			'hu' => 'hu',
			'ro' => 'ro',
			'sk' => 'sk',
			'uk' => 'uk',
			'bg' => 'bg',
			'hr' => 'hr',
			'el' => 'el',
			'id' => 'id',
			'vi' => 'vi',
			'th' => 'th',
			'hi' => 'hi',
		);

		return $mappings[ $locale ] ?? $locale;
	}
}
