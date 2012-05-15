<?php
/*
Plugin Name: Mendeley Plugin
Plugin URI: http://www.kooperationssysteme.de/produkte/wpmendeleyplugin/
Version: 0.7.4

Author: Michael Koch
Author URI: http://www.kooperationssysteme.de/personen/koch/
License: http://www.opensource.org/licenses/mit-license.php
Description: This plugin offers the possibility to load lists of document references from Mendeley (shared) collections, and display them in WordPress posts or pages.
*/

/* 
The MIT License

Copyright (c) 2010-2012 Michael Koch (email: michael.koch@acm.org)
 
Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in
all copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
THE SOFTWARE.
*/

if (!class_exists("OAuthConsumer")) {
	require_once "oauth/OAuth.php"; 
}

define( 'REQUEST_TOKEN_ENDPOINT', 'http://www.mendeley.com/oauth/request_token/' );
define( 'ACCESS_TOKEN_ENDPOINT', 'http://www.mendeley.com/oauth/access_token/' );
define( 'AUTHORIZE_ENDPOINT', 'http://www.mendeley.com/oauth/authorize/' );
define( 'MENDELEY_OAPI_URL', 'http://api.mendeley.com/oapi/' );

define( 'PLUGIN_VERSION' , '0.7' );
define( 'PLUGIN_DB_VERSION', 1 );

// JSON services for PHP4
if (!function_exists('json_encode')) {
	include_once('json.php');
	$GLOBALS['JSON_OBJECT'] = new Services_JSON();
	function json_encode($value) {
		return $GLOBALS['JSON_OBJECT']->encode($value);
	}
	function json_decode($value) {
		return $GLOBALS['JSON_OBJECT']->decode($value);
	}
}

if (!class_exists("MendeleyPlugin")) {
	class MendeleyPlugin {
		var $adminOptionsName = "MendeleyPluginAdminOptions";
		protected $options = null;
		protected $consumer = null;
		protected $acctoken = null;
		protected $sign_method = null;
		protected $error_message = "";
		function MendeleyPlugin() { // constructor
			$this->init();
		}
		function init() {
			$this->getOptions();
			$this->initializeDatabase();
			load_plugin_textdomain('wp-mendeley');
		}
		function sendAuthorizedRequest($url) {
			$this->getOptions();
			
			$request = OAuthRequest::from_consumer_and_token($this->consumer, $this->acctoken, 'GET', $url, array());
			$request->sign_request($this->sign_method, $this->consumer, $this->acctoken);

			// send request
			if ($this->settings['debug'] === 'true') {
				echo "<p>Request: ".$request->to_url()."</p>";
			}
			$resp = run_curl($request->to_url(), 'GET');
			if ($this->settings['debug'] === 'true') {
				echo "<p>Response:</p>";
				var_dump($resp);
			}

			$result = json_decode($resp);
			if (!is_null($result->error)) {
				echo "<p>Mendeley Plugin Error: " . $result->error . "</p>";
				$error_message = $result->error;
			}
			return $result;
		}
		
		function processShortcode($attrs = NULL) {
			return $this->formatCollection($attrs);
		}
		
		// format a set of references (folders, groups, documents)
		/* type = 'own' for own publications */
		function formatCollection($attrs = NULL, $maxdocs = 0, $style="standard") {
			$type = $attrs['type'];
			if (empty($type)) { $type = "folders"; }
			if (strlen($type)<1) { $type = "folders"; }
			// for upwards compatibility
			if ($type === "shared") { $type = "groups"; } 
			if ($type === "sharedcollections") { $type = "groups"; }
			if ($type === "collections") { $type = "folders"; }
			if ($type === "sharedcollection") { $type = "groups"; }
			if ($type === "collection") { $type = "folders"; }
			// map singular cases to plural
			if ($type === "folder") { $type = "folders"; }
			if ($type === "group") { $type = "groups"; }
			$id = $attrs['id'];
			if (empty($id)) { $id = 0; }
			$groupby = $attrs['groupby'];
			$grouporder = $attrs['grouporder'];
			if (empty($grouporder)) {
				$grouporder = "desc";
			}
			$sortby = $attrs['sortby'];
			$sortorder = $attrs['sortorder'];
			if (empty($sortorder)) {
				$sortorder = "asc";
			}
			if (empty($groupby)) {
				if (empty($sortby)) {
					$sortby = "year";
				}
			}
			$filter = $attrs['filter'];
			$filterattr = NULL;
			$filterval = NULL;
			if (isset($filter)) {
				if (strlen($filter)>0) {
					$filterarr = explode('=', $filter);
					$filterattr = $filterarr[0];
					if (isset($filterarr[1])) {
						$filterval = $filterarr[1];
					} else {
						$filterattr = NULL;
					}
				}
			}
			$maxtmp = $attrs['maxdocs'];
			if (isset($maxtmp)) {
				$maxdocs = intval($maxtmp);
				if ($maxdocs < 0) { $maxdocs = 0; }
			}

			$result = "";
			if ($this->settings['debug'] === 'true') {
				$result .= "<p>Mendeley Plugin: groupby = $groupby, sortby = $sortby, sortorder = $sortorder, filter = $filter</p>";
			}

			// output caching
			$cacheid = $type."-".$id."-".$groupby.$grouporder."-".$sortby.$sortorder."-".$filterattr.$filterval."-".$maxdocs;
			$cacheresult = $this->getOutputFromCache($cacheid);
			if (!empty($cacheresult)) {
				return $result.$cacheresult;
			}

			// type can be own, folders, groups, documents
			$res = $this->getItemsByType($type, $id);
			// process the data
			$docarr = $this->loadDocs($res, $type, $id);
			if (isset($sortby)) {
				$docarr = $this->groupDocs($docarr, $sortby, $sortorder);
			}
			if (isset($groupby)) {
				$docarr = $this->groupDocs($docarr, $groupby, $grouporder);
			}
			$currentgroupbyval = "";
			$groupbyval = "";
			if ($this->settings['debug'] === 'true') {
				$result .= "<p>Mendeley Plugin: Unfiltered results count: " . count($docarr) . " ($error_message)</p>";
			} else {
				if (strlen($error_message)>0) {
					$result .= "<p>Mendeley Plugin: no results - error message: $error_message</p>";
					$error_message = "";
				}
			}
			foreach($docarr as $doc) {
				// check filter
				if (!is_null($filterattr)) {
					$filtertrue = $this->checkFilter($filterattr, $filterval, $doc);
					if ($filtertrue == 0) { continue; }
				}
				// check if groupby-value changed
				if (isset($groupby)) {
					$groupbyval = $doc->$groupby;
					if (!($groupbyval === $currentgroupbyval)) {
						$result = $result . '<h2 class="wpmgrouptitle">' . $groupbyval . '</h2>';
						$currentgroupbyval = $groupbyval;
					}
				}

				// currently, there are two styles, one for widgets ("shortlist") and the
				// standard one ("standard") 				
				if ($style === "shortlist") {
					$result .= '<li class="wpmlistref">' . $this->formatDocumentShort($doc) .  '</li>';
				} else {
					$result = $result . '<p class="wpmref">' . $this->formatDocument($doc) . '</p>';
				}

				if ($maxdocs > 0) {
					$count++;  
					if ($count > $maxdocs) break;
				}	
			}
			$this->updateOutputInCache($cacheid, $result);
			return $result;
		}		

		/* check if a given document ($doc) matches the given filter ($filterattr, $filterval)
		   return 1 if the check is true, 0 otherwise */
		function checkFilter($filterattr, $filterval, $doc) {
			if (strcmp($filterattr, 'author')==0) {
				$author_arr = $doc->authors;
				if (is_array($author_arr)) {
					$tmps = $this->comma_separated_names($author_arr);
                       			if (!(stristr($tmps, $filterval) === FALSE)) {
                               			return 1;
                       			}
				}
			} else if (strcmp($filterattr, 'editor')==0) {
                               	$editor_arr = $doc->editors;
				if (is_array($editor_arr)) {
					$tmps = $this->comma_separated_names($editor_arr);
                       			if (!(stristr($tmps, $filterval) === FALSE)) {
                               			return 1;
                       			}
                               	}
			} else if (strcmp($filterattr, 'tag')==0) {
                               	$tag_arr = $doc->tags;
				if (is_array($tag_arr)) {
                               		for($i = 0; $i < sizeof($tag_arr); ++$i) {
                               			if (!(stristr($tag_arr[$i], $filterval) === FALSE)) {
                               				return 1;
						}
                               		}
                               	}
			} else if (strcmp($filterattr, 'keyword')==0) {
                               	$keyword_arr = $doc->keywords;
				if (is_array($keyword_arr)) {
                               		for($i = 0; $i < sizeof($keyword_arr); ++$i) {
                               			if (!(stristr($keyword_arr[$i], $filterval) === FALSE)) {
                               				return 1;
						}
                               		}
                               	}
                        } else {
                               	// other attributes
                                if (strcmp($keyval, $doc->{$key})==0) {
					return 1;
                                }
			}
			return 0;
		}

		
		// One function handles own, documents, groups, folders, ...
		/* get the ids of all documents in a Mendeley collection
		   and return them in an array */
		function getItemsByType($type,$id) {
			if (is_null($id)) return NULL;
			// check cache
			$cacheid="library-$type-$id";
			$result = $this->getCollectionFromCache($cacheid);
			if (!is_null($result)) {
				$doc_ids = $result->document_ids;
				return $doc_ids;
			}
			$url = MENDELEY_OAPI_URL . "library/$type/$id/?page=0&items=1000";
			if ($type === "own") { 
				$url = MENDELEY_OAPI_URL . "library/documents/authored/?page=0&items=1000";
			}
			$result = $this->sendAuthorizedRequest($url);
			$this->updateCollectionInCache($cacheid, $result);
			$doc_ids = $result->document_ids;
			if (is_null($result->document_ids)) {
				$doc_ids = array(0 => $result->id);
			}
			return $doc_ids;
		}
		
		/* get all attributes (array) for a given document */
		function getDocument($docid, $fromtype, $fromid) {
			if (is_null($docid)) return NULL;
			// check cache
			$result = $this->getDocumentFromCache($docid);
			if (!is_null($result)) return $result;
			$url = MENDELEY_OAPI_URL . "library/documents/$docid/";
			if ($fromtype === "groups") {
				$url = MENDELEY_OAPI_URL . "library/groups/$fromid/$docid/";
			}
			$result = $this->sendAuthorizedRequest($url);
			$this->updateDocumentInCache($docid, $result);
			return $result;
		}
		/* get the ids/names of all groups for the current user */
		function getGroups() {
			$url = MENDELEY_OAPI_URL . "library/groups/?items=1500";
			$result = $this->sendAuthorizedRequest($url);
			return $result;
		}
		function getSharedCollections() {
			$url = MENDELEY_OAPI_URL . "library/groups/?items=1500";
			$result = $this->sendAuthorizedRequest($url);
			return $result;
		}
		/* get the ids/names of all folders for the current user */
		function getFolders() {
			$url = MENDELEY_OAPI_URL . "library/folders/?items=1500";
			$result = $this->sendAuthorizedRequest($url);
			return $result;
		}
		function getCollections() {
			$url = MENDELEY_OAPI_URL . "library/folders/?items=1500";
			$result = $this->sendAuthorizedRequest($url);
			return $result;
		}

		/* get the meta information (array) for all document ids in
		   the array given as an input parameter */
		function loadDocs($docidarr, $fromtype, $fromid, $count=0) {
			$res = array();
			if ($count == 0) { $count = sizeof($docidarr); }
			for($i=0; $i < $count; $i++) {
				$docid = $docidarr[$i];
				$doc = $this->getDocument($docid, $fromtype, $fromid);
				$res[] = $doc;
			}
			return $res;
		}
		
		/* sort and group the documents that have been loaded before using loadDocs,
		   i.e. $docarr holds an array of meta information arrays, after
		   the function ran, the meta information arrays (document objects)
		   will be grouped according to the groupby parameter. */
		function groupDocs($docarr, $groupby, $order) {
			$grpvalues = array();
			for($i=0; $i < sizeof($docarr); $i++) {
				$doc = $docarr[$i];
				if (isset($doc->$groupby)) {
					$grpval = $doc->$groupby;
					// If array (like authors, take the first one)
					if (is_array($grpval)) {
						$grpval=$grpval[0]->__toString();
					}
				}
				if (isset($grpval)) {
					$grpvalues[$grpval][] = $doc;
				}
			}

			if (startsWith($order, "desc", false)) { 
				krsort($grpvalues);
			}
			else {
				ksort($grpvalues);
			}
			// linearize results
			$result = array();
			foreach ($grpvalues as $arr) {
				foreach ($arr as $doc) {
					$result[] = $doc;
				}
			}
			return $result;
		}
		
		/* produce the output for one document */
		/* the following attributes are available in the doc object
			type (Book Section, Conference Proceedings, Journal Article, Report, Book, Encyclopedia Article, ...)
			title
			year
			authors*
			editors*
			tags*
			keywords*
			identifiers* (issn,...)
			url
			discipline*
			subdiscipline
			publication_outlet
			published_in
			pages
			issue
			volume
			city
			publisher
			abstract
			id
			mendeley_url
			canonical_id
			files
		*/
		function formatDocument($doc) {
			$author_arr = $doc->authors;
			$authors = "";
			if (is_array($author_arr)) {
				$authors = $this->comma_separated_names($author_arr);
			}
			$editor_arr = $doc->editors;
			$editors = "";
			if (is_array($editor_arr)) {
				$editors = $this->comma_separated_names($editor_arr);
			}
			if (strlen($authors)<1) {
				if (strlen($editors)>0) {
					$authors = $editors . " (ed.)";
					$editors = "";
				}
			}
			$tmps = '<span class="wpmauthors">' . $authors . '</span> ' .
			        '<span class="wpmyear">(' . $doc->year . ')</span> ' . 
			        '<span class="wpmtitle">' . $doc->title . '</span>';
			if (isset($doc->publication_outlet)) {
				$tmps .= ', <span class="wpmoutlet">' . 
				    $doc->publication_outlet . '</span>';
			}
			if (isset($doc->volume)) {
				$tmps .= ' <span class="wpmvolume">' . $doc->volume . '</span>';
			}
			if (isset($doc->issue)) {
				$tmps .= '<span class="wpmissue">(' . $doc->issue . ')</span>';
			}
			if (isset($doc->editors)) {
				if (strlen($editors)>0) {
					$tmps .= ', <span class="wpmeditors">' . $editors . ' (' . __('ed.','wp-mendeley') . ')</span>';
				}
			}
			if (isset($doc->pages)) {
				$tmps .= ', <span class="wpmpages">' . __('p.','wp-mendeley') . ' ' . $doc->pages . '</span>';
			}
			if (isset($doc->publisher)) {
				if (isset($doc->city)) {
					$tmps .= ', <span class="wpmpublisher">' . $doc->city . ': ' . $doc->publisher . '</span>';
				} else {
					$tmps .= ', <span class="wpmpublisher">' . $doc->publisher . '</span>';
				}
			}
			if (isset($doc->url)) {
				$item=0;
				foreach(explode(chr(10),$doc->url) as $urlitem) {
					// determine the text for the anchor
					$atext = "url";
					if (strpos($urlitem, "www.pubmedcentral.nih.gov", false)) { $atext = "pubmed central"; }
					if (strpos($urlitem, "ncbi.nlm.nih.gov/pubmed", false)) { $atext = "pubmed"; }
					// TBD: add support to use icons instead of text
					// TBD: add support to further configure output
					if (endsWith($urlitem, "pdf", false)) { $atext = "pdf"; }
					if (endsWith($urlitem, "ps", false)) { $atext = "ps"; }
					if (endsWith($urlitem, "zip", false)) { $atext = "zip"; }
					if (startsWith($urlitem, "http://www.youtube", false)) { $atext = "watch on youtube"; }
					if (startsWith($urlitem, "http://www.scribd.com", false)) { $atext = "scribd"; }
					$tmps .= ', <span class="wpmurl"><a target="_blank" href="' . $urlitem . '"><span class="wpmurl' . $atext . '">' . $atext . '</span></a></span>';
					$item += 1;
				}
			}
			return $tmps;
		}
		
		function formatDocumentShort($doc) {
			$tmps = '<span class="wpmtitle">';
			if (isset($doc->url)) {
				$tmps .= '<a href="' .  $doc->url . '">' . $doc->title . '</a>';
			} else {
				$tmps .= '<a href="' . $doc->mendeley_url . '">' . $doc->title . '</a>';
			}
			$tmps .= '</span>';
			return $tmps;
		}

		/**
		 *
		 */
		function formatDocumentJSON($doc) {
			$tmps = "{\n" .'"type" : "Publication"' . ",\n";
			$tmps .= '"id" : "' . $doc->mendeley_url . '"' . ",\n";
			$tmps .= '"pub-type": "' . addslashes($doc->type) . '"' . ",\n"; 
			$tmps .= '"label" : "' . addslashes($doc->title) . '"' . ",\n";
			if (isset($doc->publication_outlet)) {
				$tmps .= '"booktitle" : "' . addslashes($doc->publication_outlet) . '"' . ",\n";
			}
			$tmps .= '"description": "' . addslashes($doc->abstract) . '"' . ",\n"; 

			$author_arr = $doc->authors;
			if (is_array($author_arr)) {
				$tmps .= '"author" : [ ' . "\n";
				for($i = 0; $i < sizeof($author_arr); ++$i) {
					if ($i > 0) { $tmps .= ', '; }
					$tmps .= '"' . addslashes($author_arr[$i]->forename) . ' ' . addslashes($author_arr[$i]->surname) . '"';
				}
				$tmps .= "\n],\n";
			}
			$editor_arr = $doc->editors;
			if (is_array($editor_arr)) {
				$tmps .= '"editor" : [ ' . "\n";
				for($i = 0; $i < sizeof($editor_arr); ++$i) {
					if ($i > 0) { $tmps .= ', '; }
					$tmps .= '"' . addslashes($editor_arr[$i]->forename) . ' ' . addslashes($editor_arr[$i]->surname) . '"';
				}
				$tmps .= "\n],\n";
			}
			$tag_arr = $doc->tags;
			if (is_array($tag_arr)) {
				$tags = "";
				for($i = 0; $i < sizeof($tag_arr); ++$i) {
					if ($i > 0) { $tags .= ', '; }
					$tags .= '"' . addslashes($tag_arr[$i]) . '"';
				}
				if (strlen($tags)>1) {
					$tmps .= '"tags" : [ ' . "\n" . $tags . "\n],\n";
				}
			}
			if (isset($doc->volume)) {
				$tmps .= '"volume" : "' . $doc->volume . '"' . ",\n";
			}
			if (isset($doc->issue)) {
				$tmps .= '"number" : "' . $doc->issue . '"' . ",\n";
			}
			if (isset($doc->pages)) {
				$tmps .= '"pages" : "' . $doc->pages . '"' . ",\n";
			}
			/*
			if (isset($doc->publisher)) {
				if (isset($doc->city)) {
					$tmps .= ', <span class="wpmpublisher">' . addslashes($doc->city) . ': ' . addslashes($doc->publisher) . '</span>';
				} else {
					$tmps .= ', <span class="wpmpublisher">' . addslashes($doc->publisher) . '</span>';
				}
			}
			*/
			if (isset($doc->url)) {
				$tmps .= '"url" : "' . addslashes($doc->url) . '"' . ",\n";
				// ...
			}
			$tmps .= '"year" : "' . $doc->year . '"' . "\n}\n";
			return $tmps;
		}


		/* create database tables for the caching functionality */
		/* database fields:
		     type = 0 (document), 1 (folder), 2 (group), 10 (output)
		     mid = Mendeley id as string
		     time = timestamp
		*/
		function initializeDatabase() {
			global $wpdb;
			$table_name = $wpdb->prefix . "mendeleycache";
			// check for table: if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) 
			// if ($this->settings['db_version'] < PLUGIN_DB_VERSION) {
			if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
				$sql = "CREATE TABLE " . $table_name . " (
						  id mediumint(9) NOT NULL AUTO_INCREMENT,
						  type mediumint(9) NOT NULL,
						  mid tinytext NOT NULL,
						  content text,
						  time bigint(11) DEFAULT '0' NOT NULL,
						  UNIQUE KEY id (id)
						);";
				require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
				dbDelta($sql);
				$this->settings['db_version'] = PLUGIN_DB_VERSION;
				update_option($this->adminOptionsName, $this->settings);
			}
		}
		/* check cache database */
		function getDocumentFromCache($docid) {
			global $wpdb;
			if ("$docid" === "") return NULL;
			if ($this->settings['cache_docs'] === "no") return NULL;
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=0 AND mid='$docid'");
			if ($dbdoc) {
				// check timestamp
				$delta = 3600;
				if ($this->settings['cache_docs'] === "day") { $delta = 86400; }
				if ($this->settings['cache_docs'] === "week") { $delta = 604800; }
				if ($dbdoc->time + $delta > time()) {
					return json_decode($dbdoc->content);
				}
			}
			return NULL;
		}
		function getFolderFromCache($cid) {
			global $wpdb;
			if ("$cid" === "") return NULL;
			if ($this->settings['cache_collections'] === "no") return NULL;
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=1 AND mid='$cid'");
			if ($dbdoc) {
				// check timestamp
				$delta = 3600;
				if ($this->settings['cache_collections'] === "day") { $delta = 86400; }
				if ($this->settings['cache_collections'] === "week") { $delta = 604800; }
				if ($dbdoc->time + $delta > time()) {
					return json_decode($dbdoc->content);
				}
			}
			return NULL;
		}
		function getCollectionFromCache($cid) {
			return $this->getFolderFromCache($cid);
		}
		function getOutputFromCache($cid) {
			global $wpdb;
			if ("$cid" === "") return NULL;
			if ($this->settings['cache_output'] === "no") return NULL;
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=10 AND mid='$cid'");
			if ($dbdoc) {
				// check timestamp
				$delta = 3600;
				if ($this->settings['cache_output'] === "day") { $delta = 86400; }
				if ($this->settings['cache_output'] === "week") { $delta = 604800; }
				if ($dbdoc->time + $delta > time()) {
					return $dbdoc->content;
				}
			}
			return NULL;
		}
		/* add data to database */
		function updateDocumentInCache($docid, $doc) {
			global $wpdb;
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=0 AND mid='$docid'");
			if ($dbdoc) {
				$wpdb->update($table_name, array('time' => time(), 'content' => json_encode($doc)), array( 'type' => '0', 'mid' => "$docid"));
				return;
			}
			$wpdb->insert($table_name, array( 'type' => '0', 'time' => time(), 'mid' => "$docid", 'content' => json_encode($doc)));
		}
		function updateFolderInCache($cid, $doc) {
			global $wpdb;
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=1 AND mid='$cid'");
			if ($dbdoc) {
				$wpdb->update($table_name, array('time' => time(), 'content' => json_encode($doc)), array( 'type' => '1', 'mid' => "$cid"));
				return;
			}
			$wpdb->insert($table_name, array( 'type' => '1', 'time' => time(), 'mid' => "$cid", 'content' => json_encode($doc)));
		}
		function updateCollectionInCache($cid, $doc) {
			return $this->updateFolderInCache($cid, $doc);
		}
		function updateOutputInCache($cid, $out) {
			global $wpdb;
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=10 AND mid='$cid'");
			if ($dbdoc) {
				$wpdb->update($table_name, array('time' => time(), 'content' => $out), array( 'type' => '10', 'mid' => "$cid"));
				return;
			}
			$wpdb->insert($table_name, array( 'type' => '10', 'time' => time(), 'mid' => "$cid", 'content' => $out));
		}

		function getOptions() {
			if ($this->settings != null)
				return $this->settings;
			$this->settings = array(
				'debug' => 'false',
				'cache_collections' => 'week',
				'cache_docs' => 'week',
				'cache_output' => 'day',
				'consumer_key' => '',
				'consumer_secret' => '',
				'req_token' => '',
				'req_token_secret' => '',
				'access_token' => '',
				'access_token_secret' => '',
				'version' => PLUGIN_VERSION,
				'db_version' => 0 );
			$tmpoptions = get_option($this->adminOptionsName);
			if (!empty($tmpoptions)) {
				foreach ($tmpoptions as $key => $option)
					$this->settings[$key] = $option;
			}
			update_option($this->adminOptionsName, $this->settings);
			// initialize some variables
			$consumer_key = $this->settings['consumer_key'];
            		$consumer_secret = $this->settings['consumer_secret'];
            		$this->consumer = new OAuthConsumer($consumer_key, $consumer_secret, NULL);
			$this->sign_method = new OAuthSignatureMethod_HMAC_SHA1();
			$acc_token = $this->settings['access_token'];
			$acc_token_secret = $this->settings['access_token_secret'];
			$this->acctoken = new OAuthConsumer($acc_token, $acc_token_secret, NULL);
			
			return $this->settings;
		}
		
		/**
		 * Concatenates two strings with $concat_str in between, if $x is not empty
		 * @param $concat_str - string, string which should be in between
		 * @param $x - string, first string
		 * @param $y - string, second string
		 * @return the combined string
		 */
		function concatenate($concat_str, $x, $y) {
			if (empty($x)) return $y;
			return $x . $concat_str . $y;
		}

		/**
		 * Concatenates two string with ', ' in between, if $x is not empty
 		 * @param $x - string, first string
	 	 * @param $y - string, second string
		 * @return the combined string
		 * @uses array_concatenate($concat_str, $x, $y)
		 */
		function comma_concatenate($x, $y) {
			return $this->concatenate(', ', $x , $y);
		}

		/**
		 * Creates the names for specific object arrays
		 * @param $nameObjectsArray - array, an array containing objects with the variables 'forename' and 'surname'
		 * @return the concatenated names string
		 */
		function comma_separated_names($nameObjectsArray) {
			foreach ($nameObjectsArray as &$singleNameObject) {
 				$singleNameObject = $singleNameObject->forename . ' ' . $singleNameObject->surname;
			}
			return array_reduce($nameObjectsArray, array(&$this, "comma_concatenate"));
		}

		
		/**
		 *
		 */
		function printAdminPage() {
			$this->getOptions();
			// check if any form data has been submitted and process it
			if (isset($_POST['update_mendeleyPlugin'])) {
				if (isset($_POST['debug'])) {
					$this->settings['debug'] = $_POST['debug'];
				}
				if (isset($_POST['cacheCollections'])) {
					$this->settings['cache_collections'] = $_POST['cacheCollections'];
				}
				if (isset($_POST['cacheDocs'])) {
					$this->settings['cache_docs'] = $_POST['cacheDocs'];
				}
				if (isset($_POST['cacheOutput'])) {
					$this->settings['cache_output'] = $_POST['cacheOutput'];
				}
				if (isset($_POST['consumerKey'])) {
					$this->settings['consumer_key'] = $_POST['consumerKey'];
				}
				if (isset($_POST['consumerSecret'])) {
					$this->settings['consumer_secret'] = $_POST['consumerSecret'];
				}
				update_option($this->adminOptionsName, $this->settings);
?>
<div class="updated"><p><strong><?php _e("Settings updated.", "MendeleyPlugin"); ?></strong></p></div>
<?php
			}
			// check if we should start a request_token, authorize request
			if (isset($_POST['request_mendeleyPlugin'])) {
				if (isset($_POST['consumerKey'])) {
					$this->settings['consumer_key'] = $_POST['consumerKey'];
				}
				if (isset($_POST['consumerSecret'])) {
					$this->settings['consumer_secret'] = $_POST['consumerSecret'];
				}
				update_option($this->adminOptionsName, $this->settings);
				$consumer_key = $this->settings['consumer_key'];
                		$consumer_secret = $this->settings['consumer_secret'];
                		$this->consumer = new OAuthConsumer($consumer_key, $consumer_secret, NULL);

				// sign request and get request token
				$params = array();
				$req_req = OAuthRequest::from_consumer_and_token($this->consumer, NULL, "GET", REQUEST_TOKEN_ENDPOINT, $params);
				$req_req->sign_request($this->sign_method, $this->consumer, NULL);
				$request_ret = run_curl($req_req->to_url(), 'GET');

				// if fetching request token was successful we should have oauth_token and oauth_token_secret
				$token = array();
				parse_str($request_ret, $token);
				$oauth_token = $token['oauth_token'];
				$this->settings['req_token'] = $token['oauth_token'];
				$this->settings['req_token_secret'] = $token['oauth_token_secret'];
				update_option($this->adminOptionsName, $this->settings);

				$domain = $_SERVER['HTTP_HOST'];
				$uri = $_SERVER["REQUEST_URI"];
				$callback_url = "http://$domain$uri&access_mendeleyPlugin=true";
				$auth_url = AUTHORIZE_ENDPOINT . "?oauth_token=$oauth_token&oauth_callback=".urlencode($callback_url);
				redirect($auth_url);
				exit;
			}
			// check if we should start a access_token request (callback)
			if (isset($_GET['access_mendeleyPlugin']) &&
				(strcmp($_GET['access_mendeleyPlugin'],'true')==0)) {
		
				$req_token = $this->settings['req_token'];
				$req_token_secret = $this->settings['req_token_secret'];
				$reqtoken = new OAuthConsumer($req_token, $req_token_secret, NULL);

				// exchange authenticated request token for access token
				$params = array('oauth_verifier' => $_GET['oauth_verifier']);
				$acc_req = OAuthRequest::from_consumer_and_token($this->consumer, $reqtoken, "GET", ACCESS_TOKEN_ENDPOINT, $params);
				$acc_req->sign_request($this->sign_method, $this->consumer, $reqtoken);
				$access_ret = run_curl($acc_req->to_url(), 'GET');

				// if access token fetch succeeded, we should have oauth_token and oauth_token_secret
				// parse and generate access consumer from values
				$token = array();
				parse_str($access_ret, $token);
				if (isset($token['oauth_token']) && (strlen(trim($token['oauth_token']))>0)) {
					$this->settings['access_token'] = $token['oauth_token'];
					$this->settings['access_token_secret'] = $token['oauth_token_secret'];
					$this->accesstoken = new OAuthConsumer($token['oauth_token'], $token['oauth_token_secret'], NULL);
					update_option($this->adminOptionsName, $this->settings);
?>
<div class="updated"><p><strong><?php _e("New Access Token retrieved.", "MendeleyPlugin"); ?></strong></p></div>
<?php
				}
			}
			// display the admin panel options
?>
<div class="wrap">
<form method="post" action="<?php echo $_SERVER["REQUEST_URI"]; ?>">
<h1>Mendeley Plugin</h1>

This plugin offers the possibility to load lists of document references from Mendeley (shared) collections or groups, and display them in WordPress posts or pages.

The lists can be included in posts or pages using WordPress shortcodes:

<p><ul>
<li>- [mendeley type="folders" id="xxx" groupby=""], groupby=year,authors; sortby; sortorder
<li>- [mendeley type="groups" id="763" sortby="year" sortorder="desc"]
<li>- [mendeley type="groups" id="xxx" groupby="" filter=""], filter=ATTRNAME=AVALUE, e.g. author=Michael Koch
<li>- [mendeley type="documents" id="authored" groupby="year"]
<li>- [mendeley type="documents" id="123456789"]
<li>- [mendeley type="own"]
<li>- ... (see readme.txt for more examples)
</ul></p>

<h3>Settings</h3>

<h4>Caching</h4>

<p>
Cache folder/group requests
    <select name="cacheCollections" size="1">
      <option value="no" id="no" <?php if ($this->settings['cache_collections'] === "no") { echo(' selected="selected"'); }?>>no caching</option>
      <option value="week" id="week" <?php if ($this->settings['cache_collections'] === "week") { echo(' selected="selected"'); }?>>refresh weekly</option>
      <option value="day" id="day" <?php if ($this->settings['cache_collections'] === "day") { echo(' selected="selected"'); }?>>refresh daily</option>
      <option value="hour" id="hour" <?php if ($this->settings['cache_collections'] === "hour") { echo(' selected="selected"'); }?>>refresh hourly</option>
    </select><br/>
 Cache document requests
     <select name="cacheDocs" size="1">
      <option value="no" id="no" <?php if ($this->settings['cache_docs'] === "no") { echo(' selected="selected"'); }?>>no caching</option>
      <option value="week" id="day" <?php if ($this->settings['cache_docs'] === "week") { echo(' selected="selected"'); }?>>refresh weekly</option>
      <option value="day" id="day" <?php if ($this->settings['cache_docs'] === "day") { echo(' selected="selected"'); }?>>refresh daily</option>
      <option value="hour" id="hour" <?php if ($this->settings['cache_docs'] === "hour") { echo(' selected="selected"'); }?>>refresh hourly</option>
    </select><br/>
 Cache formated output
    <select name="cacheOutput" size="1">
      <option value="no" id="no" <?php if ($this->settings['cache_output'] === "no") { echo(' selected="selected"'); }?>>no caching</option>
      <option value="week" id="week" <?php if ($this->settings['cache_output'] === "week") { echo(' selected="selected"'); }?>>refresh weekly</option>
      <option value="day" id="day" <?php if ($this->settings['cache_output'] === "day") { echo(' selected="selected"'); }?>>refresh daily</option>
      <option value="hour" id="hour" <?php if ($this->settings['cache_output'] === "hour") { echo(' selected="selected"'); }?>>refresh hourly</option>
    </select><br/>
</p>

<p>To turn on caching is important, because Mendeley currently imposes a rate limit to requests to the service (currently 150 requests per hour - and we need one request for every single document details). See <a href="http://dev.mendeley.com/docs/rate-limiting">http://dev.mendeley.com/docs/rate-limiting</a> for more details on this restriction.</p>

<h4>Debug</h4>

<p><input type="radio" id="debug_yes" name="debug" value="true" <?php if ($this->settings['debug'] === "true") { _e(' checked="checked"', "MendeleyPlugin"); }?> /> Yes&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="debug_no" name="debug" value="false" <?php if ($this->settings['debug'] === "false") { _e(' checked="checked"', "MendeleyPlugin"); }?>/> No</p>
 
<div class="submit">
<input type="submit" name="update_mendeleyPlugin" value="Update Settings">
</div>

<h3>Mendeley Collection IDs</h3>

<p>Currently, the plugin asks the user to specify the ids of Mendeley folders or groups to display the documents in the collection. Pressing the button bellow will request and print the list of folders and groups with the corresponding ids from the user account that authorized the access key to look up the ids you need.

<?php
			// check if we shall display folder/group information
			if (isset($_POST['request_mendeleyIds'])) {
				$result = $this->getGroups();
				echo("<h4>Groups</h4><ul>");
				if (is_array($result)) {
					for($i = 0; $i < sizeof($result); ++$i) {
						$c = $result[$i];
						echo '<li>' . $c->name . ', ' . $c->id;
					}
				}
				echo "</ul>";
				$result = $this->getFolders();
				echo "<h4>Folders</h4><ul>";
				if (is_array($result)) {
					for($i = 0; $i < sizeof($result); ++$i) {
						$c = $result[$i];
						echo '<li>' . $c->name . ', ' . $c->id;
					}
				}
				echo "</ul>";
			}
?>
<div class="submit">
<input type="submit" name="request_mendeleyIds" value="Request Collection Ids">
</div>

<h3>API Keys</h3>

<p>The Mendeley Plugin uses the <a href="http://www.mendeley.com/oapi/methods/">Mendeley OpenAPI</a> to access
the information from Mendeley Groups and Folders. For using this API you first need to request a Consumer Key
and Consumer Secret from Mendeley. These values have to be entered in the following two field. To request the key
and the secret go to <a href="http://dev.mendeley.com/">http://dev.mendeley.com/</a> and register a new application.</p>

<p>Mendeley API Consumer Key<br/>
<input type="text" name="consumerKey" value="<?php echo $this->settings['consumer_key']; ?>" size="60"></input></p>
<p>Mendeley API Consumer Secret<br/>
<input type="text" name="consumerSecret" value="<?php echo $this->settings['consumer_secret']; ?>" size="60"></input></p>

<p>Since Groups and Folders are user-specific, the plugin needs to be authorized to access this 
information in the name of a particular user. The Mendeley API uses the OAuth protocol for doing this. 
When you press the button bellow, the plugin requests authorization from Mendeley. Therefore, you will be asked by
Mendeley to log in and to authorize the request from the login. As a result an Access Token will be generated
and stored in the plugin.</p>

<div class="submit">
<input type="submit" name="request_mendeleyPlugin" value="Request and Authorize Token">
</div>

<p>Mendeley API Request Token<br/>
<input type="text" readonly="readonly" name="token" value="<?php echo $this->settings['req_token']; ?>" size="60"></input></p>
<p>Mendeley API Request Token Secret<br/>
<input type="text" readonly="readonly" name="tokenSecret" value="<?php echo $this->settings['req_token_secret']; ?>" size="60"></input></p>
<p>Mendeley Access Token<br/>
<input type="text" readonly="readonly" name="accessToken" value="<?php echo $this->settings['access_token']; ?>" size="60"></input></p>
<p>Mendeley Access Token Secret<br/>
<input type="text" readonly="readonly" name="accessTokenSecret" value="<?php echo $this->settings['access_token_secret']; ?>" size="60"></input></p>
</form>
</div>
<?php
		}

/* functions to be used in non-widgetized themes instead of widgets */

		/* return formatted version of collection elements */
		/* type = 'own', id = 0 for own publications */
		function formatWidget($type, $id, $maxdocs = 10, $filter = NULL) {
			if (is_null($id)) return '';
			$attrs = Array();
			$attrs['type'] = $type;
			$attrs['id'] = $id;
			$attrs['filter'] = $filter;
			$attrs['sortby'] = "year";
			$attrs['sortorder'] = "desc";
			return $this->formatCollection($attrs, $maxdocs, "shortlist");
		}

		/**
		 * should be called when index.php?mendeley_action=export-json
		 * generate a JSON file for the documents in a collection (after filtering them)
		 */
		function generateJSONFile($id, $type="folders", $filter="") {
			if (isset($filter)) {
                                if (strlen($filter)>0) {
                                        $filterarr = explode('=', $filter);
                                        $filterattr = $filterarr[0];
                                        if (isset($filterarr[1])) {
                                                $filterval = $filterarr[1];
                                        } else {
                                                $filterattr = NULL;
                                        }
                                }
                        }
			// type can be folders, groups, documents
                        $res = $this->getItemsByType($type, $id);
                        // process the data
                        $docarr = $this->loadDocs($res, $type, $id);

			$result = "{\n";
			$result .= '"types"' . " : {\n";
			$result .= '"Bookmark"' . " : {\n" . '"pluralLabel" : "Bookmarks"' . "\n},\n";
			$result .= '"Publication"' . " : {\n" . '"pluralLabel" : "Publications"' . "\n},\n";
			$result .= '"Tag"' . " : {\n" . '"pluralLabel" : "Tags"' . "\n},\n";
			$result .= '"User"' . " : {\n" . '"pluralLabel" : "Users"' . "\n}\n";
			$result .= "},\n";
			$result .= '"properties"' . " : {\n";
			$result .= '"count"' . " : {\n" . '"valueType" : "number"' . "\n},\n";
			$result .= '"date"' . " : {\n" . '"valueType" : "date"' . "\n},\n";
			$result .= '"changeDate"' . " : {\n" . '"valueType" : "date"' . "\n},\n";
			$result .= '"url"' . " : {\n" . '"valueType" : "url"' . "\n},\n";
			$result .= '"id"' . " : {\n" . '"valueType" : "url"' . "\n},\n";
			$result .= '"tags"' . " : {\n" . '"valueType" : "item"' . "\n},\n";
			$result .= '"user"' . " : {\n" . '"valueType" : "item"' . "\n}\n";
			$result .= "},\n";
			$result .= '"items"' . " : [\n";

			$isFirst = 1;
			foreach($docarr as $doc) {
				// check filter
                                if (!is_null($filterattr)) {
                                        $filtertrue = $this->checkFilter($filterattr, $filterval, $doc);
                                        if ($filtertrue == 0) { continue; }
                                }
				if ($isFirst == 0) {
					$result .= ",\n";
				}
				$isFirst = 0;
                                $result .= $this->formatDocumentJSON($doc);
                        }

			$result .= "]\n}";

			header("Pragma: public");
			header("Cache-Control: no-cache, must-revalidate, post-check=0, pre-check=0");
			header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
			// header("Content-Type: application/force-download");
			header("Content-Type: application/json;charset=utf-8");
			//header("Content-Transfer-Encoding: 8bit");
			header("Content-Length: ".strlen($result));
			die($result);
		}

	}
}

if (class_exists("MendeleyPlugin")) {
	$mendeleyPlugin = new MendeleyPlugin();
	function cmpmendeleydoc($a, $b) {
		if ($a->year == $b->year) {
			return 0;
		}
		return ($a->year < $b->year) ? -1 : 1;
	}
	if (!is_object($mendeleyPlugin)) {
		echo "<p>MendelePlugin: failed to initialize plugin</p>";
	}
}
if (!function_exists("wp_mendeley_add_pages")) {
	function wp_mendeley_add_pages() {
		global $mendeleyPlugin;
		if (!isset($mendeleyPlugin)) {
			return;
		}
		if (function_exists('add_options_page')) {
			add_options_page('WP Mendeley', 'WP Mendeley', 8, basename(__FILE__), array(&$mendeleyPlugin,'printAdminPage'));
		}
	}
}
if (isset($mendeleyPlugin)) {
	// check if we should create JSON file
	// (which is the case if we have a GET request with parameter
	// mendeley_action = export-json)
	if (isset($_GET['mendeley_action']) && $_GET['mendeley_action'] === 'export-json') {
		$id = $_GET['id'];
		$type = $_GET['type'];
		$filter = $_GET['filter'];
		$mendeleyPlugin->generateJSONFile($id, $type, $filter);
	}
	if (startsWith($_SERVER['REQUEST_URI'],'/mendeleyplugin-export-json.js')) {
		$id = $_GET['id'];
		$type = $_GET['type'];
		$filter = $_GET['filter'];
		$mendeleyPlugin->generateJSONFile($id, $type, $filter);
	}

	// Actions
	add_action('wp-mendeley/wp-mendeley.php', array(&$mendeleyPlugin,'init'));
	add_action('admin_menu', 'wp_mendeley_add_pages');
	// Filters
	// Shortcodes
	add_shortcode('mendeley', array(&$mendeleyPlugin,'processShortcode'));
	add_shortcode('MENDELEY', array(&$mendeleyPlugin,'processShortcode'));
}


/**
 * MendeleyCollectionWidget Class
 */
class MendeleyCollectionWidget extends WP_Widget {
    /** constructor */
    function MendeleyCollectionWidget() {
        parent::WP_Widget(false, $name = 'Mendeley Collection');	
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {		
	global $mendeleyPlugin;
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        // collectiony type (folder, group)
        $ctype = apply_filters('widget_ctype', $instance['ctype']);
        // collection id
        $cid = apply_filters('widget_cid', $instance['cid']);
        $maxdocs = apply_filters('widget_cid', $instance['count']);
        $filterattr = apply_filters('widget_cid', $instance['filterattr']);
        $filterval = apply_filters('widget_cid', $instance['filterval']);
        ?>
              <?php echo $before_widget; ?>
                  <?php if ( $title )
                        echo $before_title . $title . $after_title; ?>
              <?php
              		$result = '<ul class="wpmlist">';
			if (strlen($filterattr)<1) {
				$result .= $mendeleyPlugin->formatWidget($ctype, $cid, $maxdocs);
			} else {
				$result .= $mendeleyPlugin->formatWidget($ctype, $cid, $maxdocs, array($filterattr => $filterval));
			}
			$result .= '</ul>';
			echo $result;
               ?>
              <?php echo $after_widget; ?>
        <?php
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {				
	$instance = $old_instance;
	$instance['title'] = strip_tags($new_instance['title']);
	$instance['ctype'] = strip_tags($new_instance['ctype']);
	$instance['cid'] = strip_tags($new_instance['cid']);
	$instance['count'] = strip_tags($new_instance['count']);
	$instance['filterattr'] = strip_tags($new_instance['filterattr']);
	$instance['filterval'] = strip_tags($new_instance['filterval']);
        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {				
        $title = esc_attr($instance['title']);
        $cid = esc_attr($instance['cid']);
        $ctype = esc_attr($instance['ctype']);
        $count = esc_attr($instance['count']);
        $filterattr = esc_attr($instance['filterattr']);
        $filterval = esc_attr($instance['filterval']);
        ?>
        <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label></p>
        <p><label for="<?php echo $this->get_field_id('ctype'); ?>"><?php _e('Collection Type:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('ctype'); ?>" name="<?php echo $this->get_field_name('ctype'); ?>" type="text" value="<?php echo $ctype; ?>" /></label> (folder, group, documents)</p>
        <p><label for="<?php echo $this->get_field_id('cid'); ?>"><?php _e('Group/Folder Id:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('cid'); ?>" name="<?php echo $this->get_field_name('cid'); ?>" type="text" value="<?php echo $cid; ?>" /></label></p>
 		<p><label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Number of docs to display:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo $count; ?>" /></label></p>
 		<p><label for="<?php echo $this->get_field_id('filterattr'); ?>"><?php _e('Attribute name to filter for:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('filterattr'); ?>" name="<?php echo $this->get_field_name('filterattr'); ?>" type="text" value="<?php echo $filterattr; ?>" /></label></p>
 		<p><label for="<?php echo $this->get_field_id('filterval'); ?>"><?php _e('Attribute value:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('filterval'); ?>" name="<?php echo $this->get_field_name('filterval'); ?>" type="text" value="<?php echo $filterval; ?>" /></label></p>
        <?php 
    }

} // class MendleyCollectionWidget

/**
 * MendeleyOwnWidget Class
 */
class MendeleyOwnWidget extends WP_Widget {
    /** constructor */
    function MendeleyOwnWidget() {
        parent::WP_Widget(false, $name = 'Mendeley Collection');	
    }

    /** @see WP_Widget::widget */
    function widget($args, $instance) {		
	global $mendeleyPlugin;
        extract( $args );
        $title = apply_filters('widget_title', $instance['title']);
        $maxdocs = apply_filters('widget_cid', $instance['count']);
        ?>
              <?php echo $before_widget; ?>
                  <?php if ( $title )
                        echo $before_title . $title . $after_title; ?>
              <?php
              		$result = '<ul class="wpmlist">';
			$result .= $mendeleyPlugin->formatWidget('own', 0, $maxdocs);
			$result .= '</ul>';
			echo $result;
               ?>
              <?php echo $after_widget; ?>
        <?php
    }

    /** @see WP_Widget::update */
    function update($new_instance, $old_instance) {				
	$instance = $old_instance;
	$instance['title'] = strip_tags($new_instance['title']);
	$instance['count'] = strip_tags($new_instance['count']);
        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {				
        $title = esc_attr($instance['title']);
        $count = esc_attr($instance['count']);
        ?>
        <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label></p>
 	<p><label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Number of docs to display:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo $count; ?>" /></label></p>
        <?php 
    }

} // class MendleyOwnWidget

// register Mendeley widgets
add_action('widgets_init', create_function('', 'return register_widget("MendeleyCollectionWidget");'));
add_action('widgets_init', create_function('', 'return register_widget("MendeleyOwnWidget");'));


/***************************************************************************
 * Function: Run CURL
 * Description: Executes a CURL request
 * Parameters: url (string) - URL to make request to
 *             method (string) - HTTP transfer method
 *             headers - HTTP transfer headers
 *             postvals - post values
 **************************************************************************/
function run_curl($url, $method = 'GET', $headers = null, $postvals = null){
    $ch = curl_init($url);

    if ($method === 'GET'){
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    } else {
        $options = array(
            CURLOPT_HEADER => false,
            CURLINFO_HEADER_OUT => false,
            CURLOPT_VERBOSE => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS => $postvals,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 3
        );
        curl_setopt_array($ch, $options);
    }
    $response = curl_exec($ch);
    curl_close($ch);

    return $response;
}

function redirect($url){
    if (!headers_sent()){    //If headers not sent yet... then do php redirect
        header('Location: '.$url); exit;
    }else{                    //If headers are sent... do java redirect... if java disabled, do html redirect.
        echo '<script type="text/javascript">';
        echo 'window.location.href="'.$url.'";';
        echo '</script>';
        echo '<noscript>';
        echo '<meta http-equiv="refresh" content="0;url='.$url.'" />';
        echo '</noscript>'; exit;
    }
}//==== End -- Redirect

function startsWith($string, $prefix, $caseSensitive = true) {
	if(!$caseSensitive) {
	return stripos($string, $prefix, 0) === 0;
	}
	return strpos($string, $prefix, 0) === 0;
}

function endsWith($string, $postfix, $caseSensitive = true) {
	$expectedPostition = strlen($string) - strlen($postfix);
	if(!$caseSensitive) {
		return strripos($string, $postfix, 0) === $expectedPostition;
	}
	return strrpos($string, $postfix, 0) === $expectedPostition;
}

  
?>
