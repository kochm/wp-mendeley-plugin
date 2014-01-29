<?php
/*
Plugin Name: Mendeley Plugin
Plugin URI: http://www.kooperationssysteme.de/produkte/wpmendeleyplugin/
Version: 0.8.5

Author: Michael Koch
Author URI: http://www.kooperationssysteme.de/personen/koch/
License: http://www.opensource.org/licenses/mit-license.php
Description: This plugin offers the possibility to load lists of document references from Mendeley (shared) collections, and display them in WordPress posts or pages.
*/

define( 'PLUGIN_VERSION' , '0.8.5' );
define( 'PLUGIN_DB_VERSION', 2 );

/* 
The MIT License

Copyright (c) 2010-2014 Michael Koch (email: michael.koch@acm.org)
 
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

if (!class_exists("citeproc")){
	include_once('CiteProc.php');
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
			}

			$result = json_decode($resp);
			if (!is_null($result->error)) {
				echo "<p>Mendeley Plugin Error: " . $result->error . "</p>";
				$error_message = $result->error;
			}
			return $result;
		}
		
		function processShortcodeList($attrs = null) {
			return $this->formatCollection($attrs);
		}

		function processShortcodeDetails($attrs = null, $content = null) {
			$docid = $_GET["docid"];
			if (!$docid) {
				return 'MendeleyPlugin: No docid specified on details page.';
			}
			if (!$content) {
				$templatefile = __DIR__.'/mendeley-details-template.tpl';
				if(file_exists($path2template)){
                                	$content = file_get_contents($templatefile);
                        	}
                        	else {
					return 'MendeleyPlugin: No template or template file available for details page.';
                        	}
			}
			$doc = $this->getDocument($docid);
			preg_match_all('/\{([^\}]*)\}/', $content, $matches);
                        if(!isset($matches[0])){
                                $matches[0] = array();
                        }
                        for($i=0;$i<count($matches[0]);$i++) {
				$tmps = "";
                                if(isset($doc->$matches[1][$i])) {
                        		$tmps = $this->detailsFormat($doc, $matches[1][$i]);
				} else {
					$token = $matches[1][$i];
					$tmparr = explode(",", $token, 2);
					$token = $tmparr[0];
					switch(strtolower($token)) {
						case 'full_reference':
							if (sizeof($tmparr)>1) {
								$tmps = $this->formatDocument($doc, $tmparr[1]);
							} else {
								$tmps = $this->formatDocument($doc);
							}
							break;
					}
				}
                        	$content = str_replace($matches[0][$i], $tmps, $content);
                        }
                        $content = preg_replace('/\{([^\s]*)\}/', '', $content);
			return '<span>' . $content . '</span>';
		}
		protected function detailsFormat($doc, $token) {
			switch(strtolower($token)) {
				case 'authors':
				case 'translators':
				case 'editors':
				case 'producers':
					return implode('; ', array_map("detailsFormatMap1", $doc->$token));
					break;
				case 'identifiers':
					return implode(', ', array_map("detailsFormatMap2", $doc->$token));
					break;
				case 'tags':
				case 'keywords':
					return implode(', ', array_map("detailsFormatMap2", $doc->$token));
					break;
				default:
					return $doc->$token;
			}
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
			$csl = (isset($attrs['csl'])?$attrs['csl']:Null) ;
			$filter = (isset($attrs['filter'])?$attrs['filter']:array()) ;
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
			$cacheid = $type."-".$id."-".$groupby.$grouporder."-".$sortby.$sortorder."-".$filter."-".$maxdocs;
			if (isset($csl)) {
				$cacheid .= "-".$csl;
			}
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
				if (!is_null($filter)) {
					$filtertrue = $this->checkFilter($filter, $doc);
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
				// standard one ("standard") - the latter allows the optional attribute "csl"
				if ($style === "shortlist") {
					$result .= '<li class="wpmlistref">' . $this->formatDocumentShort($doc) .  '</li>';
				} else {
					// do static formatting or formatting with CSL stylesheet
					$result = $result . $this->formatDocument($doc,$csl);
				}

				if ($maxdocs > 0) {
					$count++;  
					if ($count > $maxdocs) break;
				}	
			}
			$this->updateOutputInCache($cacheid, $result);
			return $result;
		}		

		/* check if a given document ($doc) matches the given filter
		   return 1 if the check is true, 0 otherwise */
		function checkFilter($filter, $doc) {
			if (!isset($filter)) {
			   return 1;
			}
			if (strlen($filter)<1) {
			   return 1;
			}
			// parse filters
			$filterarr = explode(";", $filter);
			foreach ($filterarr as $singlefilter) {
			   $singlefilterarr = explode('=', $singlefilter);
			   $filterattr = $singlefilterarr[0];
			   if (isset($singlefilterarr[1])) {
				$filterval = $singlefilterarr[1];
			   } else {
				continue;
			   }
			   if (strcmp($filterattr, 'author')==0) {
				$author_arr = $doc->authors;
				if (is_array($author_arr)) {
					$tmps = $this->comma_separated_names($author_arr);
                       			if (!(stristr($tmps, $filterval) === FALSE)) {
                               			continue;
                       			}
				}
 			   } else if (strcmp($filterattr, 'editor')==0) {
                               	$editor_arr = $doc->editors;
				if (is_array($editor_arr)) {
					$tmps = $this->comma_separated_names($editor_arr);
                       			if (!(stristr($tmps, $filterval) === FALSE)) {
                               			continue;
                       			}
                               	}
			   } else if (strcmp($filterattr, 'tag')==0) {
                               	$tag_arr = $doc->tags;
				if (is_array($tag_arr)) {
				   	$ismatch = 0;
                               		for($i = 0; $i < sizeof($tag_arr); ++$i) {
                               			if (!(stristr($tag_arr[$i], $filterval) === FALSE)) {
                               				$ismatch = 1;
						}
                               		}
					if ($ismatch == 1) {
					   continue;
					}
                               	}
			   } else if (strcmp($filterattr, 'keyword')==0) {
                               	$keyword_arr = $doc->keywords;
				if (is_array($keyword_arr)) {
				   	$ismatch = 0;
                               		for($i = 0; $i < sizeof($keyword_arr); ++$i) {
                               			if (!(stristr($keyword_arr[$i], $filterval) === FALSE)) {
                               				$ismatch = 1;
						}
                               		}
					if ($ismatch == 1) {
					   continue;
					}
                               	}
                           } else {
                               	// other attributes
				if (!isset($doc->{$filterattr})) {
				   continue;
				}
                                if (strcmp($filterval, $doc->{$filterattr})==0) {
					continue;
                                }
			   }
			   return 0;
			} // foreach singlefilter
			return 1;
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
			$url = MENDELEY_OAPI_URL . "library/$type/$id/";
			if ($type === "own") { 
				$url = MENDELEY_OAPI_URL . "library/documents/authored/";
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
		function getDocument($docid, $fromtype=null, $fromid=null) {
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
		function loadDocs($docidarr, $fromtype=null, $fromid=null, $count=0) {
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
						if (is_object($grpval[0])) {
							if (isset($grpval[0]->surname)) {
								$grpval = $grpval[0]->surname . $grpval[0]->forename;
							} else {
								$grpval = strval($grpval[0]);
							}
						} else {
							$grpval = strval($grpval[0]);
						}
					}
				}
				if (isset($grpval)) {
					$grpval = $grpval . $doc->added;
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
		/* - csl is an optional URL pointing to a Citation Stylesheet Language stylesheet
		   - textonly = True will result in output without HTML formatting */
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
			doi
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
		function formatDocument($doc, $csl=Null, $textonly=False) {
			$result = '';
			if (!$textonly) {
                        	$result .= '<p class="wpmref" style="';
			}

                        // format document with a given CSL style and the CiteProc.php
                        if ($csl != Null && class_exists("citeproc")){
                        	// read the given CSL style from XML document, load it to a string, and convert it to an object
				$cacheid = "csl-".$csl;
				$csl_file = $this->getOutputFromCache($cacheid);
				if (empty($csl_file)) {
				        $curl = curl_init($csl);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        		$csl_file = curl_exec($curl);
					if ($csl_file !== false) {
					  $this->updateOutputInCache($cacheid, $csl_file);
					} else {
					  echo "<p>Mendeley Plugin Error: " . curl_error($curl) . "</p>";
					}
					curl_close($curl);
				}
                        	$csl_object = simplexml_load_string($csl_file);

                                if (!$textonly) { $result.='">'; }
                                // stdClass for showing document
                                $docdata = new stdClass;
                                $docdata->type = $this->mendeleyType2CiteProcType($doc->type);
                                $docdata->author = $this->mendeleyNames2CiteProcNames($doc->authors);
                                $docdata->editor = $this->mendeleyNames2CiteProcNames($doc->editors);
				$docdata->issued = (object) array('date-parts' => array(array($doc->year)));
                                $docdata->title = $doc->title;
                                if (isset($doc->published_in)) {
                                        $docdata->container_title = $doc->published_in;
                                }
                                if (isset($doc->publication_outlet)) {
                                        $docdata->container_title = $doc->publication_outlet;
                                }
                                if (isset($doc->journal)) {
                                        $docdata->container_title = $doc->journal;
                                }
                                if (isset($doc->volume)) {
                                        $docdata->volume = $doc->volume;
                                }
                                if (isset($doc->issue)) {
                                        $docdata->issue = $doc->issue;
                                }
                                if (isset($doc->pages)) {
                                        $docdata->page = $doc->pages;
                                }
                                if (isset($doc->publisher)) {
                                        $docdata->publisher = $doc->publisher;
                                }
                                if (isset($doc->city)) {
                                        $docdata->publisher_place = $doc->city;
				}
                                if (isset($doc->url)) {
                                        $docdata->URL = $doc->url;
				}
                                if (isset($doc->doi)) {
                                        $docdata->DOI = $doc->doi;
				}
                                if (isset($doc->isbn)) {
                                        $docdata->ISBN = $doc->isbn;
				}
                                // execute citeproc with new stdClass
                                $cp = new citeproc($csl_file);
                                $result .= $cp->render($docdata,'bibliography');
			}
                        else {
                                if (!$textonly) { $result.='">'; }
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
				if ($textonly) {
				$result .= $authors . ' (' . $doc->year . '): ' . $doc->title;
				if (isset($doc->publication_outlet)) {
					$result .= ', ' . $doc->publication_outlet;
				}
				if (isset($doc->volume)) {
					$result .= ' ' . $doc->volume;
				}
				if (isset($doc->issue)) {
					$result .= '(' . $doc->issue . ')';
				}
				if (isset($doc->editors)) {
					if (strlen($editors)>0) {
						$result .= ', ' . $editors . ' (' . __('ed.','wp-mendeley') . ')';
					}
				}
				if (isset($doc->pages)) {
					$result .= ', ' . __('p.','wp-mendeley') . ' ' . $doc->pages;
				}
				if (isset($doc->publisher)) {
					if (isset($doc->city)) {
						$result .= ', ' . $doc->city . ': ' . $doc->publisher;
					} else {
						$result .= ', ' . $doc->publisher;
					}
				}
				if (isset($doc->url)) {
					foreach(explode(chr(10),$doc->url) as $urlitem) {
						$result .= ', '.$urlitem;
					}
				} 
				if (isset($doc->doi)) {
                                	$result .= ', doi:' . $doc->doi;
				}
				} else {
				$result .= '<span class="wpmauthors">' . $authors . '</span> ' .
			        	'<span class="wpmyear">(' . $doc->year . ')</span> ' . 
			        	'<span class="wpmtitle">' . $doc->title . '</span>';
				if (isset($doc->publication_outlet)) {
					$result .= ', <span class="wpmoutlet">' . 
				    	$doc->publication_outlet . '</span>';
				}
				if (isset($doc->volume)) {
					$result .= ' <span class="wpmvolume">' . $doc->volume . '</span>';
				}
				if (isset($doc->issue)) {
					$result .= '<span class="wpmissue">(' . $doc->issue . ')</span>';
				}
				if (isset($doc->editors)) {
					if (strlen($editors)>0) {
						$result .= ', <span class="wpmeditors">' . $editors . ' (' . __('ed.','wp-mendeley') . ')</span>';
					}
				}
				if (isset($doc->pages)) {
					$result .= ', <span class="wpmpages">' . __('p.','wp-mendeley') . ' ' . $doc->pages . '</span>';
				}
				if (isset($doc->publisher)) {
					if (isset($doc->city)) {
						$result .= ', <span class="wpmpublisher">' . $doc->city . ': ' . $doc->publisher . '</span>';
					} else {
						$result .= ', <span class="wpmpublisher">' . $doc->publisher . '</span>';
					}
				}
				if (isset($doc->url)) {
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
						$result .= ', <span class="wpmurl"><a target="_blank" href="' . $urlitem . '"><span class="wpmurl' . $atext . '">' . $atext . '</span></a></span>';
					}
				} else {
					if (isset($doc->doi)) {
                                		$atext = "doi:" . $doc->doi;
                                		$result .= ', <span class="wpmurl"><a target="_blank" href="http://dx.doi.org/' . $doc->doi . '"><span class="wpmurl' . $atext . '">' . $atext . '</span></a></span>';
                                	}
				}
				}
			}
			if (!$textonly) { $result .= '</p>'; }
			return $result;
		}

		function &mendeleyNames2CiteProcNames($names) {
			foreach ($names as $rank => $name) {
				$name->given = $name->forename;
				$name->family = $name->surname;
			}
			return $names;
		}
		function mendeleyType2CiteProcType($type) {
			if (!isset($this->type_map)) {
				$this->type_map = array(
						'Book' => 'book',
						'Book Section' => 'chapter',
						'Journal Article' => 'article',
						'Magazine Article' => 'article',
						'Newspaper Article' => 'article',
						'Conference Proceedings' => 'paper-conference',
						'Report' => 'report',
						'Thesis' => 'thesis',
						'Case' => 'legal_case',
						'Encyclopedia Article' => 'entry-encyclopedia',
						'Web Page' => 'webpage',
						'Working Paper' => 'report',
						'Generic' => 'chapter', 
						);
			}
			return $this->type_map[$type];
		}
		
		function formatDocumentShort($doc,$csl=Null) {
			if ($this->settings['detail_tips'] === 'false') {
				$tmps = '<span class="wpmtitle">';
			} else {
				$tmps = '<span class="wpmtitle" title="'.$this->formatDocument($doc,$csl,True).'">';
			}
			$tmpurl = $this->settings['detail_url'];
			if (isset($tmpurl) && strlen($tmpurl)>0) {
				$tmpurl = $this->settings['detail_url'];
				if (strpos($tmpurl, '?') !== false) {
					$tmpurl .= '&docid=' . $doc->id;
				} else {
					$tmpurl .= '?docid=' . $doc->id;
				}
				$tmps .= '<a href="' .  $tmpurl . '">' . $doc->title . '</a>';
			} else {
				if (isset($doc->url)) {
					$tmps .= '<a href="' .  $doc->url . '">' . $doc->title . '</a>';
				} else {
					$tmps .= '<a href="' . $doc->mendeley_url . '">' . $doc->title . '</a>';
				}
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
			$tmps .= '"pub-type": ' . json_encode($doc->type) .  ",\n"; 
			$tmps .= '"label" : ' . json_encode($doc->title) . ",\n";
			if (isset($doc->publication_outlet)) {
				$tmps .= '"booktitle" : ' . json_encode($doc->publication_outlet) . ",\n";
			}
			$tmps .= '"description": ' . json_encode($doc->abstract) . ",\n"; 

			$author_arr = $doc->authors;
			if (is_array($author_arr)) {
				$tmps .= '"author" : [ ' . "\n";
				for($i = 0; $i < sizeof($author_arr); ++$i) {
					if ($i > 0) { $tmps .= ', '; }
					$tmps .= json_encode($author_arr[$i]->forename . ' ' . $author_arr[$i]->surname);
				}
				$tmps .= "\n],\n";
			}
			$editor_arr = $doc->editors;
			if (is_array($editor_arr)) {
				$tmps .= '"editor" : [ ' . "\n";
				for($i = 0; $i < sizeof($editor_arr); ++$i) {
					if ($i > 0) { $tmps .= ', '; }
					$tmps .= json_encode($editor_arr[$i]->forename . ' ' . $editor_arr[$i]->surname);
				}
				$tmps .= "\n],\n";
			}
			$tag_arr = $doc->tags;
			if (is_array($tag_arr)) {
				$tags = "";
				for($i = 0; $i < sizeof($tag_arr); ++$i) {
					if ($i > 0) { $tags .= ', '; }
					$tags .= json_encode($tag_arr[$i]);
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
			if (isset($doc->url)) {
				$tmps .= '"url" : ' . json_encode($doc->url) . ",\n";
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
			if ($this->settings['db_version'] < PLUGIN_DB_VERSION) {
				if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
					$sql = "CREATE TABLE " . $table_name . " (
						  id mediumint(9) NOT NULL AUTO_INCREMENT,
						  type mediumint(9) NOT NULL,
						  mid tinytext NOT NULL,
						  content text,
						  time bigint(11) DEFAULT '0' NOT NULL,
						  UNIQUE KEY id (id)
						);".
						"CREATE INDEX wpmidxid ON $table_name (mid);".
						"CREATE INDEX wpmidxtype ON $table_name (type);";
					require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
					dbDelta($sql);
					$this->settings['db_version'] = PLUGIN_DB_VERSION;
					update_option($this->adminOptionsName, $this->settings);
				} else {
					if ($this->settings['db_version'] < 2) {
						// create index
						$sql = "CREATE INDEX wpmidxid ON $table_name (mid);".
							"CREATE INDEX wpmidxtype ON $table_name (type);";
						require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
						dbDelta($sql);
					}
					$this->settings['db_version'] = PLUGIN_DB_VERSION;
					update_option($this->adminOptionsName, $this->settings);
				}
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
			$jsondoc = json_encode($doc);
			if (!strncmp($jsondoc, "{\"error", 7)) {
				return;
			}
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=0 AND mid='$docid'");
			if ($dbdoc) {
				$wpdb->update($table_name, array('time' => time(), 'content' => $jsondoc), array( 'type' => '0', 'mid' => "$docid"));
				return;
			}
			$wpdb->insert($table_name, array( 'type' => '0', 'time' => time(), 'mid' => "$docid", 'content' => $jsondoc));
		}
		function updateFolderInCache($cid, $doc) {
			global $wpdb;
			$jsondoc = json_encode($doc);
			if (!strncmp($jsondoc, "{\"error", 7)) {
				return;
			}
			$table_name = $wpdb->prefix . "mendeleycache";
			$dbdoc = $wpdb->get_row("SELECT * FROM $table_name WHERE type=1 AND mid='$cid'");
			if ($dbdoc) {
				$wpdb->update($table_name, array('time' => time(), 'content' => $jsondoc), array( 'type' => '1', 'mid' => "$cid"));
				return;
			}
			$wpdb->insert($table_name, array( 'type' => '1', 'time' => time(), 'mid' => "$cid", 'content' => $jsondoc));
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
				if (isset($_POST['detailUrl'])) {
                                        $this->settings['detail_url'] = $_POST['detailUrl'];
                                }
                                if (isset($_POST['detailTips'])) {
                                        $this->settings['detail_tips'] = $_POST['detailTips'];
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

<p>This plugin offers the possibility to load lists of document references from Mendeley (shared) collections or groups, and display them in WordPress posts or pages.</p>

<p>The "csl" attribute in the shortcode offers the possibility for customized style formatting using the citation style language (CSL). </br>
For further information a short example is given in the readme.txt or visit the CSL website on <a href="http://citationstyles.org/" target="_blank" alt="citationstyles.org">citationstyles.org</a>.</p>

<p>The lists can be included in posts or pages using WordPress shortcodes:</p>

<p><ul>
<li>- [mendeley type="folders" id="xxx" groupby=""], groupby=year,authors; sortby; sortorder
<li>- [mendeley type="groups" id="763" sortby="year" sortorder="desc"]
<li>- [mendeley type="groups" id="xxx" groupby="" filter=""], filter=ATTRNAME=AVALUE[;ATTRNAME=AVALUE], e.g. author=Michael Koch
<li>- [mendeley type="documents" id="authored" groupby="year"]
<li>- [mendeley type="documents" id="123456789"]
<li>- [mendeley type="own"]
<li>- [mendeley type="groups" id="763" csl="http://DOMAINNAME/csl/csl_style.csl"]
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

<h4>List Layout / Details</h4>

<p>There are two types of lists in the plugin: 1) Lists in pages or posts generated by the shortcode [mendeley], 2) Lists in widgets. For 1) there are different ways for formatting the list entries - including the usage of CSL stylesheets or CSS formatting; for 2) usually only the title of the paper is displayed with a link (specified by the URL or the DOI in the documents data).</p>

<p>Add tooltips with full references to widget list entries&nbsp;&nbsp;&nbsp;&nbsp;
<input type="radio" id="detailTips_yes" name="detailTips" value="true" <?php if ($this->settings['detail_tips'] === "true") { echo(' checked="checked"'); }?> /> Yes&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="detailTips_no" name="detailTips" value="false" <?php if ($this->settings['detail_tips'] === "false") { echo(' checked="checked"'); }?>/> No</p>
<p>Relative URL to a details page (will be linked to widget list entries if specified):<br/>
<input type="text" name="detailUrl" value="<?php echo $this->settings['detail_url']; ?>" size="60"></input></p>

<h4>Debug</h4>

<p>Current Plugin Version: <?php echo PLUGIN_VERSION; ?>, Current Database Version: <?php echo PLUGIN_DB_VERSION; ?></p>

<p>Show debug messages in output&nbsp;&nbsp;&nbsp;&nbsp;
<input type="radio" id="debug_yes" name="debug" value="true" <?php if ($this->settings['debug'] === "true") { echo(' checked="checked"'); }?> /> Yes&nbsp;&nbsp;&nbsp;&nbsp;<input type="radio" id="debug_no" name="debug" value="false" <?php if ($this->settings['debug'] === "false") { echo(' checked="checked"'); }?>/> No</p>
 
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
                                if (strlen($filter)<0) {
				   $filter = NULL;
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
                                if (!is_null($filter)) {
                                        $filtertrue = $this->checkFilter($filter, $doc);
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
	add_shortcode('mendeley', array(&$mendeleyPlugin,'processShortcodeList'));
	add_shortcode('mendeleydetails', array(&$mendeleyPlugin,'processShortcodeDetails'));
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
        $filter = apply_filters('widget_cid', $instance['filter']);
	$csl = apply_filters('widget_cid', $instance['csl']);
        ?>
              <?php echo $before_widget; ?>
                  <?php if ( $title )
                        echo $before_title . $title . $after_title; ?>
              <?php
              		$result = '<ul class="wpmlist">';
			if (strlen($filter)<1) {
				$result .= $mendeleyPlugin->formatWidget($ctype, $cid, $maxdocs, array('csl' => $csl));
			} else {
				$result .= $mendeleyPlugin->formatWidget($ctype, $cid, $maxdocs, array('filter' => $filter, 'csl' => $csl));
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
	$instance['filter'] = strip_tags($new_instance['filter']);
	$instance['csl'] = strip_tags($new_instance['csl']);
        return $instance;
    }

    /** @see WP_Widget::form */
    function form($instance) {				
        $title = esc_attr($instance['title']);
        $cid = esc_attr($instance['cid']);
        $ctype = esc_attr($instance['ctype']);
        $count = esc_attr($instance['count']);
        $filter = esc_attr($instance['filter']);
	$csl = esc_attr($instance['csl']);
        ?>
        <p><label for="<?php echo $this->get_field_id('title'); ?>"><?php _e('Title:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('title'); ?>" name="<?php echo $this->get_field_name('title'); ?>" type="text" value="<?php echo $title; ?>" /></label></p>
        <p><label for="<?php echo $this->get_field_id('ctype'); ?>"><?php _e('Collection Type:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('ctype'); ?>" name="<?php echo $this->get_field_name('ctype'); ?>" type="text" value="<?php echo $ctype; ?>" /></label> (folder, group, documents)</p>
        <p><label for="<?php echo $this->get_field_id('cid'); ?>"><?php _e('Group/Folder Id:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('cid'); ?>" name="<?php echo $this->get_field_name('cid'); ?>" type="text" value="<?php echo $cid; ?>" /></label></p>
 		<p><label for="<?php echo $this->get_field_id('count'); ?>"><?php _e('Number of docs to display:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('count'); ?>" name="<?php echo $this->get_field_name('count'); ?>" type="text" value="<?php echo $count; ?>" /></label></p>
 		<p><label for="<?php echo $this->get_field_id('filter'); ?>"><?php _e('(Optional) Filter:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('filter'); ?>" name="<?php echo $this->get_field_name('filter'); ?>" type="text" value="<?php echo $filter; ?>" /></label></p>
		<p><label for="<?php echo $this->get_field_id('csl'); ?>"><?php _e('Attribute value:'); ?> <input class="widefat" id="<?php echo $this->get_field_id('csl'); ?>" name="<?php echo $this->get_field_name('csl'); ?>" type="text" value="<?php echo $csl; ?>" /></label></p>
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

function detailsFormatMap1($el) { return $el->surname.', '.$el->forename; }
function detailsFormatMap2($el) { return $el; }

?>
