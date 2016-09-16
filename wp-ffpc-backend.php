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

// Workaround for Wordpress 3.0
if ( !function_exists('get_current_blog_id') )
{
	function get_current_blog_id() {
		global $blog_id;
		return absint($blog_id);
	}
}

/* this is the base class for all backends; the actual workers
 * are included at the end of the file from backends/ directory */

if (!class_exists('WP_FFPC_Backend')) :

// array of cookies to look for when looking for authenticated users
// it is a hack to store them in this file, however this is the only file shared in common w/ the admin option codebase
$wp_ffpc_auth_cookies = array ( 'comment_author_' , 'wordpressuser_' , 'wp-postpass_', 'wordpress_logged_in_' );

abstract class WP_FFPC_Backend {

	const host_separator  = ',';
	const port_separator  = ':';
	const LOG_INFO = 106;		// consts for alert mechanism; can't use LOG_*** constants because Windows PHP duplicates five of the values in PHP 5.5.12
	const LOG_NOTICE = 105;
	const LOG_WARNING = 104;
	const LOG_ERR = 103;
	const LOG_CRIT = 102;
	const LOG_ALERT = 101;
	const LOG_EMERG = 100;

	protected $connection = NULL;
	protected $alive = false;
	protected $options = array();
	protected $status = array();
	public $cookies = array();
	protected $urimap = array();

	/**
	* constructor
	*
	* @param mixed $config Configuration options
	*
	*/
	public function __construct( $config ) {

		/* no config, nothing is going to work */
		if ( empty ( $config ) ) {
			return false;
			//die ( __translate__ ( 'WP-FFPC Backend class received empty configuration array, the plugin will not work this way', 'wp-ffpc') );
		}

		$this->options = $config;

		/* map the key with the predefined schemes */
		$ruser = isset ( $_SERVER['REMOTE_USER'] ) ? $_SERVER['REMOTE_USER'] : '';
		$ruri = isset ( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$rhost = isset ( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		$scookie = isset ( $_COOKIE['PHPSESSID'] ) ? $_COOKIE['PHPSESSID'] : '';

		if (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && ($_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https'))
			$_SERVER['HTTPS'] = 'on';
		$scheme = (empty($_SERVER['HTTPS']) || ('off' === $_SERVER['HTTPS'])) ? 'http' : 'https';

		$this->urimap = array(
			'$scheme' => $scheme,
			'$host' => $rhost,
			'$request_uri' => $ruri,
			'$remote_user' => $ruser,
			'$cookie_PHPSESSID' => $scookie,
		);

		/* split single line hosts entry */
		$this->set_servers();

		/* info level */
		$this->log (  __translate__('init starting', 'wp-ffpc'));

		/* call backend initiator based on cache type */
		$init = $this->_init();

		if (is_admin() && function_exists('add_filter')) {
			add_filter('wp_ffpc_clear_keys_array', function($to_clear, $options) {
				$filtered_result = array();
				foreach ( $to_clear as $link => $dummy ) {
					/* clear feeds, meta and data as well */
					$filtered_result[ $options[ 'prefix_meta' ] . $link ] = true;
					$filtered_result[ $options[ 'prefix_data' ] . $link ] = true;
					$filtered_result[ $options[ 'prefix_meta' ] . $link . 'feed' ] = true;
					$filtered_result[ $options[ 'prefix_data' ] . $link . 'feed' ] = true;
				}
				return $filtered_result;
			}, 10, 2);
		}
	}

	/*
	 * @param string $uri
	 * @param mixed $default_urimap
	 */
	public static function parse_urimap($uri, $default_urimap=null) {
		$uri_parts = parse_url( $uri );

		$uri_map = array(
			'$scheme' => $uri_parts['scheme'],
			'$host' => $uri_parts['host'],
			'$request_uri' => $uri_parts['path']
		);

		if (is_array($default_urimap)) {
			$uri_map = array_merge($default_urimap, $uri_map);
		}

		return $uri_map;
	}

	/**
	 * @param array $urimap
	 * @param string $subject
	 */
	public static function map_urimap($urimap, $subject) {
		return str_replace(array_keys($urimap), $urimap, $subject);
	}


	/**
	 * build key to make requests with
	 *
	 * @param string $prefix prefix to add to prefix
	 * @param array $customUrimap to override defaults
	 *
	 */
	public function key ( $prefix, $customUrimap = null ) {
		$urimap = $customUrimap ?: $this->urimap;

		$key_base = self::map_urimap($urimap, $this->options['key']);

		if (( isset($this->options['hashkey']) && $this->options['hashkey'] == true) || $this->options['cache_type'] === 'redis' )
			$key_base = sha1($key_base);

		$key = $prefix . $key_base;

		$this->log( __translate__("original key configuration: {$this->options['key']}", 'wp-ffpc') );
		$this->log( __translate__("setting key for: $key_base", 'wp-ffpc') );
		$this->log( __translate__("setting key to: $key", 'wp-ffpc') );
		return $key;
	}


	/**
	 * public get function, transparent proxy to internal function based on backend
	 *
	 * @param string $key Cache key to get value for
	 *
	 * @return mixed False when entry not found or entry value on success
	 */
	public function get ( &$key ) {
		/* look for backend aliveness, exit on inactive backend */
		if ( ! $this->is_alive() ) {
			$this->log ('WARNING: Backend offline');
			return false;
		}

		/* log the current action */
		$this->log( __translate__("get entry: $key", 'wp-ffpc') );

		$result = $this->_get( $key );

		if ( $result === false || $result === null )
			$this->log( __translate__("failed to get entry: $key", 'wp-ffpc') );

		return $result;
	}

	/**
	 * public set function, transparent proxy to internal function based on backend
	 *
	 * @param string $key Cache key to set with ( reference only, for speed )
	 * @param mixed $data Data to set ( reference only, for speed )
	 * @param optional param TTL (time to live) in seconds
	 * BUGBUG there is incompatible different handling of the TTL value in the backend
	 *        implementations, e.g. http://php.net/manual/en/memcached.expiration.php
	 *
	 * @return mixed $result status of set function
	 */
	public function set ( &$key, &$data, $expire = false ) {
		/* look for backend aliveness, exit on inactive backend */
		// TODO re-evaluate this alive check. Doing it causes a 2x increase in cache traffic isalive() + set()
		// it might be better to just do a set() since they both will return false
		if ( ! $this->is_alive() )
			return false;

		/* expiration time is optional parameter value or based on type */
		// BUGBUG empty() logic is inconsistent below
		// BUGBUG using is_home() functions here is poor style and descreases perf; the expire should be passed into this function
		if ( false === $expire ) {
			if (( is_home() || is_feed() ) && isset($this->options['expire_home']))
				$expire = (int) $this->options['expire_home'];
			elseif (( is_tax() || is_category() || is_tag() || is_archive() ) && isset($this->options['expire_taxonomy']))
				$expire = (int) $this->options['expire_taxonomy'];
			else
				$expire = empty ( $this->options['expire'] ) ? 0 : $this->options['expire'];
		}

		/* log the current action */
		$this->log( __translate__("set entry: $key expire: $expire", 'wp-ffpc') );
		/* proxy to internal function */
		$result = $this->_set( $key, $data, $expire );

		/* check result validity */
		if ( $result === false || $result === null )
			$this->log( __translate__("failed to set entry: $key", 'wp-ffpc'), self::LOG_WARNING );

		return $result;
	}

	/*
	 * next generation clean
	 *
	 *
	 */
	public function clear_ng ( $new_status, $old_status, $post ) {
		$this->clear ( $post->ID );
	}

	/**
	 * public get function, transparent proxy to internal function based on backend
	 *
	 * @param string $post_id	ID of post to invalidate
	 * @param boolean $force 	Force flush cache
	 *
	 */
	public function clear ( $post_id = false, $force = false ) {

		/* look for backend aliveness, exit on inactive backend */
		if ( ! $this->is_alive() )
			return false;

		/* exit if no post_id is specified */
		if ( empty ( $post_id ) && $force === false ) {
			$this->log (  __translate__('not clearing unidentified post ', 'wp-ffpc'), self::LOG_WARNING );
			return false;
		}

		/* if invalidation method is set to full, flush cache */
		if ( ( $this->options['invalidation_method'] === 0 || $force === true ) ) {
			/* log action */
			$this->log (  __translate__('flushing cache', 'wp-ffpc') );

			/* proxy to internal function */
			$result = $this->_flush();

			if ( $result === false )
				$this->log (  __translate__('failed to empty cache', 'wp-ffpc'), self::LOG_WARNING );

			return $result;
		}

		/* storage for entries to clear */
		$to_clear = array();

		/* clear taxonomies if settings requires it */
		if ( $this->options['invalidation_method'] == 2 ) {
			/* this will only clear the current blog's entries */
			$this->taxonomy_links( $to_clear );
		}

		/* clear pasts index page if settings requires it */
		if ( $this->options['invalidation_method'] == 3 ) {
			$posts_page_id = get_option( 'page_for_posts' );
			$post_type = get_post_type( $post_id );

			if ($post_type === 'post' && $posts_page_id != $post_id) {
				$this->clear($posts_page_id, $force);
			}
		}


		/* if there's a post id pushed, it needs to be invalidated in all cases */
		if ( !empty ( $post_id ) ) {

			/* need permalink functions */
			// BUGBUG this is unusual because we use many core WP functions above yet don't 
			// test for them nor do includes; the chance that get_option() works above yet
			// get_permalink() doesn't work is almost zero
			if ( !function_exists('get_permalink') )
				include_once ABSPATH . 'wp-includes/link-template.php';

			/* get permalink */
			$permalink = get_permalink( $post_id );

			/* no path, don't do anything */
			if ( empty( $permalink ) && $permalink != false ) {
				$this->log( __translate__("unable to determine path from Post Permalink, post ID: $post_id", 'wp-ffpc'), self::LOG_WARNING );
				return false;
			}

			/*
			 * It is possible that post/page is paginated with <!--nextpage-->
			 * Wordpress doesn't seem to expose the number of pages via API.
			 * So let's just count it.
			 */
			$content_post = get_post( $post_id );
			$content = $content_post->post_content;
			$number_of_pages = 1 + (int)preg_match_all('/<!--nextpage-->/', $content, $matches);

			$current_page_id = '';
			do {
				/* urimap */
				$urimap = self::parse_urimap($permalink, $this->urimap);
				$urimap['$request_uri'] = $urimap['$request_uri'] . ($current_page_id ? $current_page_id . '/' : '');

				$clear_cache_key = self::map_urimap($urimap, $this->options['key']);

				$to_clear[ $clear_cache_key ] = true;

				$current_page_id = 1+(int)$current_page_id;
			} while ($number_of_pages>1 && $current_page_id<=$number_of_pages);
		}

		/* Hook to custom clearing array. */
		$to_clear = apply_filters('wp_ffpc_to_clear_array', $to_clear, $post_id);

		/* run clear */
		$this->clear_keys( $to_clear );
	}

	/*
	 * unset entries by key
	 * @param array $keys
	 */
	public function clear_keys( $keys ) {
		$to_clear = apply_filters('wp_ffpc_clear_keys_array', $keys, $this->options);
		$this->_clear ( $to_clear );
	}

	/**
	 * clear cache triggered by new comment
	 *
	 * @param $comment_id	Comment ID
	 * @param $comment_object	The whole comment object ?
	 */
	public function clear_by_comment ( $comment_id, $comment_object ) {
		if ( empty( $comment_id ) )
			return false;

		$comment = get_comment( $comment_id );
		$post_id = $comment->comment_post_ID;
		if ( !empty( $post_id ) )
			$this->clear ( $post_id );

		unset ( $comment );
		unset ( $post_id );
	}

	/**
	 * to collect all permalinks of all taxonomy terms used in invalidation & precache
	 *
	 * @param array &$links Passed by reference array that has to be filled up with the links
	 * @param mixed $site Site ID or false; used in WordPress Network
	 *
	 */
	public function taxonomy_links ( &$links, $site = false ) {

		if ( $site !== false ) {
			$current_blog = get_current_blog_id();
			switch_to_blog( $site );

			$url = get_blog_option ( $site, 'siteurl' );
			if ( substr( $url, -1) !== '/' )
				$url = $url . '/';

			$links[ $url ] = true;
		}

		/* we're only interested in public taxonomies */
		$args = array(
			'public'   => true,
		);

		/* get taxonomies as objects */
		$taxonomies = get_taxonomies( $args, 'objects' );

		if ( !empty( $taxonomies ) ) {
			foreach ( $taxonomies  as $taxonomy ) {
				/* reset array, just in case */
				$terms = array();

				/* get all the terms for this taxonomy, only if not empty */
				$sargs = array(
					'hide_empty'    => true,
					'fields'        => 'all',
					'hierarchical'  =>false,
				);
				$terms = get_terms ( $taxonomy->name , $sargs );

				if ( !empty ( $terms ) ) {
					foreach ( $terms as $term ) {

						/* skip terms that have no post associated and somehow slipped
						 * throught hide_empty */
						if ( $term->count === 0)
							continue;

						/* get the permalink for the term */
						$link = get_term_link ( $term->slug, $taxonomy->name );
						$links[ $link ] = true;

						/* remove the taxonomy name from the link, lots of plugins remove this for SEO, it's better to include them than leave them out in worst case, we cache some 404 as well
						 * BUGBUG this hack needs to be reviewed since the caching backend should always
						 * store and lookup internally using canonical urls. SEO changed/duplicated external URLs
						 * should always resolve to the same canonical urls and therefore not need these hacked
						 * precache crawls
						*/
						// check that we have a rewrite for pretty permalinks; if yes, then remove the slug as per hack
						if (isset($taxonomy->rewrite['slug'])) {
							$link = str_replace ( '/' . $taxonomy->rewrite['slug'] . '/', '/', $link  );
							$links[ $link ] = true;
						}
					}
				}
			}
		}

		/* switch back to original site if we navigated away */
		// BUGBUG not correctly restoring original site; see https://codex.wordpress.org/Function_Reference/restore_current_blog
		if ( $site !== false ) {
			switch_to_blog( $current_blog );
		}

	}

	/**
	 * get backend aliveness
	 *
	 * @return array Array of configured servers with aliveness value
	 *
	 */
	public function status () {

		/* look for backend aliveness, exit on inactive backend */
		if ( ! $this->is_alive() )
			return false;

		$internal = $this->_status();
		return $this->status;
	}

	/**
	 * function to check backend aliveness
	 *
	 * @return boolean true if backend is alive, false if not
	 *
	 */
	protected function is_alive() {
		if ( ! $this->alive ) {
			$this->log (  __translate__('backend is not active, exiting function ', 'wp-ffpc') . __FUNCTION__, self::LOG_WARNING );
			return false;
		}

		return true;
	}

	/**
	 * split hosts string to backend servers
	 *
	 *
	 */
	protected function set_servers () {
		if ( empty ($this->options['hosts']) )
			return false;

		/* replace servers array in config according to hosts field */
		$servers = explode( self::host_separator , $this->options['hosts']);

		$options['servers'] = array();

		foreach ( $servers as $snum => $sstring ) {

			if ( stristr($sstring, 'unix://' ) ) {
				$host = str_replace('unix:/','',$sstring);
				$port = 0;
			}
			else {
				$separator = strpos( $sstring , self::port_separator );
				$host = substr( $sstring, 0, $separator );
				$port = substr( $sstring, $separator + 1 );
			}

			$this->options['servers'][$sstring] = array (
				'host' => $host,
				'port' => $port
			);
		}

	}

	/**
	 * get current array of servers
	 *
	 * @return array Server list in current config
	 *
	 */
	public function get_servers () {
		$r = isset ( $this->options['servers'] ) ? $this->options['servers'] : '';
		return $r;
	}

	/**
	 * log wrapper to include options
	 *
	 * @var mixed $message Message to log
	 * @var int $log_level Log level
	 */
	protected function log ( $message, $level = self::LOG_NOTICE ) {
		if ( @is_array( $message ) || @is_object ( $message ) )
			$message = json_encode($message);

		// check for deprecated LOG constants; must use self::LOG_*** instead
		// TODO remove this when devs consistently use new constants
		if ($level < self::LOG_EMERG) {
			$callstack = debug_backtrace(false);
			error_log($callstack[1]['function'] . '() called WP_FFPC_Backend::log() with deprecated LOG_*** constant');
			if (LOG_ERR === $level)
				$level = self::LOG_ERR;
		}

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


	abstract protected function _init ();
	abstract protected function _status ();
	abstract protected function _get ( &$key );
	abstract protected function _set ( &$key, &$data, &$expire );
	abstract protected function _flush ();
	abstract protected function _clear ( &$keys );
}

endif;

// TODO try to replace the below with config-specified loading in acache
// while loading all in option pages
include_once __DIR__ . '/backends/apc.php';
include_once __DIR__ . '/backends/apcu.php';
include_once __DIR__ . '/backends/memcache.php';
include_once __DIR__ . '/backends/memcached.php';
include_once __DIR__ . '/backends/redis.php';
