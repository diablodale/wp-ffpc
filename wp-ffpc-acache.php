<?php
/**
 * advanced cache worker of WordPress plugin WP-FFPC
 */

if ( !function_exists ('__debug__') ) {
	/* __ only availabe if we're running from the inside of wordpress, not in advanced-cache.php phase */
	function __debug__( $text ) {
		if ( defined('WP_FFPC__DEBUG_MODE') && WP_FFPC__DEBUG_MODE == true)
			error_log ( __FILE__ . ': ' . $text );
	}
}

/* no cache for post request (comments, plugins and so on) or if we are in the wp-cron.php process */
if ( ($_SERVER['REQUEST_METHOD'] === 'POST') || defined('DOING_CRON') )
	return false;

/**
 * no cache for enabled and currently existing session
 * TODO re-evaluate this
 */
if (defined('SID') && SID !== '')
	return false;

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

/* request uri */
$wp_ffpc_uri = $_SERVER['REQUEST_URI'];

/* no cache for robots.txt */
if ( 0 === strcasecmp($wp_ffpc_uri, '/robots.txt') ) {
	__debug__('Skippings robots.txt hit');
	return false;
}

/* multisite legacy ms-files support can be too large for memcached */
// https://codex.wordpress.org/Multisite_Network_Administration#Uploaded_File_Path
if (is_multisite() && (0 === @substr_compare($_SERVER['SCRIPT_NAME'], '/ms-files.php', -13, 13, true))) {
	__debug__('Skipping multisite legacy ms-files hit');
	return false;
}

/* no cache for uri with query strings, things usually go bad that way */
// TODO reevaluate this because this causes the default WP setup (non-pretty links) and default wp-ffpc config will not cache
if ( $wp_ffpc_config['nocache_dyn'] && (stripos($wp_ffpc_uri, '?') !== false) ) {
	__debug__('Dynamic url cache is disabled ( url with "?" ), skipping');
	return false;
}

// no cache for excluded URL patterns
if ( is_string($wp_ffpc_config['nocache_url']) ) {
	// TODO trim() is only needed for legacy advanced-cache.php files saved/created with whitespace; can micro-optimize by removing the trim() and combining if tests
	$wp_ffpc_config['nocache_url'] = trim($wp_ffpc_config['nocache_url']);
	if ('' !== $wp_ffpc_config['nocache_url']) {
		// TODO consider switching delimiter to one of these |`^ because # is very common in URLs therefore more likely to be searched
		$pattern = "#{$wp_ffpc_config['nocache_url']}#i";
		if ( 1 === preg_match($pattern, $wp_ffpc_uri) ) {
			__debug__('Cache exception based on URL regex pattern matched, skipping');
			return false;
		}
	}
}

/* load backend cache storage codebase */
require_once __DIR__ . '/wp-ffpc-backend.php';

// check for cookies that will make us not cache the content
if (!empty($_COOKIE)) {
	// start with the cookies that suggest users are logged-in 
	if ( empty($wp_ffpc_config['cache_loggedin']) )
		$nocache_cookies = $wp_ffpc_auth_cookies;	// TODO switch back to storing auth cookies in the class as a static var or return of a function
	else
		$nocache_cookies = array();
	// next add admin-specified cookies
	if ( is_string($wp_ffpc_config['nocache_cookies']) ) {
		// support legacy advanced-cache.php config files that saved this value as a string w/ commas instead of an array of strings
		$wp_ffpc_config['nocache_cookies'] = array_filter(
			array_map('trim', explode(',', $wp_ffpc_config['nocache_cookies'])),
			function($value) {return $value !== '';} );
	}
	if ( is_array($wp_ffpc_config['nocache_cookies']) )
		$nocache_cookies = array_merge($nocache_cookies, $wp_ffpc_config['nocache_cookies']);

	// loop through browser cookies received and check for matches that will disable caching
	foreach ( $nocache_cookies as $single_nocache ) {
		foreach ($_COOKIE as $n=>$v) {
			if( strpos( $n, $single_nocache ) === 0 ) {
				__debug__("Cookie exception matched: $n, skipping");
				return false;
			}
		}
	}
}

// crude clock for measuring page generation and cache retrieval time
// - PHP has no stable timing clock https://blog.habets.se/2010/09/gettimeofday-should-never-be-used-to-measure-time
// - PHP typically uses IEEE 754 doubles w/ ~14 decimal digits precision. 10 digits
//   represent whole seconds from epoc, leaving only 4 digits (100s of microseconds) 
// - more precision might be retained by gettimeofday() and optional module BCMath
$wp_ffpc_gentime = microtime(true);

/* fires up the backend storage array with current config */
$backend_class = 'WP_FFPC_Backend_' . $wp_ffpc_config['cache_type'];
$wp_ffpc_backend = new $backend_class ( $wp_ffpc_config );

/* backend connection failed, no caching :( */
if ( $wp_ffpc_backend->status() === false ) {
	__debug__('Backend offline, skipping');
	return false;
}

/* canonical redirect storage */
$wp_ffpc_redirect = null;

/* try to get data & meta keys for current page */
$wp_ffpc_keys = array ( 'meta' => $wp_ffpc_config['prefix_meta'], 'data' => $wp_ffpc_config['prefix_data'] );
$wp_ffpc_values = array();

__debug__('Trying to fetch entries');

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
if ( isset($wp_ffpc_values['meta']['status']) && ($wp_ffpc_values['meta']['status'] === 404) ) {
	header('HTTP/1.1 404 Not Found');
	/* if I kill the page serving here, the 404 page will not be showed at all, so we do not do that
	 * flush();
	 * die();
	 */
}

/* server redirect cache */
if ( !empty($wp_ffpc_values['meta']['redirect']) ) {
	header('Location: ' . $wp_ffpc_values['meta']['redirect'] );
	/* cut the connection as fast as possible */
	flush();
	die();
}

/* page is already cached on client side (chrome likes to do this, anyway, it's quite efficient) */
if ( array_key_exists( 'HTTP_IF_MODIFIED_SINCE', $_SERVER ) && !empty( $wp_ffpc_values['meta']['lastmodified'] ) ) {
	$if_modified_since = strtotime(preg_replace('/;.*$/', '', $_SERVER['HTTP_IF_MODIFIED_SINCE']));
	/* check is cache is still valid */
	if ( $if_modified_since >= $wp_ffpc_values['meta']['lastmodified'] ) {
		header('HTTP/1.0 304 Not Modified');
		/* connection cut for faster serving */
		flush();
		die();
	}
}

/*** SERVING CACHED PAGE ***/

/* if we reach this point it means data was found & correct, serve it */
if ( !empty($wp_ffpc_values['meta']['mime']) )
	header('Content-Type: ' . $wp_ffpc_values['meta']['mime']);

/* set expiry date */
if ( !empty( $wp_ffpc_values['meta']['expire']) ) {
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
		case 'page':
			$expire = $wp_ffpc_config['browsercache'];
			break;
		default:
			$expire = 0;
	}

	// BUGBUG re-evaluate the cache-control header because max-age here will not match the expire stored in meta
	// https://www.w3.org/Protocols/rfc2616/rfc2616-sec14.html#sec14.9.3
	header('Cache-Control: public,max-age='.$expire.',s-maxage='.$expire.',must-revalidate');
	header('Expires: ' . gmdate('D, d M Y H:i:s', $wp_ffpc_values['meta']['expire'] ) . ' GMT');
	header('ETag: '. $hash);
	unset($expire, $hash);
}
else {
	/* in case there is no expiry set, expire immediately and don't serve Etag; browser cache is disabled */
	header('Expires: ' . gmdate('D, d M Y H:i:s', time() ) . ' GMT');
	/* BUGBUG past dev wrote if the two below are set, the 304 not modified will never, ever kick in, so not setting these
	 * leaving here as a reminder why it should not be set */
	//header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, s-maxage=0, post-check=0, pre-check=0');
	//header('Pragma: no-cache');
}

/* write out shortlink if set; guaranteed to be !empty() because that test was done before storing shortlink metadata in cache */
if (isset($wp_ffpc_values['meta']['shortlink']))
	header( 'Link:<'. $wp_ffpc_values['meta']['shortlink'] .'>; rel=shortlink' );

/* write out last modifications if set (for posts & pages) */
if (!empty($wp_ffpc_values['meta']['lastmodified']))
	header( 'Last-Modified: ' . gmdate('D, d M Y H:i:s', $wp_ffpc_values['meta']['lastmodified'] ). ' GMT' );

/* write out pingback urls, if set */
if ( !empty($wp_ffpc_values['meta']['pingback']) && !empty($wp_ffpc_config['pingback_header']) )
	header( 'X-Pingback: ' . $wp_ffpc_values['meta']['pingback'] );

/* write out header to identify wp-ffpc as the cache; used for debugging */
if ( !empty($wp_ffpc_config['response_header']) )
	header( 'X-Cache-Engine: WP-FFPC with ' . $wp_ffpc_config['cache_type'] .' via PHP');

/* write out HTML debug comment, if set */
if ( !empty($wp_ffpc_config['generate_time']) ) {
	$wp_ffpc_gentime = microtime(true) - $wp_ffpc_gentime;
	$insertion = "\n<!-- WP-FFPC cache retrieval stats\n\tcache engine: ". $wp_ffpc_config['cache_type'] . "\n\tcache response: " . round($wp_ffpc_gentime, 6) . " seconds\n\tretrieval UNIX timestamp: ". time() . "\n\tretrieval date: ". date( 'c' ) . "\n\tretrieval via web server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
	// TODO the check for the closing body tag is weak, e.g. when the tag is written by script; also consider if strripos() and a negative offset will be faster
	$index = stripos($wp_ffpc_values['data'], '</body>');
	if (false !== $index)
		$wp_ffpc_values['data'] = substr_replace( $wp_ffpc_values['data'], $insertion, $index, 0 );
	//else
	//	$wp_ffpc_values['data'] .= $insertion;
}

echo $wp_ffpc_values['data'];

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
	$wp_ffpc_gentime = microtime(true);

	/* start output buffering and pass it the actual storer function as a callback */
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

	// no cache de facto; those pages on which DONOTCACHEPAGE is defined and true
	if ( defined('DONOTCACHEPAGE') && DONOTCACHEPAGE )
		return $buffer;

	/* no is_home = error, WordPress functions are not availabe */
	if (!function_exists('is_home'))
		return $buffer;

	// no <html> close tag = not HTML, also no <rss>, not feed, don't cache
	// BUGBUG still doesn't handle a rich set of cases. What is the goal here? Why can't we cache anything that isn't elsewhere excluded?
	if ( (stripos($buffer, '</html>') === false) && (stripos($buffer, '</rss>') === false) )
		return $buffer;

	/* Can be a trackback or other things without a body.
	   We do not cache them, WP needs to get those calls. */
	$buffer = trim($buffer);
	if (strlen($buffer) === 0)
		return '';

	// scan content for any strings which cause no caching
	if ( is_string($wp_ffpc_config['nocache_comment']) ) {
		// TODO trim() is only needed for legacy advanced-cache.php files saved/created with whitespace; can micro-optimize by removing the trim() and combining if tests
		$wp_ffpc_config['nocache_comment'] = trim($wp_ffpc_config['nocache_comment']);
		if ('' !== $wp_ffpc_config['nocache_comment']) {
			$pattern = "#{$wp_ffpc_config['nocache_comment']}#";
			__debug__("Testing comment with pattern: $pattern");
			if ( preg_match($pattern, $buffer) ) {
				__debug__('Cache exception based on content regex pattern matched, skipping');
				return $buffer;
			}
		}
	}

	// get type for content 
	$meta = array();
	if ( is_home() )
		$meta['type'] = 'home';
	elseif ( is_feed() )
		$meta['type'] = 'feed';
	elseif ( is_archive())
		$meta['type'] = 'archive';
	elseif ( is_single() )
		// BUGBUG there is a bug in v1.10.1 related to this, is_singular() and pages because the legacy code has a page checkbox on the options page that did nothing 
		$meta['type'] = 'single';
	elseif ( is_page() )
		$meta['type'] = 'page';
	else
		// TODO consider adding custom post type support, e.g. http://wordpress.stackexchange.com/questions/6731/if-is-custom-post-type
		$meta['type'] = 'unknown';

	// don't cache page type if prevented by selected option
	if ( !empty($wp_ffpc_config['nocache_'. $meta['type']]) ) {
		return $buffer;
	}

	// set mimetype and character set
	if ( 'feed' === $meta['type'] )
		$meta['mime'] = "text/xml;charset={$wp_ffpc_config['charset']}";
	else
		$meta['mime'] = "text/html;charset={$wp_ffpc_config['charset']}";

	/* reset meta to solve conflicts */
	switch ($meta['type']) {
	case 'home':
	case 'feed':
		if (!empty($wp_ffpc_config['browsercache_home'])) {
			$meta['expire'] = time() + (int)$wp_ffpc_config['browsercache_home'];
		}
		if (isset($wp_ffpc_config['expire_home']))
			$backend_ttl = (int)$wp_ffpc_config['expire_home'];
		else
			$backend_ttl = 0;

		/* get newest post and set last modified accordingly */
		__debug__( 'Getting latest post for home & feed');
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
				$meta['lastmodified'] = strtotime( $recent_post->post_modified_gmt );
			}
		}
		break;
	case 'archive':
		if (!empty($wp_ffpc_config['browsercache_taxonomy'])) {
			$meta['expire'] = time() + (int)$wp_ffpc_config['browsercache_taxonomy'];
		}
		if (isset($wp_ffpc_config['expire_taxonomy']))
			$backend_ttl = (int)$wp_ffpc_config['expire_taxonomy'];
		else
			$backend_ttl = 0;

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

			$recent_post = get_posts( $args, OBJECT );
			if ( !empty($recent_post)) {
				$recent_post = array_pop($recent_post);
				if (!empty ( $recent_post->post_modified_gmt ) ) {
					$meta['lastmodified'] = strtotime( $recent_post->post_modified_gmt );
				}
			}
		}
		break;
	case 'single':
	case 'page':
		if (!empty($wp_ffpc_config['browsercache'])) {
			$meta['expire'] = time() + $wp_ffpc_config['browsercache'];
		}

		// try if post is available
		// BUGBUG if made with archive, last listed post can make this go bad
		global $post;
		if ( !empty($post) && !empty( $post->post_modified_gmt ) ) {
			/* get last modification data */
			$meta['lastmodified'] = strtotime( $post->post_modified_gmt );

			/* get shortlink */
			$shortlink = wp_get_shortlink();
			if ( !empty($shortlink) )
				$meta['shortlink'] = $shortlink;
		}
	default:
		if (isset($wp_ffpc_config['expire']))
			$backend_ttl = (int)$wp_ffpc_config['expire'];
		else
			$backend_ttl = 0;
	}

	if (is_404()) {
		$meta['status'] = 404;
		header('HTTP/1.1 404 Not Found');
	}
	else {
		/* vital for nginx, make no problem at other places */
		header('HTTP/1.1 200 OK');
	}

	/* redirect page */
	if ( $wp_ffpc_redirect != null)
		$meta['redirect'] = $wp_ffpc_redirect;

	/* store pingback url if pingbacks are enabled */
	if ( get_option('default_ping_status') === 'open' )
		$meta['pingback'] = get_bloginfo('pingback_url');

	/* add generation info is option is set, but only to HTML */
	if ( !empty($wp_ffpc_config['generate_time']) ) {
		global $wp_ffpc_gentime;
		$wp_ffpc_gentime = microtime(true) - $wp_ffpc_gentime;
		$insertion = "\n<!-- WP-FFPC content generation stats" . "\n\tgeneration time: ". round( $wp_ffpc_gentime, 3 ) ." seconds\n\tgeneration UNIX timestamp: ". time() . "\n\tgeneration date: ". date( 'c' ) . "\n\tgeneration via web server: ". $_SERVER['SERVER_ADDR'] . " -->\n";
		// TODO the check for the closing body tag is weak, e.g. when the tag is written by script; also consider if strripos() and a negative offset will be faster
		$index = stripos($buffer, '</body>');
		if (false !== $index)
			$buffer = substr_replace( $buffer, $insertion, $index, 0 );
		//else
		//	$buffer .= $insertion;
	}

	$prefix_meta = $wp_ffpc_backend->key( $wp_ffpc_config['prefix_meta'] );
	$wp_ffpc_backend->set( $prefix_meta, $meta, $backend_ttl );

	$prefix_data = $wp_ffpc_backend->key( $wp_ffpc_config['prefix_data'] );
	$wp_ffpc_backend->set( $prefix_data , $buffer, $backend_ttl );

	/* echoes HTML out */
	return $buffer;
}
/*** END GENERATING CACHE ENTRY ***/
