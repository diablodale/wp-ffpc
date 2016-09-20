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
	function __debug__( $text ) {
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

	const LOG_INFO = 106;		// consts for alert mechanism; can't use LOG_*** constants because Windows PHP duplicates five of the values in PHP 5.5.12
	const LOG_NOTICE = 105;
	const LOG_WARNING = 104;
	const LOG_ERR = 103;
	const LOG_CRIT = 102;
	const LOG_ALERT = 101;
	const LOG_EMERG = 100;

	protected $plugin_constant;
	protected $options = array();
	protected $defaults = array();
	protected static $network_activated = false;
	protected static $capability_needed = 'manage_options';
	protected $settings_link = '';
	protected $settings_slug = '';
	protected $settings_page_hook_suffix = false;
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
	protected $button_flush;
	protected $button_precache;
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
		$this->button_flush = $this->plugin_constant . '-flush';
		$this->button_precache = $this->plugin_constant . '-precache';


		if ( !empty( $donation_business_name ) && !empty( $donation_item_name ) && !empty( $donation_business_id ) ) {
			$this->donation_business_name = $donation_business_name;
			$this->donation_item_name = $donation_item_name;
			$this->donation_business_id = $donation_business_id;
			$this->donation = true;
		}

		/* we need network wide plugin check functions */
		if ( ! function_exists( 'is_plugin_active_for_network' ) )
			require_once( ABSPATH . 'wp-admin/includes/plugin.php' );

		/* check if plugin is network-activated */
		if ( is_plugin_active_for_network ( $this->plugin_file ) ) {
			static::$network_activated = true;
			static::$capability_needed = 'manage_network_options';
			$this->settings_slug = 'settings.php';
		}
		else {
			$this->settings_slug = 'options-general.php';
		}

		/* set the settings page link string and register the activation callback */
		$this->settings_link = $this->settings_slug . '?page=' . $this->plugin_settings_page;
		register_activation_hook( $this->plugin_file , array( &$this, 'plugin_activate' ) );

		/* initialize plugin, plugin specific init functions */
		$this->plugin_post_construct();

		// TODO The code should be split between code to run on every page (e.g. cache invalidation hooks) and
		// admin page code (e.g. saving settings). This division will then be hooked into init or admin_init
		// Some of the code, like the above forced load of wp-admin/includes/plugin.php (which
		// is loaded by the time admin_init occurs) can be altered. No need to run admin page code on non-admin pages.
		add_action( 'init', array(&$this,'plugin_init'));
	}

	/**
	 * activation hook function
	 */
	public function plugin_activate() {
		if (version_compare(PHP_VERSION, '5.3.0', '<')) {
			deactivate_plugins( $this->plugin_file );
			wp_die( $this->plugin_name . __(' plugin requires PHP version 5.3 or newer. The activation has been cancelled.', 'wp-ffpc') );
		}
		global $wp_version;
		if ( version_compare($wp_version, '3.1', '<') ) {
			deactivate_plugins( $this->plugin_file );
			wp_die( $this->plugin_name . __(' plugin requires Wordpress 3.1 or newer. The activation has been cancelled.', 'wp-ffpc') );
		}
	}

	/**
	 * deactivation hook function, to be extended
	 */
	abstract function plugin_deactivate();

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

		register_deactivation_hook( $this->plugin_file , array( &$this, 'plugin_deactivate' ) );

		// admin pages only
		if ( is_admin() ) {
			add_action( 'admin_init', array(&$this,'plugin_admin_init'));
			/* register to add submenu to admin menu */
			if ( static::$network_activated )
				add_action('network_admin_menu', array( &$this , 'plugin_admin_menu') );
			else
				add_action('admin_menu', array( &$this , 'plugin_admin_menu') );
		}

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
	abstract function plugin_admin_help( $contextual_help, $screen_id, $screen );

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
			include_once ABSPATH . 'wp-admin/admin-header.php';
			echo $data;
			include ABSPATH . 'wp-admin/admin-footer.php';
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
			include_once ABSPATH . 'wp-admin/admin-header.php';
			echo $data;
			include ABSPATH . 'wp-admin/admin-footer.php';
			exit;
		}

		// we have good credentials and the filesystem should be ready on global $wp_filesystem
		ob_end_flush();
		global $wp_filesystem;
		if ( !is_object($wp_filesystem) ) {
			static::alert( __('Could not access the Wordpress Filesystem to configure WP-FFPC plugin'), self::LOG_WARNING );
			error_log( __('Could not access the Wordpress Filesystem to configure WP-FFPC plugin'));
			return false;
		}
		if ( is_wp_error($wp_filesystem->errors) && $wp_filesystem->errors->get_error_code() ) {
			static::alert( __('Wordpress Filesystem error: ') . $wp_filesystem->errors->get_error_message() .
				' (' . $wp_filesystem->errors->get_error_code() . ')', self::LOG_WARNING );
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
		/* register setting link for the plugin page */
		if ( static::$network_activated )
			add_filter( 'network_admin_plugin_action_links_' . $this->plugin_file, array( &$this, 'plugin_settings_link' ) );
		else if (current_user_can( static::$capability_needed ))
			add_filter( 'plugin_action_links_' . $this->plugin_file, array( &$this, 'plugin_settings_link' ) );

		/* load additional moves */
		$this->plugin_extend_admin_init();		
	}

	/**
	 * admin menu called by WordPress add_action, needs to be public
	 */
	public function plugin_admin_menu() {
		/* add submenu to settings pages */
		$this->settings_page_hook_suffix = add_submenu_page( $this->settings_slug, $this->plugin_name . __( ' options' , 'wp-ffpc'), $this->plugin_name, static::$capability_needed, $this->plugin_settings_page, array ( &$this , 'plugin_admin_panel' ) );
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
	public function plugin_settings_link( $links ) {
		$settings_link = '<a href="' . $this->settings_link . '">' . __( 'Settings', 'wp-ffpc') . '</a>';
		array_unshift( $links, $settings_link );
		return $links;
	}

	/* add admin styling */
	public function enqueue_admin_css_js() {
		/* jquery ui tabs is provided by WordPress */
		wp_enqueue_script( 'jquery-ui-tabs', false, array('jquery', 'jquery-ui-core' ) );
		wp_enqueue_script( 'jquery-ui-slider', false, array('jquery') );
		/* additional admin styling */
		wp_register_style( $this->admin_css_handle, $this->admin_css_url, false, $this->plugin_version, 'all' );
		wp_enqueue_style( $this->admin_css_handle );
	}

	/**
	 * deletes saved options from database
	 */
	protected function plugin_options_delete() {
		static::_delete_option ( $this->plugin_constant, static::$network_activated );

		/* additional moves */
		$this->plugin_extend_options_delete();
	}

	/**
	 * hook to add functionality into plugin_options_delete
	 */
	abstract function plugin_extend_options_delete();

	/**
	 * reads options stored in database and reads merges them with default values
	 */
	protected function plugin_options_read() {
		$options = static::_get_option( $this->plugin_constant, static::$network_activated );

		/* this is the point to make any migrations from previous versions */
		$this->plugin_options_migrate( $options );

		if ( empty( $options ) ) {
			$options = $this->defaults;
		}
		else {
			/* map missing values from default */
			foreach ( $this->defaults as $key => $default )
				if ( !array_key_exists ( $key, $options ) )
					$options[$key] = $default;

			/* removed unused keys, rare, but possible */
			foreach ( array_keys ( $options ) as $key )
				if ( !array_key_exists( $key, $this->defaults ) )
					unset ( $options[$key] );
		}

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
	abstract function plugin_extend_options_read( &$options );

	/**
	 * used on update and to save current options to database
	 *
	 * @param boolean $activating [optional] true on activation hook
	 *
	 */
	// TODO make config store an array for nocache_cookies because string parsing, array create, etc. is very expensive to do on every cache request
	// and add validation like expire and TTL values should be numbers which are zero or greater
	protected function plugin_options_save( $activating = false ) {

		/* only try to update defaults if it's not activation hook, $_POST is not empty and the post is ours */
		if ( !$activating && !empty ( $_POST ) && isset( $_POST[ $this->button_save ] ) ) {
			/* we'll only update those that exist in the defaults array */
			$options = $this->defaults;

			foreach ( $options as $key => $default )
			{
				/* $_POST element is available */
				if ( !empty( $_POST[$key] ) ) {
					$update = $_POST[$key];

					/* get rid of slashes in strings, just in case */
					// TODO investigate this stripslashes(); seems an inconsistent hack for something unknown like the removed legacy magic_quotes_gpc
					if ( is_string ( $update ) ) {
						$update = trim(stripslashes($update));
					}

					$options[$key] = $update;
				}
				/* empty $_POST element: when HTML form posted, empty checkboxes a 0 input
				   values will not be part of the $_POST array, thus we need to check
				   if this is the situation by checking the types of the elements,
				   since a missing value means update from an integer to 0
				*/
				elseif ( is_bool( $default ) || is_int($default) ) {
					$options[$key] = 0;
				}
				elseif ( is_array($default) ) {
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
		static::_update_option (  $this->plugin_constant , $this->options, static::$network_activated );
	}

	/**
	 * hook to add functionality into plugin_options_save
	 */
	abstract function plugin_extend_options_save( $activating );

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
	protected function print_default( $e ) {
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
	protected function print_select_options( $elements, $current, $valid = false, $print = true ) {

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
	 * jQuery slider only exists in Wordpress 3.2+
	 */
	protected function plugin_donation_form() {
		if ( $this->donation ) :
		?>
		<script>
			jQuery(document).ready(function($) {
				jQuery(function() {
					if (!jQuery().slider) return;
					var select = $( '#amount' );
					var slider = $( '<div id="donation-slider"></div>' ).insertAfter( select ).slider({
						min: 1,
						max: 8,
						range: "min",
						value: select[ 0 ].selectedIndex + 1,
						slide: function( event, ui ) {
							select[ 0 ].selectedIndex = ui.value - 1;
						}
					});
					$( '#amount' ).change(function() {
						slider.slider( 'value', this.selectedIndex + 1 );
					});
				});
			});
		</script>

		<form action="https://www.paypal.com/cgi-bin/webscr" method="post" class="<?php echo $this->plugin_constant ?>-donation">
			<label for="amount"><?php _e( 'This plugin helped your business? I\'d appreciate a coffee in return :) Please!', 'wp-ffpc'); ?></label>
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

	public function getoption( $key ) {
		return ( empty (  $this->options[ $key] ) ) ? false :  $this->options[ $key];
	}


	/**
	 * UTILS
	 */
	/**
	 * option update; will handle network wide or standalone site options
	 *
	 */
	public static function _update_option( $optionID, $data, $network = false ) {
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
	public static function _get_option( $optionID, $network = false ) {
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
	public static function _delete_option( $optionID, $network = false ) {
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
	 */
	static function debug( $message, $level = self::LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);


		switch ( $level ) {
			case self::LOG_ERR :
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
	 * @param string $msg error message
	 * @param string $error "level" of error
	 * @param boolean $network_activated alert displayed for network admin only
	 * 
	 */
	static public function alert( $msg, $level=self::LOG_WARNING, $network_activated=NULL ) {
		//if ( php_sapi_name() === "cli" ) return false;
		if ( empty($msg)) return false;

		// check for deprecated LOG constants; must use self::LOG_*** instead
		// TODO remove this when devs consistently use new constants
		if ($level < self::LOG_EMERG) {
			$callstack = debug_backtrace(false);
			error_log($callstack[1]['function'] . '() called WP_FFPC_ABSTRACT::alert() with deprecated LOG_*** constant');
			$level = self::LOG_INFO;
		}

		// don't show notices to users that can't do anything about them
		// without this logic, it is possible for non-admins (e.g. subscribers) to see notices
		// on their user profile editing page because that page is considered an "admin" page
		if ( !current_user_can( static::$capability_needed ) ) return false;

		// select to which admin pages these notices are visible
		// network_activated=true means the superadmin network admin pages
		// network_activated=false means the rest of the admin pages (except for the single site user profile pages due to a WP bug)
		if (NULL === $network_activated) $network_activated = static::$network_activated;

		switch ($level) {
			case self::LOG_WARNING:
				//$css = "notice notice-warning";
				//break;
			case self::LOG_ERR:
				$css = "error notice notice-error";
				break;
			case self::LOG_NOTICE:
				$css = "updated notice notice-success";
				break;
			case self::LOG_INFO:
			default:
				$css = "updated notice notice-info";
				break;
		}

		$r = '<div class="'. $css .'"><p>'. $msg .'</p></div>';
		if ($network_activated)
			add_action('network_admin_notices', function() use ($r) { echo $r; });
		else
			add_action('admin_notices', function() use ($r) { echo $r; });
		static::debug( $msg, $level );
		return true;
	}

	// determine os
	static public function isWindows() {
		$uname = strtolower(php_uname());
		$retVal = false;
		if (false !== strpos($uname, "darwin")) {
			// It's OSX
		} else if (false !== strpos($uname, "cygwin")) {
			// It's Cygwin
		} else if (false !== strpos($uname, "win")) {
			// It's windows
			$retVal = true;
		} else if (false !== strpos($uname, "linux")) {
			// It's Linux
		} else {
			// It's something else e.g. Solaris, HPUX, etc.
		}
		return $retVal;
	}

	// workaround for ini_get inconsistent return values for boolean ini keys
	// probably better to compare the return result of this function with a double equals ==
	static public function ini_get_bool( $inikey ) {
		$inival = ini_get($inikey);

		switch (strtolower($inival))
		{
		case 'on':
		case 'yes':
		case 'true':
		case '1':
			return true;
		case 'off':
		case 'no':
		case 'false':
		case '0':
		case '':
			return false;
		default:
			return $inival;
		}
	}

	// copied from WP 4.6 because WP 3.6 and earlier versions had poor logic and bugs
	static protected function win_is_writable( $path ) {
	        if ( $path[strlen( $path ) - 1] == '/' ) { // if it looks like a directory, check a random file within the directory
	                return static::win_is_writable( $path . uniqid( mt_rand() ) . '.tmp');
	        } elseif ( is_dir( $path ) ) { // If it's a directory (and not a file) check a random file within the directory
	                return static::win_is_writable( $path . '/' . uniqid( mt_rand() ) . '.tmp' );
	        }
	        // check tmp file for read/write capabilities
	        $should_delete_tmp_file = !file_exists( $path );
	        $f = @fopen( $path, 'a' );
	        if ( $f === false )
	                return false;
	        fclose( $f );
	        if ( $should_delete_tmp_file )
	                unlink( $path );
	        return true;
	}
	// copied from WP 4.6 because WP 3.6 and earlier versions had poor logic and bugs
	static protected function wp_is_writable( $path ) {
    if ( 'WIN' === strtoupper( substr( PHP_OS, 0, 3 ) ) )
        return static::win_is_writable( $path );
    else
        return @is_writable( $path );
	}
	// copied from WP 4.6 because WP 3.6 and earlier versions had poor logic and bugs
	static protected function get_temp_dir() {
		static $temp = '';
		if ( defined('WP_TEMP_DIR') )
				return trailingslashit(WP_TEMP_DIR);
		if ( $temp )
				return trailingslashit( $temp );
		if ( function_exists('sys_get_temp_dir') ) {
				$temp = sys_get_temp_dir();
				if ( @is_dir( $temp ) && static::wp_is_writable( $temp ) )
						return trailingslashit( $temp );
		}
		$temp = ini_get('upload_tmp_dir');
		if ( @is_dir( $temp ) && static::wp_is_writable( $temp ) )
				return trailingslashit( $temp );
		$temp = WP_CONTENT_DIR . '/';
		if ( is_dir( $temp ) && static::wp_is_writable( $temp ) )
				return $temp;
		return '/tmp/';
	}

}

endif;
