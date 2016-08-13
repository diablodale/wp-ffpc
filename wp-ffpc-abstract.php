<?php
/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
if ( !function_exists ('__translate__') ) {
	/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
	if ( function_exists ( '__' ) ) {
		function __translate__ ( $text, $domain ) { return __($text, $domain); }
	}
	else {
		function __translate__ ( $text, $domain ) { return $text; }
	}
}

if ( !function_exists ('__debug__') ) {
	/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
	function __debug__ ( $text ) {
		if ( defined('WP_FFPC__DEBUG_MODE') && WP_FFPC__DEBUG_MODE == true)
			error_log ( __FILE__ . ': ' . $text );
	}
}

if (!class_exists('WP_FFPC_ABSTRACT')):

/**
 * abstract class for common, required functionalities
 *
 */
abstract class WP_FFPC_ABSTRACT {

	const common_slug = 'wp-common/';

	protected $plugin_constant;
	protected $options = array();
	protected $defaults = array();
	protected $status = 0;
	protected $network = false;
	protected $settings_link = '';
	protected $settings_slug = '';
	protected $plugin_url;
	protected $plugin_dir;
	protected $common_url;
	protected $common_dir;
	protected $plugin_file;
	protected $plugin_name;
	protected $plugin_version;
	protected $plugin_settings_page;
	protected $button_save;
	protected $button_delete;
	protected $capability = 'manage_options';
	protected $admin_css_handle;
	protected $admin_css_url;
	protected $utils = null;
	
	protected $donation_business_name;
	protected $donation_item_name;
	protected $donation_business_id;
	protected $donation = false;

	/**
	* constructor
	*
	* @param string $plugin_constant General plugin identifier, same as directory & base PHP file name
	* @param string $plugin_version Version number of the parameter
	* @param string $plugin_name Readable name of the plugin
	* @param mixed $defaults Default value(s) for plugin option(s)
	* @param string $donation_link Donation link of plugin
	*
	*/
	public function __construct( $plugin_constant, $plugin_version, $plugin_name, $defaults, $donation_business_name = false, $donation_item_name = false, $donation_business_id = false ) {

		$this->plugin_constant = $plugin_constant;
		$this->plugin_file = $this->plugin_constant . '/' . $this->plugin_constant . '.php';

		$this->plugin_version = $plugin_version;
		$this->plugin_name = $plugin_name;
		$this->defaults = $defaults;

		$this->plugin_settings_page = $this->plugin_constant .'-settings';

		$this->button_save = $this->plugin_constant . '-save';
		$this->button_delete = $this->plugin_constant . '-delete';

		if ( !empty( $donation_business_name ) &&  !empty( $donation_item_name ) && !empty( $donation_business_id ) ) {
			$this->donation_business_name = $donation_business_name;
			$this->donation_item_name = $donation_item_name;
			$this->donation_business_id = $donation_business_id;
			$this->donation = true;
		}

		//$this->utils =  new PluginUtils();

		/* we need network wide plugin check functions */
		if ( ! function_exists( 'is_plugin_active_for_network' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		/* check if plugin is network-activated */
		if ( @is_plugin_active_for_network ( $this->plugin_file ) ) {
			$this->network = true;
			$this->settings_slug = 'settings.php';
		}
		else {
			$this->settings_slug = 'options-general.php';
		}

		/* set the settings page link string */
		$this->settings_link = $this->settings_slug . '?page=' .  $this->plugin_settings_page;

		/* initialize plugin, plugin specific init functions */
		$this->plugin_post_construct();

		// TODO The code should be split between code to run on every page (e.g. cache invalidation hooks) and
		// admin page code (e.g. saving settings). This division will then be hooked into init or admin_init
		// Some of the code, like the above forced load of wp-admin/includes/plugin.php (which
		// is loaded by the time admin_init occurs) can be altered. No need to run admin page code on non-admin pages.
		add_action( 'init', array(&$this,'plugin_init'));
		add_action( 'admin_init', array(&$this,'plugin_admin_init'));
		add_action( 'admin_enqueue_scripts', array(&$this,'enqueue_admin_css_js'));

	}

	/**
	 * activation hook function, to be extended
	 */
	abstract function plugin_activate();

	/**
	 * deactivation hook function, to be extended
	 */
	abstract function plugin_deactivate ();

	/**
	 * runs within the __construct, after all the initial settings
	 */
	abstract function plugin_post_construct();

	/**
	 * first init hook function, to be extended, before options were read
	 */
	abstract function plugin_pre_init();

	/**
	 * second init hook function, to be extended, after options were read
	 */
	abstract function plugin_post_init();

	public function plugin_init() {

		/* initialize plugin, plugin specific init functions */
		$this->plugin_pre_init();

		/* get the options */
		$this->plugin_options_read();

		register_activation_hook( $this->plugin_file , array( &$this, 'plugin_activate' ) );
		register_deactivation_hook( $this->plugin_file , array( &$this, 'plugin_deactivate' ) );

		/* register settings pages */
		if ( $this->network )
			add_filter( "network_admin_plugin_action_links_" . $this->plugin_file, array( &$this, 'plugin_settings_link' ) );
		else
			add_filter( "plugin_action_links_" . $this->plugin_file, array( &$this, 'plugin_settings_link' ) );

		/* register admin init, catches $_POST and adds submenu to admin menu */
		if ( $this->network )
			add_action('network_admin_menu', array( &$this , 'plugin_admin_menu') );
		else
			add_action('admin_menu', array( &$this , 'plugin_admin_menu') );

		add_filter('contextual_help', array( &$this, 'plugin_admin_help' ), 10, 2);

		/* setup plugin, plugin specific setup functions that need options */
		$this->plugin_post_init();
	}


	/**
	 * admin panel, the HTML usually
	 */
	abstract function plugin_admin_panel();

	/**
	 * admin help menu
	 */
	abstract function plugin_admin_help( $contextual_help, $screen_id );

	/**
	 * initial setup of WP Filesystem API to get credentials
	 * @param string $posturl raw unescaped url representing the destination after credential check
	 * @param string $testdir directory path to test for filesystem access
	 */
	static protected function plugin_setup_fileapi( $posturl, $testdir = false) {
		if ( !$testdir ) $testdir = WP_CONTENT_DIR;
		// if an http post, gather previously posted fields and remove any old credential form fields
		$fwd_post = $_POST;
		unset( $fwd_post['hostname'], $fwd_post['username'], $fwd_post['password'], $fwd_post['public_key'],
			$fwd_post['private_key'], $fwd_post['connection_type'], $fwd_post['upgrade'] );
		$posturl = esc_url_raw( add_query_arg( '_wpnonce-f', wp_create_nonce( 'wp-ffpc-fileapi' ), $posturl) );
		ob_start();
		if (false === ($credentials = request_filesystem_credentials($posturl, '', false, $testdir, array_keys($fwd_post)) ) ) {
			// we don't have credentials yet and request_filesystem_credentials() produced a form for the user to complete
			$data = ob_get_clean();
			if ( empty($data) ) wp_die(__('<h1>Error</h1><p>request_filesystem_credentials() failed. Can not proceed with this action.</p>')); // an unexpected situation occurred, we should have buffered a credential form
			include_once( ABSPATH . 'wp-admin/admin-header.php');
			echo $data;
			include( ABSPATH . 'wp-admin/admin-footer.php');
			exit;
		}

		// we have some credentials, check nonce if we previously produced a credential form and received a post
		if ( isset( $_POST['hostname'] ) ) check_admin_referer( 'wp-ffpc-fileapi', '_wpnonce-f' );

		// try to get the wp_filesystem running
		if ( ! WP_Filesystem($credentials) ) {
			// credentials were not good; ask the user for them again
			request_filesystem_credentials($posturl, '', true, $testdir, array_keys($fwd_post));
			$data = ob_get_clean();
			if ( empty($data) ) wp_die(__('<h1>Error</h1><p>request_filesystem_credentials() failed. Can not proceed with this action.</p>')); // an unexpected situation occurred, we should have buffered a credential form
			include_once( ABSPATH . 'wp-admin/admin-header.php');
			echo $data;
			include( ABSPATH . 'wp-admin/admin-footer.php');
			exit;
		}

		// we have good credentials and the filesystem should be ready on global $wp_filesystem
		ob_end_flush();
		global $wp_filesystem;
		if ( !is_object($wp_filesystem) ) {
			static::alert( __('Could not access the Wordpress Filesystem to configure WP-FFPC plugin'), LOG_WARNING );
			error_log( __('Could not access the Wordpress Filesystem to configure WP-FFPC plugin'));
			return false;
		}
		if ( is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->get_error_code() ) {
			static::alert( __('Wordpress Filesystem error: ') . $wp_filesystem->errors->get_error_message() .
				' (' . $wp_filesystem->errors->get_error_code() . ')', LOG_WARNING );
			error_log( __('Wordpress Filesystem error: ') . $wp_filesystem->errors->get_error_message() .
				' (' . $wp_filesystem->errors->get_error_code() . ')');
			return false;
		}
		return true;
	}

	/**
	 * admin init called by WordPress add_action, needs to be public
	 */
	public function plugin_admin_init() {
		/* save parameter updates, if there are any */
		if ( isset( $_POST[ $this->button_save ] ) ) {
			if ( !$this->plugin_setup_fileapi( $this->settings_link ) ) return;
			if ( !check_admin_referer( 'wp-ffpc-save', '_wpnonce-s' ) ) return;
			$this->plugin_options_save();	// BUGBUG the return codes from nested functions in plugin_options_save() are not caught, therefore errors in saving are also not caught 
			// BUGBUG warnings like "No WP-FFPC configuration settings are saved..." are
			// showing even though the apc file was written and it seems it was saved into the db.
			// Often on the immediate page after the change. I believe this is
			// due to the warning tests being evaluated before the changes to the db.
			// This logic flow error was being masked by the Header(location) hack I previously removed
			static::alert( __( 'Settings saved to database.' , 'wp-ffpc') , LOG_NOTICE );
		}

		/* delete parameters if requested */
		if ( isset( $_POST[ $this->button_delete ] ) ) {
			if ( !$this->plugin_setup_fileapi( $this->settings_link ) ) return;
			if ( !check_admin_referer( 'wp-ffpc-admin', '_wpnonce-a' ) ) return;
			$this->plugin_options_delete();	// BUGBUG the return codes from nested functions in plugin_options_delete() are not caught, therefore errors in deleting are also not caught 
			// BUGBUG same warning display problems as above
			static::alert( __( 'Plugin options deleted from database.' , 'wp-ffpc') , LOG_NOTICE );
		}

		/* load additional moves */
		$this->plugin_extend_admin_init();		
	}

	/**
	 * admin menu called by WordPress add_action, needs to be public
	 */
	public function plugin_admin_menu() {
		/* add submenu to settings pages */
		// TODO consider switching to use add_options_page()
		add_submenu_page( $this->settings_slug, $this->plugin_name . __( ' options' , 'wp-ffpc'), $this->plugin_name, $this->capability, $this->plugin_settings_page, array ( &$this , 'plugin_admin_panel' ) );
	}

	/**
	 * to be extended
	 *
	 */
	abstract function plugin_extend_admin_init();

	/**
	 * callback function to add settings link to plugins page
	 *
	 * @param array $links Current links to add ours to
	 *
	 */
	public function plugin_settings_link ( $links ) {
		$settings_link = '<a href="' . $this->settings_link . '">' . __translate__( 'Settings', 'wp-ffpc') . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/* add admin styling */
	public function enqueue_admin_css_js(){
		/* jquery ui tabs is provided by WordPress */
		wp_enqueue_script ( "jquery-ui-tabs" );
		wp_enqueue_script ( "jquery-ui-slider" );

		/* additional admin styling */
		wp_register_style( $this->admin_css_handle, $this->admin_css_url, array('dashicons'), false, 'all' );
		wp_enqueue_style( $this->admin_css_handle );
	}

	/**
	 * deletes saved options from database
	 */
	protected function plugin_options_delete () {
		static::_delete_option ( $this->plugin_constant, $this->network );

		/* additional moves */
		$this->plugin_extend_options_delete();
	}

	/**
	 * hook to add functionality into plugin_options_delete
	 */
	abstract function plugin_extend_options_delete ();

	/**
	 * reads options stored in database and reads merges them with default values
	 */
	protected function plugin_options_read () {
		$options = static::_get_option ( $this->plugin_constant, $this->network );

		/* this is the point to make any migrations from previous versions */
		$this->plugin_options_migrate( $options );

		/* map missing values from default */
		foreach ( $this->defaults as $key => $default )
			if ( !@array_key_exists ( $key, $options ) )
				$options[$key] = $default;

		/* removed unused keys, rare, but possible */
		foreach ( @array_keys ( $options ) as $key )
			if ( !@array_key_exists( $key, $this->defaults ) )
				unset ( $options[$key] );

		/* any additional read hook */
		$this->plugin_extend_options_read( $options );

		$this->options = $options;
	}

	/**
	 * hook for parameter migration, runs right after options read from DB
	 */
	abstract function plugin_options_migrate( &$options );

	/**
	 * hook to add functionality into plugin_options_read, runs after defaults check
	 */
	abstract function plugin_extend_options_read ( &$options );

	/**
	 * used on update and to save current options to database
	 *
	 * @param boolean $activating [optional] true on activation hook
	 *
	 */
	protected function plugin_options_save ( $activating = false ) {

		/* only try to update defaults if it's not activation hook, $_POST is not empty and the post
		   is ours */
		if ( !$activating && !empty ( $_POST ) && isset( $_POST[ $this->button_save ] ) ) {
			/* we'll only update those that exist in the defaults array */
			$options = $this->defaults;

			foreach ( $options as $key => $default )
			{
				/* $_POST element is available */
				if ( !empty( $_POST[$key] ) ) {
					$update = $_POST[$key];

					/* get rid of slashes in strings, just in case */
					if ( is_string ( $update ) )
						$update = stripslashes($update);

					$options[$key] = $update;
				}
				/* empty $_POST element: when HTML form posted, empty checkboxes a 0 input
				   values will not be part of the $_POST array, thus we need to check
				   if this is the situation by checking the types of the elements,
				   since a missing value means update from an integer to 0
				*/
				elseif ( empty( $_POST[$key] ) && ( is_bool ( $default ) || is_int( $default ) ) ) {
					$options[$key] = 0;
				}
				elseif ( empty( $_POST[$key] ) && is_array( $default) ) {
					$options[$key] = array();
				}
			}

			/* update the options array */
			$this->options = $options;
		}

		/* set plugin version */
		$this->options['version'] = $this->plugin_version;

		/* call hook function for additional moves before saving the values */
		$this->plugin_extend_options_save( $activating );

		/* save options to database */
		static::_update_option (  $this->plugin_constant , $this->options, $this->network );
	}

	/**
	 * hook to add functionality into plugin_options_save
	 */
	abstract function plugin_extend_options_save ( $activating );

	/**
	 * function to easily print a variable
	 *
	 * @param mixed $var Variable to dump
	 * @param boolean $ret Return text instead of printing if true
	 *
	*/
	protected function print_var ( $var , $ret = false ) {
		if ( @is_array ( $var ) || @is_object( $var ) || @is_bool( $var ) )
			$var = var_export ( $var, true );

		if ( $ret )
			return $var;
		else
			echo $var;
	}

	/**
	 * print value of an element from defaults array
	 *
	 * @param mixed $e Element index of $this->defaults array
	 *
	 */
	protected function print_default ( $e ) {
		_e('Default : ', 'wp-ffpc');
		$select = 'select_' . $e;
		if ( @is_array ( $this->$select ) ) {
			$x = $this->$select;
			$this->print_var ( $x[ $this->defaults[ $e ] ] );
		}
		else {
			$this->print_var ( $this->defaults[ $e ] );
		}
	}

	/**
	 * select options field processor
	 *
	 * @param elements
	 *  array to build <option> values of
	 *
	 * @param $current
	 *  the current active element
	 *
	 * @param $print
	 *  boolean: is true, the options will be printed, otherwise the string will be returned
	 *
	 * @return
	 * 	prints or returns the options string
	 *
	 */
	protected function print_select_options ( $elements, $current, $valid = false, $print = true ) {

		if ( is_array ( $valid ) )
			$check_disabled = true;
		else
			$check_disabled = false;

		$opt = '';
		foreach ($elements as $value => $name ) {
			//$disabled .= ( @array_key_exists( $valid[ $value ] ) && $valid[ $value ] == false ) ? ' disabled="disabled"' : '';
			$opt .= '<option value="' . $value . '" ';
			$opt .= selected( $value , $current );

			// ugly tree level valid check to prevent array warning messages
			if ( is_array( $valid ) && isset ( $valid [ $value ] ) && $valid [ $value ] == false )
				$opt .= ' disabled="disabled"';

			$opt .= '>';
			$opt .= $name;
			$opt .= "</option>\n";
		}

		if ( $print )
			echo $opt;
		else
			return $opt;
	}

	/**
	 * creates PayPal donation form based on plugin details
	 *
	 */
	protected function plugin_donation_form () {
		if ( $this->donation ) :
		?>
		<script>
			jQuery(document).ready(function($) {
				jQuery(function() {
					var select = $( "#amount" );
					var slider = $( '<div id="donation-slider"></div>' ).insertAfter( select ).slider({
						min: 1,
						max: 8,
						range: "min",
						value: select[ 0 ].selectedIndex + 1,
						slide: function( event, ui ) {
							select[ 0 ].selectedIndex = ui.value - 1;
						}
					});
					$( "#amount" ).change(function() {
						slider.slider( "value", this.selectedIndex + 1 );
					});
				});
			});
		</script>

		<form action="https://www.paypal.com/cgi-bin/webscr" method="post" class="<?php echo $this->plugin_constant ?>-donation">
			<label for="amount"><?php _e( "This plugin helped your business? I'd appreciate a coffee in return :) Please!", 'wp-ffpc'); ?></label>
			<select name="amount" id="amount">
				<option value="3">3$</option>
				<option value="5">5$</option>
				<option value="10" selected="selected">10$</option>
				<option value="15">15$</option>
				<option value="30">30$</option>
				<option value="42">42$</option>
				<option value="75">75$</option>
				<option value="100">100$</option>
			</select>
			<input type="hidden" id="cmd" name="cmd" value="_donations" />
			<input type="hidden" id="tax" name="tax" value="0" />
			<input type="hidden" id="business" name="business" value="<?php echo $this->donation_business_id ?>" />
			<input type="hidden" id="bn" name="bn" value="<?php echo $this->donation_business_name ?>" />
			<input type="hidden" id="item_name" name="item_name" value="<?php _e('Donation for ', 'wp-ffpc'); echo $this->donation_item_name ?>" />
			<input type="hidden" id="currency_code" name="currency_code" value="USD" />
			<input type="submit" name="submit" value="<?php _e('Donate via PayPal', 'wp-ffpc') ?>" class="button-secondary" />
		</form>
		<?php
		endif;
	}

	public function getoption ( $key ) {
		return ( empty (  $this->options[ $key] ) ) ? false :  $this->options[ $key];
	}


	/**
	 * UTILS
	 */
	/**
	 * option update; will handle network wide or standalone site options
	 *
	 */
	public static function _update_option ( $optionID, $data, $network = false ) {
		if ( $network ) {
			static::debug( sprintf( __( ' – updating network option %s', 'PluginUtils' ), $optionID ) );
			update_site_option( $optionID , $data );
		}
		else {
			static::debug( sprintf( __( '- updating option %s', 'PluginUtils' ), $optionID ));
			update_option( $optionID , $data );
		}
	}

	/**
	 * read option; will handle network wide or standalone site options
	 *
	 */
	public static function _get_option ( $optionID, $network = false ) {
		if ( $network ) {
			static::debug ( sprintf( __( '- getting network option %s', 'PluginUtils' ), $optionID ) );
			$options = get_site_option( $optionID );
		}
		else {
			static::debug( sprintf( __( ' – getting option %s', 'PluginUtils' ), $optionID ));
			$options = get_option( $optionID );
		}

		return $options;
	}

	/**
	 * clear option; will handle network wide or standalone site options
	 *
	 */
	public static function _delete_option ( $optionID, $network = false ) {
		if ( $network ) {
			static::debug ( sprintf( __( ' – deleting network option %s', 'PluginUtils' ), $optionID ) );
			delete_site_option( $optionID );
		}
		else {
			static::debug ( sprintf( __( ' – deleting option %s', 'PluginUtils' ), $optionID ) );
			delete_option( $optionID );
		}
	}

	/**
	 * read option; will handle network wide or standalone site options
	 *
	 */
	public static function _site_url ( $site = '', $network = false ) {
		if ( $network && !empty( $site ) )
			$url = get_blog_option ( $site, 'siteurl' );
		else
			$url = get_bloginfo ( 'url' );

		return $url;
	}

	/**
	 * replaces http:// with https:// in an url if server is currently running on https
	 *
	 * @param string $url URL to check
	 *
	 * @return string URL with correct protocol
	 *
	 */
	public static function replace_if_ssl ( $url ) {
		if ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https' )
			$_SERVER['HTTPS'] = 'on';

		if ( isset($_SERVER['HTTPS']) && (( strtolower($_SERVER['HTTPS']) == 'on' )  || ( $_SERVER['HTTPS'] == '1' ) ))
			$url = str_replace ( 'http://' , 'https://' , $url );

		return $url;
	}

	/**
	 */
	static function debug( $message, $level = LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case LOG_ERR :
				wp_die( '<h1>Error:</h1>' . '<p>' . $message . '</p>' );
				exit;
			default:
				if ( !defined( 'WP_DEBUG' ) || WP_DEBUG != true || !defined( 'WP_FFPC__DEBUG_MODE' ) || WP_FFPC__DEBUG_MODE != true )
					return;
				break;
		}

		error_log(  __CLASS__ . ": " . $message );
	}

	/**
	 * display formatted alert message
	 * 
	 * @param string $msg Error message
	 * @param string $error "level" of error
	 * @param boolean $network WordPress network or not, DEPRECATED
	 * 
	 */
	static public function alert ( $msg, $level=LOG_WARNING, $network=false ) {
		if ( empty($msg)) return false;
		//if ( php_sapi_name() === "cli" ) return false;

		switch ($level) {
			case LOG_WARNING:
				//$css = "notice notice-warning";
				//break;
			case LOG_ERR:
				$css = "error notice notice-error";
				break;
			case LOG_INFO:
				$css = "notice notice-info";
				break;
			case LOG_NOTICE:
			default:
				$css = "updated notice notice-success";
				break;
		}

		$r = '<div class="'. $css .'"><p>'. sprintf ( __('%s', 'PluginUtils' ),  $msg ) .'</p></div>';
		if ( version_compare(phpversion(), '5.3.0', '>=')) {
			// BUGBUG notices here and below will not appear in multisite network admin or multisite user admin pages
			// see do_action's in wordpress\wp-admin\admin-header.php
			add_action('admin_notices', function() use ($r) { echo $r; }, 10 );
		}
		else {
			global $tmp;
			$tmp = $r;
			$f = create_function ( '', 'global $tmp; echo $tmp;' );
			add_action('admin_notices', $f );
		}
		static::debug( $msg, $level );
	}

}

endif;
