=== Mendeley Plugin ===
Contributors: kochm
Donate link: http://www.kooperationssysteme.de/produkte/wpmendeleyplugin/
Tags: bibliography, mendeley
Requires at least: 2.8
Tested up to: 3.3.1
Stable tag: trunk

Mendeley Plugin for WordPress is a plugin for displaying information from the Mendeley "shared bibliography system" in WordPress blogs.

== Description ==

Mendeley Plugin for WordPress is a plugin for displaying information from the Mendeley "shared bibliography system" (www.mendeley.com) in WordPress blogs.

Using the public API from Mendeley, meta-information on documents in personal, public or shared collections is loaded and formatted as bibliographic entries.

The lists can be included in posts or pages using WordPress shortcodes:
<pre>
&#91;mendeley type="folders" id="xxx" groupby="xxx"&#93;
&#91;mendeley type="groups" id="xxx" groupby="xxx"&#93;
&#91;mendeley type="groups" id="xxx" sortby="xxx" sortbyorder="xxx"&#93;
&#91;mendeley type="groups" id="xxx" groupby="xxx"&#93;
&#91;mendeley type="groups" id="xxx" groupby="xxx" filter="author=Michael Koch"&#93;
&#91;mendeley type="documents" id="authored" groupby="year"&#93;
&#91;mendeley type="documents" id="authored" filter="tag=perceptualorganization"&#93;
&#91;mendeley type="documents" id="authored" sortby="authors" sortbyorder="asc" groupby="year" grouporder="desc"%#93;
&#91;mendeley type="own"%#93;

- the attribute "type" can be set to "own", "folders", "groups", "documents"
- the attribute "groupby" is optional; possible values currently are: "authors", "year"
- the attribute "sortby" is optional; possible values currently are: "authors", "year"
- the attributes "sortbyorder" and "groupbyorder" can have the values "asc" and "desc"
- sorting on the sort key is done before grouping on the group key if both are provided
- possible attributes to filter for are: author, editor, title, year, tag, keyword, url, publication_outlet, pages, issue, volume, city, publisher, abstract
</pre>

Additionally, there are widgets to display the content of collections or shared collections in widget areas of a theme.

The entries are formatted the following way - so, the style can be tailored using CSS. 
<pre>
    &lt;h2 class="wpmgrouptitle"&gt;grouptitle&lt;/h2&gt;
	&lt;p class="wpmref"&gt;
	   &lt;span class="wpmauthors"&gt;$authors&lt/span&gt;
	   &lt;span class="wpmyear"&gt;($year)&lt;/span&gt;: 
	   &lt;span class="wpmtitle"&gt;$title&lt;/span&gt;
	   , &lt;span class="wpmoutlet"&gt;$publication_outlet&lt;/span&gt;
	   &lt;span class="wpmvolume"&gt;$volume&lt;/span&gt;&lt;span class="wpmissue"&gt;($issue)&lt;/span&gt;
	   , &lt;span class="wpmeditors"&gt;$editors&lt;/span&gt;
	   , &lt;span class="wpmpages"&gt;$pages&lt;/span&gt;
	   , &lt;span class="wpmpublisher"&gt;$city: $publisher&lt;/span&gt;
	   , &lt;span class="wpmurl"&gt;&lt;a target="_blank" href="$url"&gt;&lt;span class="wpmurl$urltxt"&gt;$urltxt&lt;&gt;/span&lt;/a&gt&lt;/span&gt;
	&lt;/p&gt;
</pre>
- urltxt is per default "url" or "pdf", "ps", "zip" if this is the extension of the file referenced by the url

By adding some lines in your style sheets, you can format the output according to your needs. One example:
<pre>
.wpmref {
}

.wpmauthors {
color: #666666;
}
.wpmyear:after{
content: ": ";
}
.wpmyear {
color: #666666;
}
.wpmtitle:before{
content: "\"";
}
.wpmtitle:after{
content: "\"";
}

.wpmurlpdf:before {
content: url("/image/pdf.gif");
}

.wpmoutlet,
.wpmvolume,
.wpmissue,
.wpmpages {
font-style: italic;
color: #336633;
}
</pre>

The output in the widgets is formatted the following way:
<pre>
    &lt;ul class="wpmlist"&gt;
	&lt;li class="wpmlistref"&gt;
	title (if url is defined, then this title is linked to url)
	&lt;/li&gt;
	...
	&lt;/ul&gt;
</pre>

One of the next versions of the plugin will support a CSL (citation style language) based formatting.

You can use the plugin in non widgetized themes, just try
<pre>
echo $mendeleyPlugin->formatWidget("groups", 763, 10, array ('author' => 'Michael Koch'));
</pre>

For using the plugin you have to obtain an API key from Mendeley,
enter this Customer Key in the configuration section of the plugin,
and authorize the API. To do so the following steps have to be taken:
<ol>
<li> install plugin
<li> activate plugin
<li> get Customer Key and Customer Secret from http://dev.mendeley.com/
<li> enter the information in the wp-mendeley tab in the backend
<li> press "Get Access Key" on the wp-mendeley configuration page
<li> then you are redirected to the Mendeley web site to authorize the request, and redirected back to the blog
<li> now you can use shortcodes in your pages and blogs
</ol> 

<h3>JSON data source</h3>

In version 0.7 we added the functionality to create a JSON data source - e.g. to be used as data source in Exhibit/Simile application.

For example the page http://www.kooperationssysteme.de/pub/cscm/ uses such a data source in an interactive JavaScript application to search the references.

The following line is used in the Exhibit/Simile application:

<link href="/index.php?mendeley_action=export-json&id=763&type=groups" type="application/json" rel="exhibit/data" />
 
In the directory examples you find a file bibexhibit.tpl.php that can be placed in your Wordpress theme directory. Then you can create a new page with the Template BibExhibit that will show the application with the data source.

<h3>Thanks ...</h3>

Thanks for contributions to Rhodri Cusack and Matthias Budde.

== Installation ==

<ol>
<li> Upload archive contents to the `/wp-content/plugins/` directory
<li> Activate the plugin through the 'Plugins' menu in WordPress
<li> Configure your settings (especially enter Customer Key and Customer Secret obtained from Mendeley), and request Access Token
</ol>

<p>Please make sure that caching is switched on when accessing shared collections! There is currently an access rate limit of 150 requests per hour - and since we need one request for every document (for retrieving the details) this limit is reached quickly.</p>

<p>The plugin assumes that curl support is available in your PHP engine. If you see error messages like "Call to undefined function curl_init()" most likely curl support is not enabled. You can check by creating a .php file with a phpinfo(); command in it. Browse to this and search/look for curl on the resulting page. If support is enabled, there will be a listing for it.</p>

<p>There are some reported problems with other plugins that are using the OAuth PHP library like tweetblender: If the other plugin does not check if the library is already loaded (as ours does), initializing the other plugins after wp_mendeley will result in an error message. In this case deactivate the other plugin.</p>

<p>Tutorials / descriptions contributed by others:
<ul>
<li><a href="http://tramullas.com/2012/02/23/integrando-mendeley-en-wordpress-2/">Spanish tutorial about installing and using the plugin (v0.7)</a> by Jesús Tramullas</li>
</ul>
</p>

== Upgrade Notice ==

To upgrade the plugin, just deactivate the plugin, overwrite the plugin directory, and reactivate it - or use the automatic upgrade mechanism in WordPress.

== Frequently Asked Questions ==

How can I contribute to the development of the plugin?

The plugin is hosted on Google Code: http://code.google.com/p/wp-mendeley-plugin/ - You may check out the newest code from there - and ask for being added as a developer to the repository to upload bug fixes and other additions.

== Screenshots ==

== Change log ==

= 0.7.4 =
* corrected bug with output caching

= 0.7.3 =
* added output caching

= 0.7.2 =
* added possibility to display own publications (type = 'own')
* added widget for displaying own publications

= 0.7.1 =
* added link to spanisch tutorial
* bug fix in widgets: missing declaration ob global variable $mendeleyPlugin

= 0.7 =
* added support for creating JSON files with references on demand - e.g. to be used as a data source for Simile/Exhibit

= 0.6.7 =
* some bug fixes / additional handling regarding the new author data format

= 0.6.6 =
* adapted author handling to new way (forename, surname)

= 0.6.5 =
* minor bug fix (multiple URLs have been parsed using the same type)

= 0.6.4 =
* minor bug fixes (thanks to Matthias Budde for contributing)

= 0.6.3 =
* minor bug fix (saveguarded all access to arrays returned from API by is_array calls)

= 0.6.2 =
* adapted to Mendeley API changes: map "collections" and "sharedcollections" methods to "folders" and "groups"

= 0.6.1 =
* fixed bug in caching (which prevented the cache from updating)
* extensive tests on WordPress 3.1.1 and some minor bug fixes

= 0.6 =
* added support for Mendeley "groups" and Mendeley "documents" in addition to "collections" and "sharedcollections"
* added support for sorting without grouping and sorting within groups
* combined different widgets to one and added support for "groups" and "documents"

= 0.5.4 =
* added support to filter by editor, tag and keyword in shortcode option
* fixed bug in caching (which prevented the cache from updating)

= 0.5.3 =
* added support for displaying error messages from service

= 0.5.2 =
* corrected several bugs (that had to do with handling options)
* set caching to weekly as default option (due to rate limit restrictions of the api)

= 0.5.1 =
* corrected bug that used to overwrite access token with empty string after it was received and stored in database

= 0.5 =
* tested and debugged widget support
* provided widget support for non widgetized themes
* added functionality to filter for attributes in widget lists
* added functionality to filter for attributes in lists on pages (shortcode "mendeley")

= 0.4.1 =
* When displaying URLs, use different anchor texts for pdf, scribd, ...
* Load oauth library only when no other oauth library has been loaded before - to avoid a "Cannot redeclare class oauthconsumer" runtime error

= 0.4 =
* Support for additional document attributes (display journal issue, pages etc)
* Initial support for internationalization

= 0.3.1 (11.08.2010) =
* Corrected typo in source code
* More consistent and complete support for CSS formatting output
* Widgets now support display of latest / first x documents from collection

= 0.3.0 (11.08.2010) =
* Added support for caching the data requested from Mendeley in the Wordpress database

= 0.2.0 =
* Added support for widgets

= 0.1.0 =
* First release
