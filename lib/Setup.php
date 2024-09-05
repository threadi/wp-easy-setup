<?php
/**
 * File to handle the main tasks for this library.
 *
 * @package wp-easy-setup
 */

namespace wpEasySetup;

// prevent direct access.
defined( 'ABSPATH' ) || exit;

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
     * The URL to use.
     *
     * @var string
     */
    private string $url = '';

    /**
     * The path to use.
     *
     * @var string
     */
    private string $path = '';

    /**
     * List of texts to use for setup-errors.
     *
     * @var array
     */
    private array $texts = array();

    /**
     * The configured vendor path.
     *
     * @var string
     */
    private string $vendor_path = '';

    /**
     * The error help.
     *
     * @var string
     */
    private string $error_help = '';

    /**
     * Constructor for Init-Handler.
     */
    private function __construct() {
        // register our scripts.
        add_action( 'admin_enqueue_scripts', array( $this, 'add_scripts' ) );

        // register REST API.
        add_action( 'rest_api_init', array( $this, 'add_rest_api' ) );

        // add action to skip setup.
        add_action( 'admin_action_wp_easy_setup_skip', array( $this, 'skip_setup' ) );
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
     * Return the setup-configuration for given name.
     *
     * @param string $name The configuration name to use.
     *
     * @return array
     */
    private function get_config( string $name ): array {
        // bail if requested configuration is not available.
        if( empty($this->config[$name]) ) {
            return array();
        }

        // return the configuration.
        return $this->config[$name];
    }

    /**
     * Set the config for the setup.
     *
     * The array must contain following entries:
     * - name => the unique name for the setup (e.g. the plugin slug)
     * - title => the language-specific title of the setup for the header of it
     * - steps => list of steps (see documentation)
     * - back_button_label => language-specific title for the back-button
     * - continue_button_label => language-specific title for the continue-button
     * - finish_button_label => language-specific title for the finish-button
     * - skip_button_label => language-specific title for the skip-button
     *
     * @param array $config The config for the setup.
     *
     * @return void
     */
    public function set_config( array $config ): void {
        // only add if required values are set.
        if( empty( $config['name'] ) || empty( $config['steps'] ) ) {
            return;
        }

        // set config in object.
        $this->config[$config['name']] = $config;
    }

    /**
     * Return the setup-steps from configuration.
     *
     * @param string $name Configuration name to use.
     *
     * @return array
     */
    private function get_setup_steps( string $name ): array {
        // bail if requested configuration is unknown.
        if( empty( $this->config[$name] ) ) {
            return array();
        }

        // return the configuration.
        return apply_filters( 'wp_easy_setup_steps', $this->config[$name]['steps'] );
    }

    /**
     * Add our scripts for the setup.
     *
     * @return void
     */
    public function add_scripts(): void {
        // bail if no configuration is set.
        if( empty( $this->url ) && empty( $this->path ) ) {
            return;
        }

        // get absolute path for this package.
        $path = __DIR__.'/../';

        // get the URL were we could call our scripts.
        $url = $this->get_url().'/'.str_replace($this->get_path(), '', $this->get_vendor_path()).'/threadi/wp-easy-setup/';

        // embed the setup-JS-script.
        $script_asset_path = $path . 'build/setup.asset.php';

        // bail if path does not exist.
        if( ! file_exists( $script_asset_path ) ) {
            return;
        }

        $script_asset      = require $script_asset_path;
        wp_enqueue_script(
            'wp-easy-setup',
            $url . 'build/setup.js',
            $script_asset['dependencies'],
            $script_asset['version'],
            true
        );

        // embed the dialog-components CSS-script.
        wp_enqueue_style(
            'wp-easy-setup',
            $url . 'build/setup.css',
            array( 'wp-components' ),
            filemtime( $path . 'build/setup.css' )
        );

        // localize the script.
        wp_localize_script(
            'wp-easy-setup',
            'wp_easy_setup',
            array(
                'rest_nonce'       => wp_create_nonce( 'wp_rest' ),
                'get_fields'   => rest_url( 'wp-easy-setup/v1/fields' ),
                'validation_url'   => rest_url( 'wp-easy-setup/v1/validate-field' ),
                'process_url'      => rest_url( 'wp-easy-setup/v1/process' ),
                'process_info_url' => rest_url( 'wp-easy-setup/v1/get-process-info' ),
                'completed_url'    => rest_url( 'wp-easy-setup/v1/completed' ),
                'title_error'      => $this->get_texts()['title_error'],
                'txt_error_1'      => $this->get_texts()['txt_error_1'],
                'txt_error_2'      => $this->get_texts()['txt_error_2'],
            )
        );
    }

    /**
     * Get the vendor path.
     *
     * @return string
     */
    private function get_vendor_path(): string {
        // return configured vendor path.
        if( ! empty( $this->vendor_path ) ) {
            return $this->vendor_path;
        }

        // detect vendor path.
        $path = str_replace('/threadi/wp-easy-setup/lib', '', __DIR__ );
        return basename( $path );
    }

    /**
     * Add rest api endpoints.
     *
     * @return void
     */
    public function add_rest_api(): void {
        register_rest_route(
            'wp-easy-setup/v1',
            '/fields/(?P<config_name>[a-zA-Z0-9-]+)',
            array(
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => array( $this, 'get_fields' ),
                'permission_callback' => function () {
                    return true; //current_user_can( 'manage_options' );
                },
            )
        );
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
                'callback'            => array( $this, 'set_completed_by_request' ),
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

        // get config-name.
        $config_name = $request->get_param( 'config_name' );

        // get setup step.
        $step = $request->get_param( 'step' );

        // get field-name.
        $field_name = $request->get_param( 'field_name' );

        // get value.
        $value = $request->get_param( 'value' );

        // run check if step and field_name are set.
        if ( ! empty( $step ) && ! empty( $field_name ) ) {
            // get setup-fields of requested configuration.
            $fields = $this->get_setup_steps( $config_name );

            // set field for response.
            $validation_result['field_name'] = $field_name;

            // check if field exist in the requested step of the requested setup-configuration.
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
     * @param WP_REST_Request $request The REST API request object.
     *
     * @return void
     */
    public function process_init( WP_REST_Request $request ): void {
        $config_name = $request->get_param( 'config_name' );

        /**
         * Run actions before setup-process is running.
         *
         * @since 3.0.0 Available since 3.0.0.
         *
         * @param string $config_name The name of the requested setup-configuration.
         */
        do_action( 'wp_easy_setup_process_init', $config_name );

        // reset step label.
        update_option('wp_easy_setup_step_label', '' );

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
         *
         * @param string $config_name The name of the requested setup-configuration.
         */
        do_action( 'wp_easy_setup_process', $config_name );

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

        // return JSON with result.
        wp_send_json( $return );
    }

    /**
     * Return whether the setup has been completed.
     *
     * @param string $config_name The name of the requested setup-configuration.
     *
     * @return bool
     */
    public function is_completed( string $config_name ): bool {
        // return true if main block functions are not available.
        if ( ! has_action( 'enqueue_block_assets' ) ) {
            return true;
        }

        // get actual completed setups.
        $actual_completed = get_option( 'wp_easy_setup_completed', array() );

        // check if requested setup is completed.
        $is_completed = in_array( $config_name, $actual_completed, true );

        /**
         * Filter whether the setup is completed (true) or not (false).
         *
         * @since 1.0.0 Available since 1.0.0.
         * @param bool $is_completed The return value.
         * @param string $config_name The configuration name.
         */
        return apply_filters( 'wp_easy_setup_completed', $is_completed, $config_name );
    }

    /**
     * Set setup as completed.
     *
     * @param WP_REST_Request $request The REST API request object.
     *
     * @return void
     */
    public function set_completed_by_request( WP_REST_Request $request ): void {
        // get config name.
        $config_name = $request->get_param( 'config_name' );

        // set completed.
        $this->set_completed( $config_name );

        // return empty json.
        wp_send_json( array() );
    }

    /**
     * Set setup to complete.
     *
     * @param string $config_name The config name.
     *
     * @return void
     */
    public function set_completed( string $config_name ): void {
        // get actual list of completed setups.
        $actual_completed = get_option( 'wp_easy_setup_completed', array() );

        // add this setup to the list.
        $actual_completed[] = $this->get_config( $config_name )['name'];

        // add the actual setup to the list of completed setups.
        update_option( 'wp_easy_setup_completed', $actual_completed );

        /**
         * Run tasks if setup has been marked as completed.
         *
         * @since 3.0.0 Available since 3.0.0.
         * @param string $config_name The name of the requested setup-configuration.
         */
        do_action( 'wp_easy_setup_set_completed', $config_name );
    }

    /**
     * Show setup dialog.
     *
     * @param string $name The name of the requested setup-configuration.
     *
     * @return string
     */
    public function display( string $name ): string {
        return '<div id="wp-easy-setup" data-config="' . esc_attr( wp_json_encode( $this->get_config( $name ) ) ) . '" data-fields="' . esc_attr( wp_json_encode( $this->get_setup_steps( $name ) ) ) . '">' . $this->get_error_help() . '</div>';
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

    /**
     * Return the URL.
     *
     * @return string
     */
    private function get_url(): string {
        return $this->url;
    }

    /**
     * Set the URL.
     *
     * @param string $url The URL to use.
     *
     * @return void
     */
    public function set_url( string $url ): void {
        $this->url = $url;
    }

    /**
     * Set the path (relativ to URL).
     *
     * @return string
     */
    private function get_path(): string {
        return $this->path;
    }

    /**
     * Set the path (relativ to URL).
     *
     * @param string $path The path to use.
     *
     * @return void
     */
    public function set_path( string $path ): void {
        $this->path = $path;
    }

    /**
     * Return the default texts for setup errors.
     *
     * @return array
     */
    private function get_texts(): array {
        return $this->texts;
    }

    /**
     * Set the default texts for setup errors.
     *
     * @param array $texts List of texts.
     *
     * @return void
     */
    public function set_texts( array $texts ): void {
        $this->texts = $texts;
    }

    /**
     * Get actual fields via request.
     *
     * @param WP_REST_Request $request
     *
     * @return array
     */
    public function get_fields( WP_REST_Request $request ): array {
        // get config-name.
        $config_name = $request->get_param( 'config_name' );

        // bail if no config is given.
        if( empty( $config_name ) ) {
            return array();
        }

        // return the configuration.
        return $this->get_setup_steps( $config_name );
    }

    /**
     * Set vendor path.
     *
     * @param string $vendor_path
     *
     * @return void
     */
    public function set_vendor_path( string $vendor_path ): void {
        $this->vendor_path = $vendor_path;
    }

    /**
     * Return help in case for error on loading of setup.
     *
     * @return string
     */
    private function get_error_help(): string {
        return $this->error_help;
    }

    /**
     * Set the error help.
     *
     * @param string $error_help The text for the error help.
     *
     * @return void
     */
    public function set_error_help( string $error_help ): void {
        $this->error_help = $error_help;
    }

    /**
     * Return skip link.
     *
     * @param string $config_name The config name.
     * @param string $url The URL to forward.
     *
     * @return string
     */
    public function get_skip_url( string $config_name, string $url ): string {
        return add_query_arg(
            array(
                'action' => 'wp_easy_setup_skip',
                'nonce' => wp_create_nonce( 'wp-easy-setup-skip' ),
                'config_name' => $config_name,
                'url' => urlencode( $url )
            ),
            get_admin_url() . 'admin.php'
        );
    }

    /**
     * Skip setup:
     * - set it to complete.
     * - forward user to given URL.
     *
     * @return void
     * @noinspection PhpNoReturnAttributeCanBeAddedInspection
     */
    public function skip_setup(): void {
        check_admin_referer( 'wp-easy-setup-skip', 'nonce' );

        // get config name from request.
        $config_name = filter_input( INPUT_GET, 'config_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS );

        // get forward URL.
        $forward_url = filter_input( INPUT_GET, 'url', FILTER_SANITIZE_URL );

        // set setup to complete.
        $this->set_completed( $config_name );

        // forward user to given url.
        wp_safe_redirect( $forward_url );
        exit;
    }
}
