<?php
declare(strict_types=1);

// parse_feed(): RSS 2.0 with namespaced extensions.
$rss = <<<XML
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
    <title>RSS Test</title>
    <link>https://example.net/</link>
    <item>
      <title>Post 1</title>
      <link>https://example.net/1</link>
      <guid>https://example.net/1</guid>
      <content:encoded><![CDATA[<p>Rich <em>body</em></p>]]></content:encoded>
      <dc:creator>Grace</dc:creator>
      <pubDate>Wed, 30 Sep 2026 10:00:00 GMT</pubDate>
    </item>
    <item>
      <title>Post 2</title>
      <link>https://example.net/2</link>
      <description>Plain description</description>
    </item>
  </channel>
</rss>
XML;
$parsed = parse_feed($rss);
check('rss feed title', 'RSS Test', $parsed['title']);
check('rss site url', 'https://example.net/', $parsed['site_url']);
check('rss item count', 2, count($parsed['items']));
check('rss guid', 'https://example.net/1', $parsed['items'][0]['guid']);
check('rss content:encoded extracted', '<p>Rich <em>body</em></p>', $parsed['items'][0]['content']);
check('rss dc:creator extracted', 'Grace', $parsed['items'][0]['author']);
check('rss pubDate raw', 'Wed, 30 Sep 2026 10:00:00 GMT', $parsed['items'][0]['pub_date']);
check('rss guid falls back to link', 'https://example.net/2', $parsed['items'][1]['guid']);
check('rss description used as content', 'Plain description', $parsed['items'][1]['content']);

// parse_feed(): RDF / RSS 1.0 (items at root level, channel metadata).
$rdf = <<<XML
<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#" xmlns="http://purl.org/rss/1.0/" xmlns:dc="http://purl.org/dc/elements/1.1/">
  <channel>
    <title>RDF Test</title>
    <link>https://example.org/</link>
  </channel>
  <item>
    <title>Item 1</title>
    <link>https://example.org/1</link>
    <description>Body</description>
    <dc:creator>Grace</dc:creator>
    <dc:date>2026-10-02T10:00:00Z</dc:date>
  </item>
</rdf:RDF>
XML;
$parsed = parse_feed($rdf);
check('rdf feed title', 'RDF Test', $parsed['title']);
check('rdf site url', 'https://example.org/', $parsed['site_url']);
check('rdf item count', 1, count($parsed['items']));
check('rdf guid falls back to link', 'https://example.org/1', $parsed['items'][0]['guid']);
check('rdf dc:creator extracted', 'Grace', $parsed['items'][0]['author']);
check('rdf dc:date extracted', '2026-10-02T10:00:00Z', $parsed['items'][0]['pub_date']);

// parse_feed(): Atom.
$atom = <<<XML
<feed xmlns="http://www.w3.org/2005/Atom">
  <title>Atom Test</title>
  <link rel="alternate" href="https://example.com/"/>
  <entry>
    <id>tag:example.com,2026:1</id>
    <title>Entry 1</title>
    <link rel="alternate" href="https://example.com/1"/>
    <content type="html">&lt;p&gt;Hello&lt;/p&gt;</content>
    <author><name>Ada</name></author>
    <published>2026-10-01T09:00:00Z</published>
  </entry>
</feed>
XML;
$parsed = parse_feed($atom);
check('atom feed title', 'Atom Test', $parsed['title']);
check('atom site url from alternate link', 'https://example.com/', $parsed['site_url']);
check('atom item count', 1, count($parsed['items']));
check('atom id as guid', 'tag:example.com,2026:1', $parsed['items'][0]['guid']);
check('atom link from alternate', 'https://example.com/1', $parsed['items'][0]['url']);
check('atom html content decoded', '<p>Hello</p>', $parsed['items'][0]['content']);
check('atom author name', 'Ada', $parsed['items'][0]['author']);
check('atom published date', '2026-10-01T09:00:00Z', $parsed['items'][0]['pub_date']);

// parse_feed(): invalid XML yields an empty result, never a crash.
check('invalid xml yields no items', 0, count(parse_feed('not xml at all')['items']));

// parse_opml(): nested outlines, attribute fallbacks, entries without xmlUrl.
$opml = <<<XML
<opml version="2.0">
  <head><title>subs</title></head>
  <body>
    <outline text="News">
      <outline text="Feed A" xmlUrl="https://a.example/feed" htmlUrl="https://a.example/"/>
      <outline title="Feed B" xmlUrl="https://b.example/feed"/>
    </outline>
    <outline text="No URL"/>
    <outline text="Feed C" xmlUrl="https://c.example/feed"/>
  </body>
</opml>
XML;
$feeds = parse_opml($opml);
check('opml feed count', 3, count($feeds));
check('opml title from text attr', 'Feed A', $feeds[0]['title']);
check('opml feed url', 'https://a.example/feed', $feeds[0]['feed_url']);
check('opml site url', 'https://a.example/', $feeds[0]['site_url']);
check('opml title attr preferred', 'Feed B', $feeds[1]['title']);
check('opml missing htmlUrl yields empty', '', $feeds[1]['site_url']);
check('opml flat outline parsed', 'Feed C', $feeds[2]['title']);

// build_opml(): round-trip structure (checked via XPath so the app name in
// the head, which depends on local config, does not matter).
$xml = build_opml([
    ['title' => 'Feed A', 'feed_url' => 'https://a.example/feed', 'site_url' => 'https://a.example/'],
    ['title' => 'Feed B', 'feed_url' => 'https://b.example/feed', 'site_url' => null],
]);
$doc = new DOMDocument();
$loaded = $doc->loadXML($xml);
check('opml export is valid xml', true, $loaded);
$xp = new DOMXPath($doc);
check('opml export version', '2.0', $doc->documentElement->getAttribute('version'));
check('opml export outline count', 2, (int)$xp->evaluate('count(/opml/body/outline)'));
check('opml export xmlUrl A', 'https://a.example/feed', (string)$xp->evaluate('string(/opml/body/outline[1]/@xmlUrl)'));
check('opml export htmlUrl A', 'https://a.example/', (string)$xp->evaluate('string(/opml/body/outline[1]/@htmlUrl)'));
check('opml export text B', 'Feed B', (string)$xp->evaluate('string(/opml/body/outline[2]/@text)'));
check('opml export no htmlUrl when absent', '', (string)$xp->evaluate('string(/opml/body/outline[2]/@htmlUrl)'));
