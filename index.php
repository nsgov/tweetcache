<?php

########################################

$PATH_TO_TWITTEROAUTH = './twitteroauth/twitteroauth.php';  # twitteroauth is required
$TWEETS_XML='./tweets.xml';    # this file needs to be writable
$MAX_AGE = 120;    # the minimum number of seconds to use the cache without making a new request to twitter

# twitter api keys, fill them in
define('CONSUMER_KEY', '___');
define('CONSUMER_SECRET', '___');
define('OAUTH_TOKEN', '___');
define('OAUTH_TOKEN_SECRET', '___');

########################################

require($PATH_TO_TWITTEROAUTH);
$comments = array();

function main() {
  global $TWEETS_XML, $MAX_AGE, $comments;
  $xml = null;
  $xmlstr = null;
  $lastmod = 0;
  $tweets_xml = fopen($TWEETS_XML, 'r+b');
  if ($tweets_xml) {
    $stat = fstat($tweets_xml);
    $lastmod = $stat['mtime'];
    $cache_age = time() - $lastmod;
    btw("Cache age: $cache_age seconds");
    if ($cache_age > $MAX_AGE) {
      if (flock($tweets_xml, LOCK_EX|LOCK_NB)) {
	try {
	  $xml = fetchTweets();
	  // filterTweets($xml);
	  $xmlstr = $xml->saveXML($xml->documentElement);
	  $lastmod = 0;
	  btw("Writing tweets.xml");
	  ftruncate($tweets_xml, 0);
	  fwrite($tweets_xml, $xmlstr, strlen($xmlstr));
	  $html = transformTweets($xml);
	  generateJS($html);
	} catch(Exception $ex) {
	  btw($ex->getMessage());
	}
	flock($tweets_xml, LOCK_UN);
      } else btw("Couldn't get exclusive lock");
    }
    if (!$xmlstr) {
      btw("Reading cached tweets.xml");
      flock($tweets_xml, LOCK_SH);
      $stat = fstat($tweets_xml);
      $xmlstr = fread($tweets_xml, $stat['size']);
      flock($tweets_xml, LOCK_UN);
    }
    fclose($tweets_xml);
    $now = time();
    header("Content-type: application/xml; charset=utf-8");
    header("Last-Modified: " . gmstrftime("%A %d-%b-%y %T %Z", $lastmode?$lastmod:$now));
    header("Expires: " . gmstrftime("%A %d-%b-%y %T %Z", $now + $MAX_AGE));
    header("Cache-control: public,max-age=" . $MAX_AGE);
    echo '<' . '?xml version="1.0" encoding="utf-8" ?' . ">\n";
    echo "<!--\n\t" . join("\n\t", $comments) . "\n-->\n";
    echo $xmlstr . "\n";
  }
}

function btw($c) {
  global $comments;
  //echo $c . "\n";
  $comments[]=$c;
}

function fetchTweets() {
  global $TWEETS_XML;
  btw("Fetching Live Tweets");
  $start = time();
  $connection = new TwitterOAuth(CONSUMER_KEY, CONSUMER_SECRET, OAUTH_TOKEN, OAUTH_TOKEN_SECRET);
  $connection->connecttimeout = 3;  // don't wait too long
  $connection->format = 'xml';
  $tweets = $connection->get('statuses/user_timeline', array('trim_user'=>1, 'include_entities'=>0));
  $hc = $connection->http_code;
  if ($hc != 200)
    throw new Exception("Fail Whale: HTTP " . ($hc?$hc:'timeout') . ' (after ' . (time() - $start) . ' seconds)', $hc);
  $xml = new DomDocument();
  $xml->loadXML($tweets);
  return $xml;
}

function filterTweets($xml) {
  $tweets = $xml->getElementsByTagName('status');
  if ($tweets.length) {
    $t = $tweets[0];
    while ($t) {
      $text = $t->getElementsByTagName('text')->item(0)->nodeValue;
      $next = $t->nextSibling;
      if (substr($text, 0, 4) == 'RT @') {
	    $t->parentNode->removeChild($t);
	    btw("Removed " . substr($text, 0, 40));
      } else
	    btw("Keeping " . substr($text, 0, 40));
      $t = $next;
    }
  }
}

function transformTweets($xml) {
  btw("Transforming twitter XML to HTML");
  $xsl = new DomDocument();
  $xsl->load('./tweets.xsl');

  $xp = new XsltProcessor();
  $xp->importStylesheet($xsl);
  $html = $xp->transformToDoc($xml);
  $html->preserveWhiteSpace = FALSE;
  btw("Writing tweets.html");
  $html->saveHTMLFile('./tweets.html');
  return $html->saveHTML();
}

### generates a javascript file that calls a callback function, passing a string with the twitter xml
### useful for calling from another hostname, similar to jsonp
function generateJS($html) {
  btw("Wrapping HTML into Javascript");
  $js = str_replace("\\", "\\\\", trim($html));
  $js = str_replace("'", "\\'", $js);
  $js = str_replace("\r", '', $js);
  $js = preg_replace('/\s{2,}/', ' ', $js);
  $js = preg_replace('/ ?<article/', "\n<article", $js);
  $js = preg_replace('/ ?<\/section/', "\n</section", $js);
  $js = "'" . implode("',\n\t'", explode("\n", $js)) . "'";
  $js = "tweets([" . $js . "].join(\"\\n\"));\n";
  if (($f = fopen('./tweets.js', 'w'))) {
    btw("Writing tweets.js");
    fwrite($f, $js, strlen($js));
    fclose($f);
  }
}

try {
  main();
  btw("All done.");
} catch(Exception $ex) {
  header("Status: 500 Something Bad Happened");
  echo $ex->getMessage() . "\n";
}
?>
