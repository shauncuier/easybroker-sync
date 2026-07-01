<?php
/**
 * Admin: settings page, sync buttons, and sync log.
 *
 * @package EasyBroker_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Settings screen under the EasyBroker menu.
 */
class EBS_Admin {

	const MENU_SLUG = 'easybroker-sync';

	/**
	 * Register hooks.
	 */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
	}

	/**
	 * Contextual admin notices, shown only on the plugin's own screens.
	 */
	public function notices() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || EBS_Cpt::POST_TYPE !== $screen->post_type ) {
			return;
		}

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'EasyBroker Sync: WP-Cron is disabled (DISABLE_WP_CRON). Automatic sync will not run unless a real server cron calls wp-cron.php. Use the “Push to EasyBroker now” / “Import from EasyBroker” buttons, or configure a system cron.', 'easybroker-sync' );
			echo '</p></div>';
		}

		$settings = get_option( EBS_OPTION, array() );
		$db_key   = ! empty( $settings['api_key'] );
		if ( $db_key && ! ( defined( 'EBS_API_KEY' ) && EBS_API_KEY ) ) {
			echo '<div class="notice notice-info is-dismissible"><p>';
			echo esc_html__( 'EasyBroker Sync: your API key is stored in the database. For better security, define it in wp-config.php:', 'easybroker-sync' );
			echo ' <code>define( \'EBS_API_KEY\', \'your-key\' );</code>';
			echo '</p></div>';
		}
	}

	/**
	 * Add the settings submenu under the Properties CPT menu.
	 */
	public function menu() {
		add_submenu_page(
			'edit.php?post_type=' . EBS_Cpt::POST_TYPE,
			__( 'EasyBroker Sync', 'easybroker-sync' ),
			__( 'EasyBroker Sync', 'easybroker-sync' ),
			'manage_options',
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Register the settings option and fields.
	 */
	public function register_settings() {
		register_setting(
			'ebs_settings_group',
			EBS_OPTION,
			array(
				'type'              => 'object',
				'sanitize_callback' => array( $this, 'sanitize_settings' ),
				'show_in_rest'      => false, // Never expose the API key via the REST API.
				'autoload'          => false, // Contains the API key — don't load on every request.
			)
		);

		// Older WordPress ignores the autoload arg above; flip it once if possible.
		if ( function_exists( 'wp_set_option_autoload' ) ) {
			wp_set_option_autoload( EBS_OPTION, false );
		}
	}

	/**
	 * Sanitize the settings array.
	 *
	 * @param array $input Raw input.
	 * @return array
	 */
	public function sanitize_settings( $input ) {
		$existing = get_option( EBS_OPTION, array() );
		$out      = is_array( $existing ) ? $existing : array();

		// API key: keep existing if the field was left blank (masked display).
		if ( isset( $input['api_key'] ) ) {
			$key = sanitize_text_field( $input['api_key'] );
			if ( '' !== $key && false === strpos( $key, '•' ) ) {
				$out['api_key'] = $key;
			}
		}

		$currency             = isset( $input['currency'] ) ? EBS_Field_Map::sanitize_currency( $input['currency'] ) : '';
		$out['currency']      = '' !== $currency ? $currency : 'MXN';
		$out['agent_email']   = isset( $input['agent_email'] ) ? sanitize_email( $input['agent_email'] ) : '';
		$out['image_mode']    = ( isset( $input['image_mode'] ) && 'hotlink' === $input['image_mode'] ) ? 'hotlink' : 'import';
		$out['pull_own']      = ( isset( $input['pull_own'] ) && 'yes' === $input['pull_own'] ) ? 'yes' : 'no';
		$allowed_intervals    = array( 'ebs_hourly', 'ebs_six_hours', 'daily' );
		$out['cron_interval'] = ( isset( $input['cron_interval'] ) && in_array( $input['cron_interval'], $allowed_intervals, true ) )
			? $input['cron_interval']
			: 'ebs_hourly';

		return $out;
	}

	/**
	 * Enqueue admin assets on our page and the property editor.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$is_ours = ( $screen && EBS_Cpt::POST_TYPE === $screen->post_type )
			|| ( false !== strpos( (string) $hook, self::MENU_SLUG ) );
		if ( ! $is_ours ) {
			return;
		}
		wp_enqueue_style( 'ebs-admin', EBS_URL . 'assets/admin.css', array(), EBS_VERSION );
		wp_enqueue_script( 'ebs-admin', EBS_URL . 'assets/admin.js', array( 'jquery' ), EBS_VERSION, true );
		wp_localize_script(
			'ebs-admin',
			'EBS_Admin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'ebs_admin_action' ),
				'strings' => array(
					'working' => __( 'Working…', 'easybroker-sync' ),
					'done'    => __( 'Done.', 'easybroker-sync' ),
					'failed'  => __( 'Request failed.', 'easybroker-sync' ),
				),
			)
		);
	}

	/**
	 * Render the settings page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings   = get_option( EBS_OPTION, array() );
		$has_key    = ! empty( $settings['api_key'] ) || ( defined( 'EBS_API_KEY' ) && EBS_API_KEY );
		$key_locked = defined( 'EBS_API_KEY' ) && EBS_API_KEY;
		$currency   = isset( $settings['currency'] ) ? $settings['currency'] : 'MXN';
		$agent_email = isset( $settings['agent_email'] ) ? $settings['agent_email'] : '';
		$image_mode = isset( $settings['image_mode'] ) ? $settings['image_mode'] : 'import';
		$pull_own   = isset( $settings['pull_own'] ) ? $settings['pull_own'] : 'yes';
		$interval   = isset( $settings['cron_interval'] ) ? $settings['cron_interval'] : 'ebs_hourly';
		?>
		<div class="wrap ebs-wrap">
			<h1><?php esc_html_e( 'EasyBroker Sync', 'easybroker-sync' ); ?></h1>

			<h2 class="title"><?php esc_html_e( 'Settings', 'easybroker-sync' ); ?></h2>
			<form method="post" action="options.php">
				<?php settings_fields( 'ebs_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="ebs_api_key"><?php esc_html_e( 'API Key', 'easybroker-sync' ); ?></label></th>
						<td>
							<?php if ( $key_locked ) : ?>
								<em><?php esc_html_e( 'Defined via the EBS_API_KEY constant in wp-config.php.', 'easybroker-sync' ); ?></em>
							<?php else : ?>
								<input type="password" id="ebs_api_key" name="<?php echo esc_attr( EBS_OPTION ); ?>[api_key]"
									value="" placeholder="<?php echo esc_attr( $has_key ? '••••••••••••' : '' ); ?>" class="regular-text" autocomplete="off" />
								<p class="description"><?php esc_html_e( 'Leave blank to keep the stored key. Find your key in EasyBroker → Settings → API.', 'easybroker-sync' ); ?></p>
							<?php endif; ?>
							<button type="button" class="button" id="ebs-test-connection"><?php esc_html_e( 'Test connection', 'easybroker-sync' ); ?></button>
							<span class="ebs-inline-result" id="ebs-test-result"></span>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ebs_currency"><?php esc_html_e( 'Default Currency', 'easybroker-sync' ); ?></label></th>
						<td><input type="text" id="ebs_currency" name="<?php echo esc_attr( EBS_OPTION ); ?>[currency]" value="<?php echo esc_attr( $currency ); ?>" class="small-text" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="ebs_agent_email"><?php esc_html_e( 'Default Agent Email', 'easybroker-sync' ); ?></label></th>
						<td>
							<input type="email" id="ebs_agent_email" name="<?php echo esc_attr( EBS_OPTION ); ?>[agent_email]" value="<?php echo esc_attr( $agent_email ); ?>" class="regular-text" />
							<p class="description"><?php esc_html_e( 'EasyBroker account email used as the listing agent when a property has none set.', 'easybroker-sync' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Image Handling', 'easybroker-sync' ); ?></th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( EBS_OPTION ); ?>[image_mode]" value="import" <?php checked( $image_mode, 'import' ); ?> /> <?php esc_html_e( 'Import into media library', 'easybroker-sync' ); ?></label><br />
							<label><input type="radio" name="<?php echo esc_attr( EBS_OPTION ); ?>[image_mode]" value="hotlink" <?php checked( $image_mode, 'hotlink' ); ?> /> <?php esc_html_e( 'Hotlink remote images', 'easybroker-sync' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Also pull own listings', 'easybroker-sync' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( EBS_OPTION ); ?>[pull_own]" value="yes" <?php checked( $pull_own, 'yes' ); ?> /> <?php esc_html_e( 'Import own EasyBroker listings that do not yet exist in WordPress', 'easybroker-sync' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="ebs_cron_interval"><?php esc_html_e( 'Sync Frequency', 'easybroker-sync' ); ?></label></th>
						<td>
							<select id="ebs_cron_interval" name="<?php echo esc_attr( EBS_OPTION ); ?>[cron_interval]">
								<option value="ebs_hourly" <?php selected( $interval, 'ebs_hourly' ); ?>><?php esc_html_e( 'Every hour', 'easybroker-sync' ); ?></option>
								<option value="ebs_six_hours" <?php selected( $interval, 'ebs_six_hours' ); ?>><?php esc_html_e( 'Every 6 hours', 'easybroker-sync' ); ?></option>
								<option value="daily" <?php selected( $interval, 'daily' ); ?>><?php esc_html_e( 'Daily', 'easybroker-sync' ); ?></option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2 class="title"><?php esc_html_e( 'Manual Sync', 'easybroker-sync' ); ?></h2>
			<p>
				<button type="button" class="button button-primary ebs-action" data-action="ebs_push_pending"><?php esc_html_e( 'Push pending listings', 'easybroker-sync' ); ?></button>
				<button type="button" class="button ebs-action" data-action="ebs_pull_now"><?php esc_html_e( 'Import from EasyBroker', 'easybroker-sync' ); ?></button>
				<span class="ebs-inline-result" id="ebs-action-result"></span>
			</p>
			<p class="description">
				<?php esc_html_e( 'Import pulls your own EasyBroker listings into WordPress and refreshes the collaboration-agency roster below. EasyBroker does not expose partner agencies\' individual listings via the API.', 'easybroker-sync' ); ?>
			</p>

			<?php $this->render_agencies(); ?>

			<h2 class="title"><?php esc_html_e( 'Sync Log', 'easybroker-sync' ); ?></h2>
			<?php $this->render_log(); ?>
		</div>
		<?php
	}

	/**
	 * Render the collaboration-agency roster (from GET /collaborations).
	 */
	private function render_agencies() {
		$agencies = EBS_Pull::get_agencies();
		echo '<h2 class="title">' . esc_html__( 'Collaboration Agencies', 'easybroker-sync' ) . '</h2>';
		if ( empty( $agencies ) ) {
			echo '<p>' . esc_html__( 'No collaboration agencies recorded yet. Click "Import from EasyBroker".', 'easybroker-sync' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr>';
		echo '<th>' . esc_html__( 'Agency ID', 'easybroker-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Agency Name', 'easybroker-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Group', 'easybroker-sync' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $agencies as $a ) {
			printf(
				'<tr><td>%1$d</td><td>%2$s</td><td>%3$s</td></tr>',
				(int) $a['agency_id'],
				esc_html( $a['agency_name'] ),
				! empty( $a['group'] ) ? esc_html__( 'Yes', 'easybroker-sync' ) : '—'
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Render the sync log table.
	 */
	private function render_log() {
		$log = EBS_Logger::get();
		if ( empty( $log ) ) {
			echo '<p>' . esc_html__( 'No sync activity yet.', 'easybroker-sync' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped ebs-log"><thead><tr>';
		echo '<th>' . esc_html__( 'Time', 'easybroker-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Direction', 'easybroker-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Status', 'easybroker-sync' ) . '</th>';
		echo '<th>' . esc_html__( 'Message', 'easybroker-sync' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $log as $row ) {
			$post_link = '';
			if ( ! empty( $row['post_id'] ) ) {
				$edit = get_edit_post_link( $row['post_id'] );
				if ( $edit ) {
					$post_link = ' (<a href="' . esc_url( $edit ) . '">#' . (int) $row['post_id'] . '</a>)';
				}
			}
			printf(
				'<tr><td>%1$s</td><td>%2$s</td><td><span class="ebs-badge ebs-badge-%3$s">%3$s</span></td><td>%4$s%5$s</td></tr>',
				esc_html( $row['time'] ),
				esc_html( $row['direction'] ),
				esc_html( $row['status'] ),
				esc_html( $row['message'] ),
				wp_kses_post( $post_link )
			);
		}
		echo '</tbody></table>';
	}
}
