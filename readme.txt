=== Mendeley Plugin ===
Contributors: kochm
Donate link: http://www.kooperationssysteme.de/produkte/wpmendeleyplugin/
Tags: bibliography, mendeley
Requires at least: 2.8
Tested up to: 4.0
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
&#91;mendeley type="groups" id="xxx" groupby="xxx" filter="author=Michael Koch"&#93;
&#91;mendeley type="groups" id="xxx" groupby="xxx" filter="author=Michael Koch;type=journal"&#93;
&#91;mendeley type="groups" id="xxx" groupby="xxx" filter="type=book_section"&#93;
&#91;mendeley type="groups" id="xxx" groupby="xxx" csl="http://DOMAINNAME/csl/custom_style.csl"&#93;

- the attribute "type" can be set to "folders" or "groups" or "own"
- the attribute "groupby" is optional; possible values currently are: "authors", "year"
- the attribute "sortby" is optional; possible values currently are: "authors", "year"
- the attributes "sortbyorder" and "groupbyorder" can have the values "asc" and "desc"
- sorting on the sort key is done before grouping on the group key if both are provided
- in "filter" one or more equal matches can be filtered for; if more than one filter rule is specified, than documents are displayed only when all filter rules match 
- possible attribute names to filter for are: type, title, year, author, editor, publisher, tag, keyword, abstract (when filtering for tag or keyword, a substring search is performed, so "blog" also matches "microblog")
- possible values for attribute type: ['journal' or 'book' or 'generic' or 'book_section' or 'conference_proceedings' or 'working_paper' or 'report' or 'web_page' or 'thesis' or 'magazine_article' or 'statute' or 'patent' or 'newspaper_article' or 'computer_program' or 'hearing' or 'television_broadcast' or 'encyclopedia_article' or 'case' or 'film' or 'bill']
- the attribute "csl" is optional; the value must contain a valid URL with a .csl file
</pre>

<h3>Changes in v1.0 (December 2014)</h3>

In v1.0 of the plugin there are some important changes due to support
for the new Mendeley API.

The most important issue is that ids of groups and folders now have to
be uuids - so, you have to look up the ids again - e.g. using the list
feature in the settings page of the plugin in the Wordpress backend.

Additionally, some attribute names and possible values have been
changed - so for example filters for type have to be changed.

We now also no longer support the outdated collection types "shared",
"sharedcollections", "collections" - Only "groups" and "folders" are
supported.

<h3>Known Problems</h3>

The current version of the plugin (v1.0) has one known problem with
requesting and authorizing access tokens: When the activity is
triggered in the backend using the Safari browser, the redirection to
the Mendeley API site might not work (blank page) - If this is the
case for you, please try with a different browser. For us Firefox has
always worked.

<h3>Formatting</h3>

If you do not specify a CSL stylesheet, full entries are formatted the following way - so, the style can be tailored using CSS. 
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

Additionally, there are widgets to display the content of collections or shared collections in widget areas of a theme (list of titles with links only).
The output in the widgets is formatted the following way:
<pre>
    &lt;ul class="wpmlist"&gt;
	&lt;li class="wpmlistref"&gt;
	title (if url is defined, then this title is linked to url)
	&lt;/li&gt;
	...
	&lt;/ul&gt;
</pre>

The title will additionally be equipped with a div-tag including the
full reference in the title attribute - which will in most browsers
display the full reference when you are hoovering over the title with
the mouse pointer.

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
<li> then you are redirected to the Mendeley web site to authorize the request, and redirected back to the blog (see Known Problems when this step does not work)
<li> now you can use shortcodes in your pages and blogs
</ol> 

<h3>Details presentation</h3>

The document lists in the widgets only include the title of the
document and a link.  Per default the link is either the url attribute
of the document - or if not set a link to the doi attribute in the
document if set.

To present more details for those list entries without leaving the web
site beginning in Version 0.8.1 you have two options:

1) if enabled in the configuration, the list items will include
mouseover tooltips showing the full reference - this can be
constructed in a simple default way or using CSL stylesheets.

2) if a details url is set in the configuration, all list items are linked to
      $DETAILSURL?docid=XXXX
You can create a details page on your Wordpress installation in the following way:
- create a page
- insert the [mendeleydetails]...[/mendeleydetails] shortcode in the page
- the text between the shortcodes is interpreted the following way:
  every tag "{attributename}" is replaced by the corresponding attribute in the
  Mendeley document
- if you only use the simple shortcode [mendeleydetails] then the plugin
  looks for the file "mendeley-details-template.tpl" in the plugin directory
  and interprets the content of this file in the same way

In addition to all attributes returned from Mendeley (abstract,
authors, doi, editors, translators, categories, identifiers, issue,
keywords, mendeley_url, pages, producers, publication_outlet,
published_in, tags, title, type, url, uuid, volume, year - also see
the Mendeley API documentation at
http://apidocs.mendeley.com/home/public-resources/search-details) you
can use the special attribute "full_reference" to insert a full
reference. The attribute can be annotated with a CSL url to do the
formatting according to a CSL stylesheet:
{full_reference,http://site/url.csl}

<h3>JSON data source</h3>

In version 0.7 we added the functionality to create a JSON data source
- e.g. to be used as data source in Exhibit/Simile application.

For example the page http://www.kooperationssysteme.de/pub/cscm/ uses
such a data source in an interactive JavaScript application to search
the references.

The following line is used in the Exhibit/Simile application:

<link href="/index.php?mendeley_action=export-json&id=763&type=groups" type="application/json" rel="exhibit/data" />
 
In the directory examples you find a file bibexhibit.tpl.php that can
be placed in your Wordpress theme directory. Then you can create a new
page with the Template BibExhibit that will show the application with
the data source.

<h3>CSL integration</h3>

When you specify a CSL (citation style language) stylesheet via the csl parameter, 
the formatting specified in the stylesheet is used to generate details.

A .csl file must be a XML formatted file, containing the style tags
defined by the citation Style language.  For further information of
CSL you should visit the citationstyles.org website. If you want to
use an existing CSL style, you should browse the zotero style
repository on zotero.org/styles. For creating your own CSL file, you
can either write an XML-file or use a visual editor
e.g. editor.citationstyles.org/visualEditor/.

We include some CSL files in the sub directory "style" - So, an easy
way to try the functionality could be to link to one of those files -
e.g. by an URL like the following:
http://YOURDOMAIN/wp-content/plugins/mendeleyplugin/style/apa.csl

For formatting entries via CLS, we are relying on the CiteProc.php
formatting engine by Ron Jerome, which usually is included in the
plugins distribution. (See https://bitbucket.org/rjerome/citeproc-php
for the original project.)

The library includes locale-files for adapting the output to different
languages.  We have only included the DE and EN locales here. Please
load additional locales from
https://bitbucket.org/rjerome/citeproc-php/src/ and add them in the
locale folder in the plugin folder.

<h3>Source Code Documentation</h3>

The source code of the plugin is more or less documented. Here some
information about the overall structure:

The main function of the plugin is "formatCollection". This function
is called from different places in order to create a formatted list of
references. In this function
<ul>
<li>first the output cache ist checked (getOutputFromCache) - in this cache completely formatted outputs for queries are stored
<li>then the list of documents is retrieved from Mendeley - depending on the type of collection (group or folder) - either by getDocumentListForFolder() oder getDocumentsForGroup()
<li>and then the documents are sorted and grouped according to the request (groupDocs)
<li>finally (in the loop producing the output) filter parameters are checked (checkFilter)
<li>the output for single references is produced using the functions formatDocumentShort() and formatDocument()
</ul>

The following Mendeley API calls are used
<ul>
<li>retrieving document list from a group: /documents?group_id=GROUPID
<li>retrieving document list from a folder: /folders/FOLDERID/documents; Iterate /documents/DOCID
</ul>

<h3>Thanks ...</h3>

Thanks for contributions to Rhodri Cusack and Matthias Budde.

Thanks for contributing to the CSL integration in V0.8 to Philipp Plagemann, Claudia Armbruster and Martin Wandtke.
Thanks for contributing to the details display in V0.8.1 to Björn Trappe.

== Installation ==

<ol>
<li> Upload archive contents to the `/wp-content/plugins/` directory
<li> Activate the plugin through the 'Plugins' menu in WordPress
<li> Obtain Client Id and Client Secret from Mendeley (see http://dev.mendeley.com/applications/register/) - When asked for a callback/redirection URI, specify the URL provided on the options page of the plugin
<li> Configure your settings (especially enter Client Id and Client Secret obtained from Mendeley), and press "Request Access Token"
</ol>

<p>The plugin assumes that curl support is available in your PHP
engine (extensions/modules curl and php_curl installed). If you see
error messages like "Call to undefined function curl_init()" most
likely curl support is not enabled. You can check by creating a .php
file with a phpinfo(); command in it. Browse to this and search/look
for curl on the resulting page. If support is enabled, there will be a
listing for it.</p>

<p>There are some reported problems with other plugins that are using
the OAuth PHP library like tweetblender: If the other plugin does not
check if the library is already loaded (as ours does), initializing
the other plugins after wp_mendeley will result in an error
message. In this case deactivate the other plugin.</p>


== Upgrade Notice ==

To upgrade the plugin, just deactivate the plugin, overwrite the
plugin directory, and reactivate it - or use the automatic upgrade
mechanism in WordPress.

== Frequently Asked Questions ==

What PHP extensions do I need on my Web server to make the plugin work?

Only need the CURL (and the php_curl) extension seems to be missing on
some server systems. Everything else is standard.  Use phpinfo(); to
check what extensions are available on your server.

How can I contribute to the development of the plugin?

The plugin is hosted on Google Code:
http://code.google.com/p/wp-mendeley-plugin/ - You may check out the
newest code from there - and ask for being added as a developer to the
repository to upload bug fixes and other additions.

== Screenshots ==

== Change log ==

= 1.0.4 (21.01.2015)
* corrected problems with url / websites attribute (especially for CSL formatting)

= 1.0.3 (16.01.2015)
* corrected problems with type mapping when using CSL formatting

= 1.0.2 (08.01.2015)
* corrected bug that resulted in returning only 20 results (from more) for some queries (thanks to poundsixzeros for the bugfix)

= 1.0.1 (03.01.2015)
* corrected problem with single tag filters (thanks to invisigoth99 for the bugfix)
* added "own" type again (also thanks to invisigoth99)

= 1.0.0 (10.12.2014)
* major migration to new Mendeley API

= 0.9.6 (25.6.2014)
* bugfix: sometimes sortorder=desc and asc were misinterpreted - should work now

= 0.9.5 (16.6.2014)
* removed support for OAuth1
* bugfix: sortorder=desc was ignored - should work now

= 0.9.4 (21.2.2014)
* additional code for displaying error messages from CURL subsystem (regarding connecting to Mendeley API server)
* bugfix regarding authentification of OAuth2 refresh requests

= 0.9.3 (18.2.2014)
* bugfix concerning escape characters in OAuth2 client secrets (added stripslashes())
* added possibility to remove/delete OAuth2 access token

= 0.9.2 (18.2.2014)
* bugfix concerning formatting via csl style files

= 0.9.1 (15.2.2014)
* bugfix concerning loading of csl style files

= 0.9 (12.2.2014)
* Added support for OAuth2 authentication (since the Mendeley API no longer supports OAuth1)
* Support for OAuth1 remains in rudimentary form (if you already have authorized the plugin, you can continue to use it)

= 0.8.8 (30.01.2014)
* do not include log output in output caching
* bug fix: CSL formatting now works again (problem with missing elements in output e.g. journal title)

= 0.8.7 (29.01.2014)
* refactoring of filtering code to deal with minor problems

= 0.8.6 (29.01.2014)
* corrected name of "My Publications" widget
* again set maximum number of documents (to 10000) since omitting this value sometimes defaults to 10 ...

= 0.8.5 (29.01.2014)
* using cURL to load CSL files (instead of get_file_content)
* added error message when loading CSL file fails

= 0.8.4
* updated CiteProc library (for formatting CSL) to version from 15.3.2013
* completely removed number of documents limit when reading from Mendeley
* bug fix: filtering by tags and keywords now works again

= 0.8.3
* bug fixes
* changed maximum number of documents to be retrieved from Mendeley from 1000 to 10000

= 0.8.2
* added support for filtering for more than one attribute at once

= 0.8.1
* added support for displaying details pages for references directly in Wordpress

= 0.8
* added support for formatting entries via CSL (Citation Style Language)
* added tooltip display of full reference in widget lists
* do not insert error responses in cache database

= 0.7.8 =
* initializeDatabase only checks for existence of table if db_version is wrong
* added index in database to optimize queries

= 0.7.7 =
* sorting by year or other attributes now does sub-sorting by add-date, so the order might be correct in the years

= 0.7.6 =
* corrected problem with incorrect JSON encoding (replaced calls to addslashes() with calls to json_encode())

= 0.7.5 =
* if no url is defined, but a doi is defined, a dx.doi.org/... url is set
* corrected bug when sorting lists by author

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
