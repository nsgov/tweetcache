<?php
require('./twitteroauth/twitteroauth.php');

class TweetCache {
	protected $config;
	protected $cachefile;
	protected $stat;
	protected $lastmod;
	protected $logentries;
	protected $feed;

	function __construct($config, $username, $format) {
		$this->logentries = array();
		if (!preg_match('/^\w+$/', $username))
			throw new Exception("Username not accepted.");

		if (!in_array($format, array('atom', 'xml', 'rss')))
			throw new Exception("Feed format not accepted");

		$this->config = $config;
		$this->cachefile = $config['cache']['path']."/$username/$username.$format";
		$this->stat = @stat($this->cachefile);
		if ($this->stat)
			$this->lastmod = $this->stat['mtime'];
		else
			throw new Exception("$format cache for $username not found.");
		$this->username = $username;
		$this->format = $format;
	}

	function load() {
		$this->feed = '';
		$cache = fopen($this->cachefile, 'r+b');
		if (!$cache)
			throw new Exception("Could not open cache file");
		$age = time() - $this->lastmod;
		$this->log("Cache age: $age seconds");
		$stale = $age > $this->config['cache']['max_age'];
		if ($stale || !$this->stat['size']) {
			if (flock($cache, LOCK_EX|LOCK_NB)) {
				try {
					$xmldom = $this->loadFromTwitter();
					$this->feed = $xmldom->saveXML($xmldom->documentElement);
					$this->lastmod = time();
					$this->log("Writing to cache");
					ftruncate($cache, 0);
					fwrite($cache, $this->feed, strlen($this->feed));
				} catch(Exception $ex) {
					$this->log($ex->getMessage());
				}
				flock($cache, LOCK_UN);
			} else $this->log("Unable to get exclusive lock");
		}
		if (!$this->feed) {
			$this->log("Reading from cache");
			flock($cache, LOCK_SH);
			/*	refresh stat after getting lock,
				just in case flock was waiting for another LOCK_EX to release,
				in which case the file was just updated	*/
			$this->stat = fstat($cache);
			$bytes = $this->stat['size'];
			if ($bytes)
				$this->feed = fread($cache, $bytes);
			flock($cache, LOCK_UN);
		}
		fclose($cache);
	}

	function modified() { return $this->lastmod; }
	
	function isStale() { return $this->age() > $this->config['cache']['max_age']; }

	function mimetype() {
		$mimetype = 'application/';
		if (in_array($this->format, array('atom', 'rss')))
			$mimetype .= $this->format . '+xml';
		else
			$mimetype .= 'xml';
		return $mimetype; 
	}
	
	function loadFromTwitter() {
		$this->log("Loading tweets from twitter");
		$start = time();
		$config = $this->config['twitter']; 
		$connection = new TwitterOAuth($config['CONSUMER_KEY'],
		                               $config['CONSUMER_SECRET'],
		                               $config['OAUTH_TOKEN'],
		                               $config['OAUTH_TOKEN_SECRET']);
		$connection->connecttimeout = $config['timeout'];
		$connection->format = $this->format;
		$params = array(
			'screen_name'=>$this->username,
			'trim_user'=>1,
			'include_entities'=>0,
			'include_rts'=>0
		);
		$paramfile = $this->config['cache']['path'].'/' . $this->username . '/params.ini';
		if (file_exists($paramfile))
			$params = array_merge($params, parse_ini_file($paramfile));
		$tweets = $connection->get('statuses/user_timeline', $params);
		$hc = $connection->http_code;
		if ($hc != 200)
			throw new Exception("Fail Whale: HTTP " . ($hc?$hc:'timeout') . ' (after ' . (time() - $start) . ' seconds)', $hc);
		$xml = new DomDocument();
		$xml->loadXML($tweets);
		return $xml;
	}
	
	function toString() {
		return $this->feed;
	}

	function log($txt) {
		$this->logentries[] = $txt;
	}

	function getLog($sep="\n") {
		return join($sep, $this->logentries);
	}
}
