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

		if (!in_array($format, array('atom', 'xml', 'rss', 'json')))
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
					$this->feed = $this->loadFromTwitter();
					if ($this->format!='json') {
						$xml = new DomDocument();
						$xml->loadXML($this->feed);
						#$this->filterTweets($xml);
						$this->convertToLocalTime($xml);
						$this->feed = $xml->saveXML($xml->documentElement);
					}
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
			$mimetype .= $this->format;
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
		if (in_array($this->format, array('atom', 'rss'))) {
			unset($params['trim_user']);
			unset($params['include_rts']);
		}
		$tweets = $connection->get('statuses/user_timeline', $params);
		$hc = $connection->http_code;
		if ($hc != 200)
			throw new Exception("Fail Whale: HTTP " . ($hc?$hc:'timeout') . ' (after ' . (time() - $start) . ' seconds)', $hc);
		return $tweets;
	}

	function convertToLocalTime($xml) {
		$months = array('Jan'=>1, 'Feb'=>2, 'Mar'=>3, 'Apr'=>4, 'May'=>5, 'Jun'=>6,
						'Jul'=>7, 'Aug'=>8, 'Sep'=>9, 'Oct'=>10, 'Nov'=>11, 'Dec'=>12);
		$this->log('Converting timestamps to ' . $this->config['cache']['timezone'] . ' timezone');
		switch ($this->format) {
			case 'atom':
				foreach (array('published', 'updated') as $tagname) {
					$tags = $xml->getElementsByTagNameNS('http://www.w3.org/2005/Atom', $tagname);
					for ($n = $tags->length; $n--;) {
						$tag = $tags->item($n);
						if (preg_match_all('/^(\d+)-(\d+)-(\d+)T(\d+):(\d+):(\d+)[-+]00:00$/', $tag->textContent, $d)) {
							$t = gmmktime($d[4][0],$d[5][0],$d[6][0],$d[2][0],$d[3][0],$d[1][0]);
							$tag->removeChild($tag->firstChild);
							$tag->appendChild($xml->createTextNode(date(DATE_W3C, $t)));
						}
					}
				}
				break;
			case 'rss':
				$tags = $xml->getElementsByTagName('pubDate');
				for ($n = $tags->length; $n--;) {
					$tag = $tags->item($n);
					if (preg_match_all('/^\w+, (\d+) (\w+) (\d+)\s+(\d+):(\d+):(\d+)\s+[-+]0000$/', $tag->textContent, $d)) {
						$t = gmmktime($d[4][0],$d[5][0],$d[6][0],$months[$d[2][0]],$d[1][0],$d[3][0]);
						$tag->removeChild($tag->firstChild);
						$tag->appendChild($xml->createTextNode(date('D, d M Y H:i:s O', $t)));
					}
				}
				break;
			case 'xml':
				$tags = $xml->getElementsByTagName('created_at');
				for ($n = $tags->length; $n--;) {
					$tag = $tags->item($n);
					if (preg_match_all('/^\w+ (\w+) (\d+)\s+(\d+):(\d+):(\d+)\s+[-+]0000\s+(\d+)$/', $tag->textContent, $d)) {
						$t = gmmktime($d[3][0],$d[4][0],$d[5][0],$months[$d[1][0]],$d[2][0],$d[6][0]);
						$tag->removeChild($tag->firstChild);
						$tag->appendChild($xml->createTextNode(date('D M d H:i:s O Y', $t)));
					}
				}
				break;
			default: break;
		}
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
