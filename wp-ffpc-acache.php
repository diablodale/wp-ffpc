<?php
/**
 * advanced cache worker of WordPress plugin WP-FFPC
 */

if ( !function_exists ('__debug__') ) {
	/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
	function __debug__ ( $text ) {
		if ( defined('WP_FFPC__DEBUG_MODE') && WP_FFPC__DEBUG_MODE == true)
			error_log ( __FILE__ . ': ' . $text );
	}
}

/* check for config */
if (!isset($wp_ffpc_config))
	return false;

/* check if config is network active: use network config */
if (!empty ( $wp_ffpc_config['network'] ) )
	$wp_ffpc_config = $wp_ffpc_config['network'];
/* check if config is active for site : use site config */
elseif ( !empty ( $wp_ffpc_config[ $_SERVER['HTTP_HOST'] ] ) )
	$wp_ffpc_config = $wp_ffpc_config[ $_SERVER['HTTP_HOST'] ];
/* plugin config not found :( */
else {
	unset($GLOBALS['wp_ffpc_config']);
	return false;
}

/* no cache for post request (comments, plugins and so on) */
if ($_SERVER["REQUEST_METHOD"] == 'POST')
	return false;

/**
 * Try to avoid enabling the cache if sessions are managed
 * with request parameters and a session is active
 */
if (defined('SID') && SID != '')
	return false;

/* request uri */
$wp_ffpc_uri = $_SERVER['REQUEST_URI'];

/* no cache for robots.txt */
if ( 0 === strncasecmp($wp_ffpc_uri, '/robots.txt', 11) ) {
	__debug__ ( 'Skippings robots.txt hit');
	return false;
}

/* multisite files can be too large for memcached */
// https://codex.wordpress.org/Multisite_Network_Administration#Uploaded_File_Path
if (is_multisite() && (0 === @substr_compare($_SERVER['SCRIPT_NAME'], '/ms-files.php', -13, 13, true))) {
	__debug__ ( 'Skipping multisite legacy ms-files hit');
	return false;
}

/* no cache for uri with query strings, things usually go bad that way */
// TODO reevaluate this because this causes the default WP setup (non-pretty links) and default wp-ffpc config will not cache
if ( $wp_ffpc_config['nocache_dyn'] && (stripos($wp_ffpc_uri, '?') !== false) ) {
	__debug__ ( 'Dynamic url cache is disabled ( url with "?" ), skipping');
	return false;
}

/* check for cookies that will make us not cache the content, like logged in WordPress cookie */
if ( is_string($wp_ffpc_config['nocache_cookies']) && ('' !== $wp_ffpc_config['nocache_cookies']) ) {
	$nocache_cookies = array_map('trim',explode(",", $wp_ffpc_config['nocache_cookies'] ) );

	if ( !empty( $nocache_cookies ) ) {
		foreach ($_COOKIE as $n=>$v) {
			/* check for any matches to user-added cookies to no-cache */
			foreach ( $nocache_cookies as $nocache_cookie ) {
				if( strpos( $n, $nocache_cookie ) === 0 ) {
					__debug__ ( "Cookie exception matched: $n, skipping");
					return false;
				}
			}
		}
	}
}

// no cache for excluded URL patterns
if ( is_string($wp_ffpc_config['nocache_url']) ) {
	// TODO trim() is only needed for legacy advanced-cache.php files saved/created with whitespace; can micro-optimize by removing the trim() and combining if tests
	$wp_ffpc_config['nocache_url'] = trim($wp_ffpc_config['nocache_url']);
	if ('' !== $wp_ffpc_config['nocache_url']) {
		// TODO consider switching delimiter to one of these |`^ because # is very common in URLs therefore more likely to be searched
		$pattern = sprintf('#%s#i', $wp_ffpc_config['nocache_url']);
		if ( 1 === preg_match($pattern, $wp_ffpc_uri) ) {
			__debug__ ( "Cache exception based on URL regex pattern matched, skipping");
			return false;
		}
	}
}

/* canonical redirect storage */
$wp_ffpc_redirect = null;
/* fires up the backend storage array with current config */
include_once ('wp-ffpc-backend.php');
$backend_class = 'WP_FFPC_Backend_' . $wp_ffpc_config['cache_type'];
$wp_ffpc_backend = new $backend_class ( $wp_ffpc_config );

//$wp_ffpc_backend = new WP_FFPC_Backend( $wp_ffpc_config );

/* no cache for for logged in users unless it's set
   identifier cookies are listed in backend as var for easier usage
*/
if ( !isset($wp_ffpc_config['cache_loggedin']) || $wp_ffpc_config['cache_loggedin'] == 0 || empty($wp_ffpc_config['cache_loggedin']) ) {

	foreach ($_COOKIE as $n=>$v) {
		foreach ( $wp_ffpc_backend->cookies as $nocache_cookie ) {
			if( strpos( $n, $nocache_cookie ) === 0 ) {
				__debug__ ( "No cache for cookie: $n, skipping");
				return false;
			}
		}
	}
}

/* will store time of page generation */
$wp_ffpc_gentime = 0;

/* backend connection failed, no caching :( */
if ( $wp_ffpc_backend->status() === false ) {
	__debug__ ( "Backend offline, skipping");
	return false;
}

/* try to get data & meta keys for current page */
$wp_ffpc_keys = array ( 'meta' => $wp_ffpc_config['prefix_meta'], 'data' => $wp_ffpc_config['prefix_data'] );
$wp_ffpc_values = array();

__debug__ ( "Trying to fetch entries");

foreach ( $wp_ffpc_keys as $internal => $key ) {
	$key = $wp_ffpc_backend->key ( $key );
	$value = $wp_ffpc_backend->get ( $key );

	if ( ! $value ) {
		/* does not matter which is missing, we need both, if one fails, no caching */
		wp_ffpc_start();
		return;
	}
	else {
		/* store results */
		$wp_ffpc_values[ $internal ] = $value;
		__debug__('Got value for ' . $internal);
	}
}

/* serve cache 404 status */
if ( isset( $wp_ffpc_values['meta']['status'] ) &&  $wp_ffpc_values['meta']['status'] == 404 ) {
	header("HTTP/1.1 404 Not Found");
	/* if I kill the page serving here, the 404 page will not be showed at all, so we do not do that
	 * flush();
	 * die();
	 */
}

/* server redirect cache */
if ( isset( $wp_ffpc_values['meta']['redirect'] ) && $wp_ffpc_values['meta']['redirect'] ) {
	header('Location: ' . $wp_ffpc_values['meta']['redirect'] );
	/* cut the connection as fast as possible */
	flush();
	die();
}

/* page is already cached on client side (chrome likes to do this, anyway, it's quite efficient) */
if ( array_key_exists( "HTTP_IF_MODIFIED_SINCE" , $_SERVER ) && !empty( $wp_ffpc_values['meta']['lastmodified'] ) ) {
	$if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER["HTTP_IF_MODIFIED_SINCE"]));
	/* check is cache is still valid */
	if ( $if_modified_since >= $wp_ffpc_values['meta']['lastmodified'] ) {
		header("HTTP/1.0 304 Not Modified");
		/* connection cut for faster serving */
		flush();
		die();
	}
}

/*** SERVING CACHED PAGE ***/

/* if we reach this point it means data was found & correct, serve it */
if (!empty ( $wp_ffpc_values['meta']['mime'] ) )
	header('Content-Type: ' . $wp_ffpc_values['meta']['mime']);

/* set expiry date */
if (isset($wp_ffpc_values['meta']['expire']) && !empty ( $wp_ffpc_values['meta']['expire'] ) ) {
	$hash = md5 ( $wp_ffpc_uri . $wp_ffpc_values['meta']['expire'] );

	switch ($wp_ffpc_values['meta']['type']) {
		case 'home':
		case 'feed':
			$expire = $wp_ffpc_config['browsercache_home'];
			break;
		case 'archive':
			$expire = $wp_ffpc_config['browsercache_taxonomy'];
			break;
		case 'single':
			$expire = $wp_ffpc_config['browsercache'];
			break;
		default:
			$expire = 0;
	}

	header('Cache-Control: public,max-age='.$expire.',s-maxage='.$expire.',must-revalidate');
	header('Expires: ' . gmdate("D, d M Y H:i:s", $wp_ffpc_values['meta']['expire'] ) . " GMT");
	header('ETag: '. $hash);
	unset($expire, $hash);
}
else {
	/* in case there is no expiry set, expire immediately and don't serve Etag; browser cache is disabled */
	header('Expires: ' . gmdate("D, d M Y H:i:s", time() ) . " GMT");
	/* if I set these, the 304 not modified will never, ever kick in, so not setting these
	 * leaving here as a reminder why it should not be set */
	//header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0, post-check=0, pre-check=0');
	//header('Pragma: no-cache');
}

/* if shortlinks were set */
if (isset($wp_ffpc_values['meta']['shortlink']) && !empty ( $wp_ffpc_values['meta']['shortlink'] ) )
	header( 'Link:<'. $wp_ffpc_values['meta']['shortlink'] .'>; rel=shortlink' );

/* if last modifications were set (for posts & pages) */
if (isset($wp_ffpc_values['meta']['lastmodified']) && !empty($wp_ffpc_values['meta']['lastmodified']) )
	header( 'Last-Modified: ' . gmdate("D, d M Y H:i:s", $wp_ffpc_values['meta']['lastmodified'] ). " GMT" );

/* pingback urls, if existx */
if ( isset($wp_ffpc_values['meta']['pingback']) && !empty( $wp_ffpc_values['meta']['pingback'] ) && isset($wp_ffpc_config['pingback_header']) && $wp_ffpc_config['pingback_header'] )
	header( 'X-Pingback: ' . $wp_ffpc_values['meta']['pingback'] );

/* for debugging */
if ( isset($wp_ffpc_config['response_header']) && $wp_ffpc_config['response_header'] )
	header( 'X-Cache-Engine: WP-FFPC with ' . $wp_ffpc_config['cache_type'] .' via PHP');

/* HTML data */
if ( isset($wp_ffpc_config['generate_time']) && $wp_ffpc_config['generate_time'] == '1' && stripos($wp_ffpc_values['data'], '</body>') ) {
	$mtime = explode ( " ", microtime() );
	$wp_ffpc_gentime = ( $mtime[1] + $mtime[0] ) - $wp_ffpc_gentime;

	$insertion = "\n<!-- WP-FFPC cache output stats\n\tcache engine: ". $wp_ffpc_config['cache_type'] ."\n\tUNIX timestamp: ". time() . "\n\tdate: ". date( 'c' ) . "\n\tfrom server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
	$index = stripos( $wp_ffpc_values['data'] , '</body>' );

	$wp_ffpc_values['data'] = substr_replace( $wp_ffpc_values['data'], $insertion, $index, 0);
}

echo trim($wp_ffpc_values['data']);

flush();
die();

/*** END SERVING CACHED PAGE ***/


/*** GENERATING CACHE ENTRY ***/
/**
 * starts caching function
 *
 */
function wp_ffpc_start( ) {
	/* set start time */
	global $wp_ffpc_gentime;
	$mtime = explode ( " ", microtime() );
	$wp_ffpc_gentime = $mtime[1] + $mtime[0];

	/* start object "colleting" and pass it the the actual storer function  */
	ob_start('wp_ffpc_callback');
}

/**
 * callback function for WordPress redirect urls
 *
 */
function wp_ffpc_redirect_callback ($redirect_url, $requested_url) {
	global $wp_ffpc_redirect;
	$wp_ffpc_redirect = $redirect_url;
	return $redirect_url;
}

/**
 * write cache function, called when page generation ended
 */
function wp_ffpc_callback( $buffer ) {
	/* use global config */
	global $wp_ffpc_config;
	/* backend was already set up, try to use it */
	global $wp_ffpc_backend;
	/* check is it's a redirect */
	global $wp_ffpc_redirect;

	/* no is_home = error, WordPress functions are not availabe */
	if (!function_exists('is_home'))
		return $buffer;

	/* no <body> close tag = not HTML, also no <rss>, not feed, don't cache */
	if ( stripos($buffer, '</body>') === false && stripos($buffer, '</rss>') === false )
		return $buffer;

	/* reset meta to solve conflicts */
	$meta = array();

	/* trim unneeded whitespace from beginning / ending of buffer */
	$buffer = trim( $buffer );

	/* Can be a trackback or other things without a body.
	   We do not cache them, WP needs to get those calls. */
	if (strlen($buffer) == 0)
		return '';

	if ( is_string($wp_ffpc_config['nocache_comment']) ) {
		// TODO trim() is only needed for legacy advanced-cache.php files saved/created with whitespace; can micro-optimize by removing the trim() and combining if tests
		$wp_ffpc_config['nocache_comment'] = trim($wp_ffpc_config['nocache_comment']);
		if ('' !== $wp_ffpc_config['nocache_comment']) {
			$pattern = sprintf('#%s#', $wp_ffpc_config['nocache_comment']);
			__debug__ ( sprintf("Testing comment with pattern: %s", $pattern));
			if ( preg_match($pattern, $buffer) ) {
				__debug__ ( "Cache exception based on content regex pattern matched, skipping");
				return $buffer;
			}
		}
	}

	if ( is_home() || is_feed() ) {
		if (is_home())
			$meta['type'] = 'home';
		elseif(is_feed())
			$meta['type'] = 'feed';

		if (isset($wp_ffpc_config['browsercache_home']) && !empty($wp_ffpc_config['browsercache_home']) && $wp_ffpc_config['browsercache_home'] > 0) {
			$meta['expire'] = time() + $wp_ffpc_config['browsercache_home'];
		}

		__debug__( 'Getting latest post for for home & feed');
		/* get newest post and set last modified accordingly */
		$args = array(
			'numberposts' => 1,
			'orderby' => 'modified',
			'order' => 'DESC',
			'post_status' => 'publish',
		);

		$recent_post = wp_get_recent_posts( $args, OBJECT );
		if ( !empty($recent_post)) {
			$recent_post = array_pop($recent_post);
			if (!empty ( $recent_post->post_modified_gmt ) ) {
				$meta['lastmodified'] = strtotime ( $recent_post->post_modified_gmt );
			}
		}

	}
	elseif ( is_archive() ) {
		$meta['type'] = 'archive';
		if (isset($wp_ffpc_config['browsercache_taxonomy']) && !empty($wp_ffpc_config['browsercache_taxonomy']) && $wp_ffpc_config['browsercache_taxonomy'] > 0) {
			$meta['expire'] = time() + $wp_ffpc_config['browsercache_taxonomy'];
		}

		global $wp_query;

		if ( null != $wp_query->tax_query && !empty($wp_query->tax_query)) {
			__debug__( 'Getting latest post for taxonomy: ' . json_encode($wp_query->tax_query));

			$args = array(
				'numberposts' => 1,
				'orderby' => 'modified',
				'order' => 'DESC',
				'post_status' => 'publish',
				'tax_query' => $wp_query->tax_query,
			);

			$recent_post =  get_posts( $args, OBJECT );

			if ( !empty($recent_post)) {
				$recent_post = array_pop($recent_post);
				if (!empty ( $recent_post->post_modified_gmt ) ) {
					$meta['lastmodified'] = strtotime ( $recent_post->post_modified_gmt );
				}
			}
		}

	}
	elseif ( is_single() || is_page() ) {
		$meta['type'] = 'single';
		if (isset($wp_ffpc_config['browsercache']) && !empty($wp_ffpc_config['browsercache']) && $wp_ffpc_config['browsercache'] > 0) {
			$meta['expire'] = time() + $wp_ffpc_config['browsercache'];
		}

		/* try if post is available
			if made with archieve, last listed post can make this go bad
		*/
		global $post;
		if ( !empty($post) && !empty ( $post->post_modified_gmt ) ) {
			/* get last modification data */
			$meta['lastmodified'] = strtotime ( $post->post_modified_gmt );

			/* get shortlink, if possible */
			if (function_exists('wp_get_shortlink')) {
				$shortlink = wp_get_shortlink( );
				if (!empty ( $shortlink ) )
					$meta['shortlink'] = $shortlink;
			}
		}

	}
	else {
		$meta['type'] = 'unknown';
	}

	if ( $meta['type'] != 'unknown' ) {
		/* check if caching is disabled for page type */
		$nocache_key = 'nocache_'. $meta['type'];

		/* don't cache if prevented by rule */
		if ( $wp_ffpc_config[ $nocache_key ] == 1 ) {
			return $buffer;
		}
	}

	if ( is_404() )
		$meta['status'] = 404;

	/* redirect page */
	if ( $wp_ffpc_redirect != null)
		$meta['redirect'] =  $wp_ffpc_redirect;

	/* feed is xml, all others forced to be HTML */
	if ( is_feed() )
		$meta['mime'] = 'text/xml;charset=';
	else
		$meta['mime'] = 'text/html;charset=';

	/* set mimetype */
	$meta['mime'] = $meta['mime'] . $wp_ffpc_config['charset'];

	/* store pingback url if pingbacks are enabled */
	if ( get_option ( 'default_ping_status' ) == 'open' )
		$meta['pingback'] = get_bloginfo('pingback_url');

	$to_store = $buffer;

	/* add generation info is option is set, but only to HTML */
	if ( $wp_ffpc_config['generate_time'] == '1' && stripos($buffer, '</body>') ) {
		global $wp_ffpc_gentime;
		$mtime = explode ( " ", microtime() );
		$wp_ffpc_gentime = ( $mtime[1] + $mtime[0] )- $wp_ffpc_gentime;

		$insertion = "\n<!-- WP-FFPC cache generation stats" . "\n\tgeneration time: ". round( $wp_ffpc_gentime, 3 ) ." seconds\n\tgeneraton UNIX timestamp: ". time() . "\n\tgeneraton date: ". date( 'c' ) . "\n\tgenerator server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
		$index = stripos( $buffer , '</body>' );

		$to_store = substr_replace( $buffer, $insertion, $index, 0);
	}

	$prefix_meta = $wp_ffpc_backend->key ( $wp_ffpc_config['prefix_meta'] );
	$wp_ffpc_backend->set ( $prefix_meta, $meta );

	$prefix_data = $wp_ffpc_backend->key ( $wp_ffpc_config['prefix_data'] );
	$wp_ffpc_backend->set ( $prefix_data , $to_store );

	if ( !empty( $meta['status'] ) && $meta['status'] == 404 ) {
		header("HTTP/1.1 404 Not Found");
	}
	else {
		/* vital for nginx, make no problem at other places */
		header("HTTP/1.1 200 OK");
	}

	/* echoes HTML out */
	return trim($buffer);
}
/*** END GENERATING CACHE ENTRY ***/
