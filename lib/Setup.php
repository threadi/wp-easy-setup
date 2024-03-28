<?php
/**
 * File to handle the main tasks for this library.
 *
 * @package wp-easy-setup
 */

namespace wpEasySetup;

use Composer\Autoload\ClassLoader;
use WP_REST_Request;
use WP_REST_Server;

/**
 * Object to handle the main tasks for this library.
 */
class Setup {
	/**
	 * Config for the setup.
	 *
	 * @var array
	 */
	private array $config = array();

	/**
	 * Instance of this object.
	 *
	 * @var ?Setup
	 */
	private static ?Setup $instance = null;

	/**
	 * Constructor for Init-Handler.
	 */
	private function __construct() {
		// register our scripts.
		add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );

		// register REST API.
		add_action( 'rest_api_init', array( $this, 'add_rest_api' ) );
	}

	/**
	 * Prevent cloning of this object.
	 *
	 * @return void
	 */
	private function __clone() {}

	/**
	 * Return the instance of this Singleton object.
	 */
	public static function get_instance(): Setup {
		if ( ! static::$instance instanceof static ) {
			static::$instance = new static();
		}
		return static::$instance;
	}

	/**
	 * Return the setup-configuration.
	 *
	 * @return array
	 */
	private function get_config(): array {
		return $this->config;
	}

	/**
	 * Set the config for the setup.
	 *
	 * The array must contain following entries:
	 * - name => the unique name for the setup (e.g. the plugin slug)
	 * - url => the URL of the plugin where the setup is used
	 * - path => the path of the plugin where the setup is used
	 * - title => the language-specific title of the setup for the header of it
	 * - steps => list of steps (see documentation)
	 * - back_button_label => language-specific title for the back-button
	 * - continue_button_label => language-specific title for the continue-button
	 * - finish_button_label => language-specific title for the finish-button
	 * - title_error => title of modal if error occurred
	 * - txt_error_1 => initial text which will be visible before the error
	 * - txt_error_2 => text after the error
	 *
	 * @param array $config The config for the setup.
	 *
	 * @return void
	 */
	public function set_config( array $config ): void {
		// add step-count.
		$config['step_count'] = count( $config['steps'] );

		// set config in object.
		$this->config = $config;
	}

	/**
	 * Return the setup-steps from configuration.
	 *
	 * @return array
	 */
	private function get_setup_steps(): array {
		return $this->config['steps'];
	}

	/**
	 * Add our scripts for the setup.
	 *
	 * @return void
	 */
	public function add_scripts(): void {
		// get absolute path for this package.
		$path = __DIR__.'/../';

		// get the URL were we could call our scripts.
		$url = $this->config['url'].'/'.str_replace($this->config['path'], '', $this->get_vendor_path()).'/threadi/wp-easy-setup/';

		// embed the setup-JS-script.
		$script_asset_path = $path . 'build/setup.asset.php';
		$script_asset      = require $script_asset_path;
		wp_enqueue_script(
			'wp-easy-setup',
			$url . 'build/setup.js',
			$script_asset['dependencies'],
			$script_asset['version'],
			true
		);

		// embed the dialog-components CSS-script.
		$admin_css      = $url . 'build/setup.css';
		wp_enqueue_style(
			'wp-easy-setup',
			$admin_css,
			array( 'wp-components' ),
			'1.0.0' // TODO
		);

		// localize the script.
		wp_localize_script(
			'wp-easy-setup',
			'wp_easy_setup',
			array(
				'rest_nonce'       => wp_create_nonce( 'wp_rest' ),
				'validation_url'   => rest_url( 'wp-easy-setup/v1/validate-field' ),
				'process_url'      => rest_url( 'wp-easy-setup/v1/process' ),
				'process_info_url' => rest_url( 'wp-easy-setup/v1/get-process-info' ),
				'completed_url'    => rest_url( 'wp-easy-setup/v1/completed' ),
				'title_error'      => $this->get_config()['title_error'],
				'txt_error_1'      => $this->get_config()['txt_error_1'],
				'txt_error_2'      => $this->get_config()['txt_error_2'],
			)
		);
	}

	/**
	 * Get the vendor path.
	 *
	 * @return string
	 */
	private function get_vendor_path(): string {
		$reflector = new \ReflectionClass(ClassLoader::class);
		$vendorPath = preg_replace('/^(.*)\/composer\/ClassLoader\.php$/', '$1', $reflector->getFileName() );
		if($vendorPath && is_dir($vendorPath)) {
			return $vendorPath . '/';
		}
		throw new \RuntimeException('Unable to detect vendor path.');
	}

	/**
	 * Add rest api endpoints.
	 *
	 * @return void
	 */
	public function add_rest_api(): void {
		register_rest_route(
			'wp-easy-setup/v1',
			'/validate-field/',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'validate_field' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'wp-easy-setup/v1',
			'/process/',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'process_init' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'wp-easy-setup/v1',
			'/get-process-info/',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_process_info' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
		register_rest_route(
			'wp-easy-setup/v1',
			'/completed/',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_completed' ),
				'permission_callback' => function () {
					return current_user_can( 'manage_options' );
				},
			)
		);
	}

	/**
	 * Validate a given field via REST API request.
	 *
	 * @param WP_REST_Request $request The REST API request object.
	 *
	 * @return void
	 */
	public function validate_field( WP_REST_Request $request ): void {
		$validation_result = array(
			'field_name' => false,
			'result'     => 'error',
		);

		// get setup step.
		$step = $request->get_param( 'step' );

		// get field-name.
		$field_name = $request->get_param( 'field_name' );

		// get value.
		$value = $request->get_param( 'value' );

		// get setup-fields.
		$fields = $this->config['steps'];

		// run check if step and field_name are set.
		if ( ! empty( $step ) && ! empty( $field_name ) ) {
			// set field for response.
			$validation_result['field_name'] = $field_name;
			// check if field exist in step.
			if ( ! empty( $fields[ $step ][ $field_name ] ) ) {
				// get validation-callback for this field.
				$validation_callback = $fields[ $step ][ $field_name ]['validation_callback'];
				if ( ! empty( $validation_callback ) ) {
					if ( is_callable( $validation_callback ) ) {
						// call the validation callback and get its results.
						$validation_result['result'] = call_user_func( $validation_callback, $value );
					}
				}
			}
		}

		// Return JSON with results.
		wp_send_json( $validation_result );
	}

	/**
	 * Run the setup-progress via REST API.
	 *
	 * @return void
	 */
	public function process_init(): void {
		/**
		 * Run actions before setup-process is running.
		 *
		 * @since 3.0.0 Available since 3.0.0.
		 */
		do_action( 'wp_easy_setup_process_init' );

		// set marker that process is running.
		update_option( 'wp_easy_setup_running', 1 );

		// set max step count (could be overridden by process-action).
		update_option( 'wp_easy_setup_max_steps', 0 );

		// set actual steps to 0.
		update_option( 'wp_easy_setup_step', 0 );

		/**
		 * Run the process with custom tasks.
		 *
		 * @since 3.0.0 Available since 3.0.0.
		 */
		do_action( 'wp_easy_setup_process' );

		// set process as not running.
		update_option( 'wp_easy_setup_running', 0 );

		// return empty json.
		wp_send_json( array() );
	}

	/**
	 * Get progress info via REST API.
	 *
	 * @return void
	 */
	public function get_process_info(): void {
		$return = array(
			'running'    => absint( get_option( 'wp_easy_setup_running' ) ),
			'max'        => absint( get_option( 'wp_easy_setup_max_steps' ) ),
			'step'       => absint( get_option( 'wp_easy_setup_step' ) ),
			'step_label' => get_option( 'wp_easy_setup_step_label' ),
		);

		// Return JSON with result.
		wp_send_json( $return );
	}

	/**
	 * Return whether the setup has been completed.
	 *
	 * @return bool
	 */
	public function is_completed(): bool {
		// return true if main block functions are not available.
		if ( ! has_action( 'enqueue_block_assets' ) ) {
			return true;
		}

		// get actual completed setups.
		$actual_completed = get_option( 'wp_easy_setup_completed', array() );

		// return depending on own setting.
		$is_completed = in_array( $this->get_config()['name'], $actual_completed, true );

		/**
		 * Filter whether the setup is completed (true) or not (false).
		 *
		 * @since 1.0.0 Available since 1.0.0.
		 * @param bool $is_completed The return value.
		 */
		return apply_filters( 'wp_easy_setup_completed', $is_completed );
	}

	/**
	 * Set setup as completed.
	 *
	 * @return void
	 */
	public function set_completed(): void {
		// get actual list of completed setups.
		$actual_completed = get_option( 'wp_easy_setup_completed', array() );

		// add this setup to the list.
		$actual_completed[] = $this->get_config()['name'];

		// add the actual setup to the list of completed setups.
		update_option( 'wp_easy_setup_completed', $actual_completed );

		/**
		 * Run tasks if setup has been marked as completed.
		 *
		 * @since 3.0.0 Available since 3.0.0.
		 */
		do_action( 'wp_easy_setup_set_completed' );

		// return empty json.
		wp_send_json( array() );
	}

	/**
	 * Show setup dialog.
	 *
	 * @return string
	 */
	public function display(): string {
		return '<div id="wp-plugin-setup" data-config="' . esc_attr( wp_json_encode( $this->get_config() ) ) . '" data-fields="' . esc_attr( wp_json_encode( $this->get_setup_steps() ) ) . '"></div>';
	}

	/**
	 * Return the list of options this plugin is using.
	 *
	 * @return string[]
	 */
	private function get_options(): array {
		return array(
			'wp_easy_setup_max_steps' => 0,
			'wp_easy_setup_step' => 0,
			'wp_easy_setup_step_label' => '',
			'wp_easy_setup_running' => 0,
			'wp_easy_setup_completed' => array(),
		);
	}

	/**
	 * Tasks to run during plugin activation.
	 *
	 * Has to be called from main plugin file via:
	 * register_activation_hook( __FILE__, array( \wpEasySetup\Setup::get_instance(), 'activation' ) );
	 *
	 * @return void
	 */
	public function activation(): void {
		foreach( $this->get_options() as $option_name => $value ) {
			add_option( $option_name, $value, null, true );
		}
	}

	/**
	 * Tasks to run during plugin deinstallation.
	 *
	 * Has to be called in uninstall.php.
	 *
	 * @return void
	 */
	public function uninstall(): void {
		foreach( $this->get_options() as $option_name => $value ) {
			delete_option( $option_name );
		}
	}
}
