<?php

// Worker PHP file for the wp-ffpc precache feature
// argument 1 (required): input file holding urls to crawl; one on each line
// argument 2 (optional): full log pathname into which write results of the crawl 

if (!isset($argv[1])) {
	fwrite(STDERR, 'wp-ffpc precache worker must have file containing URLs to crawl as first parameter');
	exit(1);
}

// load links into an array
$links = file($argv[1], FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
if (empty($links)) {
	fwrite(STDERR, 'wp-ffpc precache worker received no links');
	exit(1);
}

// open the logfile for writing
$logfile = false;
if (isset($argv[2])) {
	$logfile = fopen($argv[2], 'wb');
	if (false === $logfile) {
		fwrite(STDERR, 'wp-ffpc precache worker can not create logfile');
		exit(1);
	}
	if (false === fwrite($logfile, "permalink\tgeneration time (s)\tsize (kbyte)\n")) {
		@fclose($logfile);
		fwrite(STDERR, 'wp-ffpc precache worker can not write header to logfile');
		exit(1);
	}
}

// loop through each link, http GET it, and save statistics
foreach ( $links as $permalink ) {
	// only support precaching http and https URLs; this is to help prevent malicious URLS like streams, ftp, file descriptions, etc.
	$urlScheme = parse_url($permalink, PHP_URL_SCHEME);
	if (empty($urlScheme))
		continue;
	$urlScheme = strtolower($urlScheme);
	if (('http' !== $urlScheme) && ('https' !== $urlScheme))
		continue;

	// crude substitute for a timing clock
	$starttime = microtime(true);

	$page = @file_get_contents( $permalink );
	if (false === $page) {
		$size = 0;
		$endtime = 0;
		//$permalink = $http_response_header[0] . ' ' . $permalink;
	}
	else {
		$size = round ( ( strlen ( $page ) / 1024 ), 2 );
		$endtime = round( microtime(true) - $starttime, 2 );
	}
	if ($logfile && (false === fwrite($logfile, $permalink . "\t" .  $endtime . "\t" . $size . "\n"))) {
		@fclose($logfile);
		fwrite(STDERR, 'wp-ffpc precache worker can not write to logfile');
		exit(1);
	}
	unset( $page, $size, $starttime, $endtime );
	sleep( 1 );
}
@fclose($logfile);
unlink($argv[1]);
exit(0);
?>
