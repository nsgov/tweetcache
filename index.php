<?php
require('tweetcache.php');

try {
	$config = parse_ini_file("tweetcache.ini", true);
	$username = '';
	$format = 'xml';
	$bad = array();
	if (isset($_GET['username'])) {
		$username = $_GET['username'];
		if (!preg_match('/^\w+$/', $username))
			$bad[] = "Twitter username not accepted.";
	} else
		$bad[] = 'No twitter name given.';
	if (isset($_GET['format']))
		$format = $_GET['format'];
	if (!in_array($format, array('atom', 'xml', 'rss')))
		$bad[] = "Format not accepted.";
	
	if (count($bad)) {
		header("Status: 400 Bad Request");
		echo "<ul>\n\t<li>" . join("</li>\n\t<li>", $bad) . "</li>\n</ul>\n";
	} else {
		$tweets = new TweetCache($config, $username, $format);
		$tweets->load();
		$now = time();
		$max_age = $config['cache']['max_age'];
		header('Content-type: ' . $tweets->mimetype() . '; charset=utf-8');
		header('Last-Modified: ' . gmstrftime("%A %d-%b-%y %T %Z", $tweets->modified()));
		header('Expires: ' . gmstrftime("%A %d-%b-%y %T %Z", $now + $max_age));
		header('Cache-control: public,max-age=' . $max_age);
		echo '<' . '?xml version="1.0" encoding="utf-8" ?' . ">\n";
		echo "<!--\n\t" . $tweets->getLog("\n\t") . "\n-->\n";
		echo $tweets->toString() . "\n";
	}
} catch(Exception $ex) {
	header('Status: 500 Internal Server Error');
	echo $ex->getMessage() . "\n";
}

?>
