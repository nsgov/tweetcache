<?xml version="1.0" encoding="utf-8"?>
<xsl:transform version="1.0"
	       xmlns:xsl="http://www.w3.org/1999/XSL/Transform"
	       xmlns:map="http://gov.ns.ca/xmlns/map"
	       exclude-result-prefixes="map">
<xsl:output method="html" omit-xml-declaration="yes" indent="no"/>

<map:months>
  <map:month abbr="Jan" value="01"/>
  <map:month abbr="Feb" value="02"/>
  <map:month abbr="Mar" value="03"/>
  <map:month abbr="Apr" value="04"/>
  <map:month abbr="May" value="05"/>
  <map:month abbr="Jun" value="06"/>
  <map:month abbr="Jul" value="07"/>
  <map:month abbr="Aug" value="08"/>
  <map:month abbr="Sep" value="09"/>
  <map:month abbr="Oct" value="10"/>
  <map:month abbr="Nov" value="11"/>
  <map:month abbr="Dec" value="12"/>
</map:months>

<xsl:template match="/statuses">
  <section id="twitterBox">
    <h1 class="col1Headline"><a href="http://twitter.com/nsgov">Nova Scotia on Twitter</a></h1>
    <xsl:apply-templates select="status[position() &lt; 4]"/>
  </section>
</xsl:template>

<xsl:template match="status">
  <xsl:variable name="tweetId" select="id"/>
  <xsl:variable name="month_day" select="substring(created_at, 5, 6)"/>
  <xsl:variable name="month_abbr" select="substring($month_day, 1, 3)"/>
  <xsl:variable name="mm"><xsl:value-of select="document('')/*/map:months/map:month[@abbr=$month_abbr]/@value"/></xsl:variable>
  <xsl:variable name="yyyy_mm_dd"><xsl:value-of select="substring(created_at, 27)"/>-<xsl:value-of select="$mm"/>-<xsl:value-of select="substring($month_day, 5)"/></xsl:variable>
  <xsl:variable name="hh_mm_ss" select="substring(created_at, 12, 8)"/>
  <xsl:variable name="datetime"><xsl:value-of select="$yyyy_mm_dd"/>T<xsl:value-of select="$hh_mm_ss"/>+00:00</xsl:variable>
  <article class="tweet">
    <p class="tweetContent"><xsl:copy-of select="text/node()"/></p>
    <footer class="tweetMeta">
      <a class="twtr-timestamp" href="http://twitter.com/nsgov/status/{$tweetId}"
	 ><time datetime="{$datetime}" pubdate="pubdate"><xsl:value-of select="$month_day"/></time></a> ·
      <a class="twtr-reply" href="http://twitter.com/intent/tweet?in_reply_to={$tweetId}">reply</a> · 
      <a class="twtr-rt"    href="http://twitter.com/intent/retweet?tweet_id={$tweetId}">retweet</a> · 
      <a class="twtr-fav"   href="http://twitter.com/intent/favorite?tweet_id={$tweetId}">favorite</a> 
    </footer>
  </article>

</xsl:template>

</xsl:transform>