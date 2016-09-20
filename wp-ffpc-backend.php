<?php

/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
if ( !function_exists('__translate__') ) {
	/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
	if ( function_exists( '__' ) ) {
		function __translate__( $text, $domain ) { return __($text, $domain); }
	}
	else {
		function __translate__( $text, $domain ) { return $text; }
	}
}

/* this is the base class for all backends; the actual workers
 * are included at the end of the file from backends/ directory */

if (!class_exists('WP_FFPC_Backend')) :

// array of cookies to look for when looking for authenticated users
// it is a hack to store them in this file, however this is the only file shared in common w/ the admin option codebase
$wp_ffpc_auth_cookies = array( 'comment_author_' , 'wordpressuser_' , 'wp-postpass_', 'wordpress_logged_in_' );

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
		if ( empty( $config ) ) {
			return false;
			//die ( __translate__ ( 'WP-FFPC Backend class received empty configuration array, the plugin will not work this way', 'wp-ffpc') );
		}

		$this->options = $config;

		/* map the key with the predefined schemes */
		$ruser = isset( $_SERVER['REMOTE_USER'] ) ? $_SERVER['REMOTE_USER'] : '';
		$ruri = isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : '';
		$rhost = isset( $_SERVER['HTTP_HOST'] ) ? $_SERVER['HTTP_HOST'] : '';
		$scookie = isset( $_COOKIE['PHPSESSID'] ) ? $_COOKIE['PHPSESSID'] : '';

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
		$this->log(  __translate__('init starting', 'wp-ffpc'));

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
	public function key( $prefix, $customUrimap = null ) {
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
	public function get( &$key ) {
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
	public function set( &$key, &$data, $expire = false ) {
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

	// clean by transition hook
	// custom post types https://codex.wordpress.org/Post_Types#Custom_Post_Types_in_the_Main_Query
	public function clear_post_on_transition( $new_status, $old_status, &$post ) {
		error_log("clear_post_on_transition() n={$new_status} o={$old_status} t={$post->post_type} i={$post->ID}");
		// ignore revisions and nav menus
		if ( ('revision' === $post->post_type) || ('nav_menu_item' === $post->post_type) ) 
			return;
		// clear cache of old content so newly saved content will be cached
		if ( ('publish' === $new_status) && ('publish' === $old_status) )
			$this->clear( $post->ID );
		// clear cache of the no longer published content
		if ( ('publish' !== $new_status) && ('publish' === $old_status) )
			// BUGBUG need to get links for just 

		// ignore private (because you have to be logged in to see it) and draft content
		if ( ('private' === $new_status) || ('draft' === $new_status) )
			return;
	}

	// cache clean by hook callback as post/page/attach moves out of the publish state
	// TODO add status=private and password status=publish handling
	// add custom post types https://codex.wordpress.org/Post_Types#Custom_Post_Types_in_the_Main_Query
	public function clear_post_on_depublish( $post_id, $post_after, $post_before ) {
		// ignore revisions and nav menus
		if ( ('revision' === $post_before->post_type) || ('nav_menu_item' === $post_before->post_type) ) 
			return;
		// clear cache for existing in-place edits, clear cache for depublishes/trash/redraft, ignore new publishes
		if ('publish' === $post_before->post_status) {
			if ('publish' === $post_after->post_status) {
				$this->clear( $post_before->ID );
			}
			else {
				// BUGBUG need to create shared function to use a wp_post to get the permalink and then the cache key
				$depuburl = get_permalink($post_before);
				error_log("depublish={$depuburl}");
			}
		}
	}

	// clear cache for posts/attachments/pages that were not already in the trash
	// TODO add status=private and password status=publish handling
	private static $delete_queue = array();
	public function clear_post_before_forcedelete( $post_id ) {
		error_log('clear_post_before_forcedelete()');
		// ignore duplicate calls that can occur in WP 3.x
		if ( isset(self::$delete_queue[$post_id]) )
			return;
		// ignore posts that are not currently published
		$post_status = get_post_status( $post_id );
		if ('publish' !== $post_status)
			return;
		// get the post object for this id
		if ( !$post_before = get_post( $post_id ) )
			return;
		// ignore revisions and nav menus
		if ( ('revision' === $post_before->post_type) || ('nav_menu_item' === $post_before->post_type) ) 
			return;
		// save into the delete queue
		self::$delete_queue[$post_id] = $post_before; 
		error_log('before_dq=' . print_r(self::$delete_queue, true));
	}

	public function clear_post_after_forcedelete( $post_id ) {
		error_log('clear_post_after_forcedelete()');
		// ignore duplicate calls that can occur in WP 3.x and completed deletes
		if ( empty(self::$delete_queue[$post_id]) )
			return;
		// BUGBUG need to create shared function to use a wp_post to get the permalink and then the cache key
		$depuburl = get_permalink(self::$delete_queue[$post_id]);
		error_log("forcedelete={$depuburl}");
		self::$delete_queue[$post_id] = false;
		error_log('after_dq=' . print_r(self::$delete_queue, true));
	}

	/**
	 * public clear function, transparent proxy to internal function based on backend
	 *
	 * @param string $post_id	ID of post to invalidate
	 * @param boolean $force 	Force flush cache
	 *
	 */
	// BUGBUG on transitions, often getting revisions which have links like: http://centos6/2016/09/26-revision-4/
	public function clear( $post_id = false, $force = false ) {
		/* exit if no post_id is specified */
		if ( !is_int($post_id) && (true !== $force ) ) {
			$this->log( __translate__('not clearing unidentified post ', 'wp-ffpc'), self::LOG_WARNING );
			return false;
		}

		/* look for backend aliveness, exit on inactive backend */
		if ( !$this->is_alive() )
			return false;

		/* if invalidation method is set to full flush cache; intentionally test against integer 0 */
		if ( (true === $force) || ($this->options['invalidation_method'] == 0) ) {
			/* log action */
			$this->log( __translate__('flushing cache', 'wp-ffpc') );

			/* proxy to internal function */
			$result = $this->_flush();
			if ( $result === false )
				$this->log( __translate__('failed to empty cache', 'wp-ffpc'), self::LOG_WARNING );
			return $result;
		}

		/* storage for entries to clear */
		$to_clear = array();

		// clear taxonomies and archives of the blog; intentionally test against string '2'
		if ( $this->options['invalidation_method'] == '2' ) {
			// TODO only clear the taxonomies and archives of the new post and (if edited) the old post, e.g. get_month_link()
			$this->taxonomy_links( $to_clear );
			$this->archive_links( $to_clear );
		}
		// clear the blog page (i.e. posts ìndex) which can be different than the home page; intentionally test against string '3'
		// BUGBUG need to port this logic to use the further below uri mapping
		elseif ( $this->options['invalidation_method'] == '3' ) {
			$post_type = get_post_type( $post_id );
			if ($post_type === 'post') {
				if ('posts' === get_option('show_on_front')) {
					$to_clear[ trailingslashit(home_url()) ] = true;
				}
				else {	// front is something else, e.g. static page
					$posts_page_id = (int)get_option('page_for_posts');
					if ($posts_page_id)
						// BUGBUG this doesn't handle pagination; to know which/all pages of the blog to clear
						// $count_posts = wp_count_posts();
						$to_clear[ trailingslashit(get_permalink($posts_page_id)) ] = true;	// get_page_link($posts_page_id);
				}
			}
		}

		/* get permalink */
		// BUGBUG if post is a draft (e.g. publish transitioned back to draft), then the permalink is 
		// not pretty; it is instead a query string 
		$permalink = get_permalink( $post_id );

		/* no path, don't do anything */
		if ( empty($permalink) ) {
			$this->log( __translate__("unable to determine path from Post Permalink, post ID: $post_id", 'wp-ffpc'), self::LOG_WARNING );
			return false;
		}

		/*
			* It is possible that post/page is paginated with <!--nextpage-->
			* Wordpress doesn't seem to expose the number of pages via API.
			* So let's just count it.
			* BUGBUG need to re-evaluate this method of invalidating paged content because
			*        highly expensive in computation; also inaccurate because when a post is edited,
			*        the old content and new content could have different number of pages
			*/
		$content_post = get_post( $post_id );
		$content = $content_post->post_content;
		$number_of_pages = 1 + (int)preg_match_all('/<!--nextpage-->/', $content, $matches);
		$urimap_init = self::parse_urimap($permalink, $this->urimap);
		$current_page_id = 0;
		do {
			$urimap = $urimap_init;
			$urimap['$request_uri'] = $urimap['$request_uri'] . ($current_page_id ? $current_page_id . '/' : '');
			$clear_cache_key = self::map_urimap($urimap, $this->options['key']);
			$to_clear[ $clear_cache_key ] = true;
			++$current_page_id;
		} while ( ($number_of_pages > 1) && ($current_page_id <= $number_of_pages) );

		/* run clear */
		$this->clear_keys( $to_clear );
		error_log('clear='.print_r($to_clear, true));
	}

	/*
	 * unset entries by key
	 * @param array $keys
	 */
	public function clear_keys( &$keys ) {
		// filter hook used by other plugins like WP-FFPC-Purge https://github.com/zeroturnaround/wp-ffpc-purge
		$to_clear = apply_filters('wp_ffpc_clear_keys_array', $keys, $this->options);
		$this->_clear( $to_clear );
	}

	/**
	 * clear cache triggered by new comment
	 *
	 * @param $comment_id	Comment ID
	 * @param $comment_object	The whole comment object ?
	 */
	public function clear_by_comment( $comment_id, $comment_object ) {
		if ( empty( $comment_id ) )
			return false;
		$comment = get_comment( $comment_id );
		$post_id = $comment->comment_post_ID;
		$this->clear( $post_id );
	}

	/**
	 * to collect all permalinks of all taxonomy terms used in invalidation & precache
	 *
	 * @param array &$links: Passed by reference array that has to be filled up with the links
	 * @param mixed $switch_blog_id: WP blog id (integer) or false
	 *
	 */
	public function taxonomy_links( &$links, $switch_blog_id = false ) {
		// if a switch_blog_id was provided, save current blog and change to the other site
		if ( (false !== $switch_blog_id) && (get_current_blog_id() !== $switch_blog_id) )
			switch_to_blog( $switch_blog_id );
		
		// add home page
		$links[ trailingslashit(home_url()) ] = true;

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

		/* switch back to original site */
		if ( $switch_blog_id !== false ) {
			restore_current_blog();
		}
	}

	// add post archive pages by month and year to links array
	// $links: should be an array; passing something else could cause faults
	// $switch_blog_id: optional and if provided should be an integer WP blog_id
	// TODO add archives for categories, tags, authors, date, custom posts, custom taxonomies
	// e.g. wp_list_...: categories(), authors(), pages(), bookmarks(), comments(), wp_tag_cloud(), etc.
	public function archive_links( &$links, $switch_blog_id = false ) {
		// if a switch_blog_id was provided, save current blog and change to the other site
		if ( (false !== $switch_blog_id) && (get_current_blog_id() !== $switch_blog_id) )
			switch_to_blog( $switch_blog_id );
		
		// get_post_type_archive_link('post');
		// get_month_link()
		$args = array (
			'type' => 'monthly',
			'format' => 'custom',
			'before' => '',
			'after' => '',
			'echo' => false
		);
		if ( preg_match_all( '`[\'"](https?:[^\'"]+)`i', wp_get_archives( $args ), $archives) ) {
			$links = array_merge( $links, array_flip(array_map('html_entity_decode', $archives[1])) );
		}
		unset($archives);
		$args['type'] = 'yearly';
		if ( preg_match_all( '`[\'"](https?:[^\'"]+)`i', wp_get_archives( $args ), $archives) ) {
			$links = array_merge( $links, array_flip(array_map('html_entity_decode', $archives[1])) );
		}

		/* switch back to original site */
		if ( $switch_blog_id !== false ) {
			restore_current_blog();
		}
	}

	/**
	 * get backend aliveness
	 *
	 * @return array Array of configured servers with aliveness value
	 *
	 */
	public function status() {

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
	protected function set_servers() {
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
	public function get_servers() {
		$r = isset ( $this->options['servers'] ) ? $this->options['servers'] : '';
		return $r;
	}

	/**
	 * log wrapper to include options
	 *
	 * @var mixed $message Message to log
	 * @var int $log_level Log level
	 */
	protected function log( $message, $level = self::LOG_NOTICE ) {
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


	abstract protected function _init();
	abstract protected function _status();
	abstract protected function _get( &$key );
	abstract protected function _set( &$key, &$data, &$expire );
	abstract protected function _flush();
	abstract protected function _clear( &$keys );
}

endif;

// TODO try to replace the below with config-specified loading in acache
// while loading all in option pages
include_once __DIR__ . '/backends/apc.php';
include_once __DIR__ . '/backends/apcu.php';
include_once __DIR__ . '/backends/memcache.php';
include_once __DIR__ . '/backends/memcached.php';
include_once __DIR__ . '/backends/redis.php';
