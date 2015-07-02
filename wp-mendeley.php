<?php
/*
Plugin Name: Mendeley Plugin
Plugin URI: http://www.kooperationssysteme.de/produkte/wpmendeleyplugin/
Version: 1.1.1

Author: Michael Koch
Author URI: http://www.kooperationssysteme.de/personen/koch/
License: http://www.opensource.org/licenses/mit-license.php
Description: This plugin offers the possibility to load lists of document references from Mendeley (shared) collections, and display them in WordPress posts or pages.
*/

define( 'PLUGIN_VERSION' , '1.1.1' );
define( 'PLUGIN_DB_VERSION', 2 );

/* 
The MIT License

Copyright (c) 2010-2015 Michael Koch (email: michael.koch@acm.org)
 
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

define( 'MENDELEY_API_URL', 'https://api.mendeley.com/' );
define( 'OAUTH2_AUTHORIZE_ENDPOINT', 'https://api.mendeley.com/oauth/authorize' );
define( 'OAUTH2_REQUEST_TOKEN_ENDPOINT', 'https://api.mendeley.com/oauth/token' );

define('FILE_CACHE_DIR', ABSPATH . '/wp-content/cache/mendeley-file-cache/');
define('FILE_CACHE_URL', home_url() . "/wp-content/cache/mendeley-file-cache/");

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
		protected $error_message = "";
		function MendeleyPlugin() { // constructor
			$this->init();
		}
		function init() {
			$this->getOptions();
			$this->initializeDatabase();
			$this->initFileCache();
			load_plugin_textdomain('wp-mendeley');
		}

		// send an authorized / authenticated request to the Mendeley API
		// the result (JSON) will be decoded and returned as result parameter
		// if there is an error on the way, null will be returned (and an error massage will be written to stdout)
		// HTTP-Headers will be stored in $headers (to allow processing pagination)
		function sendAuthorizedRequest($url) {
			global $headers;
			$headers = array();
			$this->getOptions();
			$access_token = $this->settings['oauth2_access_token'];
			if (strlen($access_token)<1) {
			   echo "<p>Mendeley Plugin Error: No access token set - try to authorize against Mendeley in the backend before accessing data first.</p>";
			   return null;
			} else {
			   // OAuth2
		  	   $client_id = $this->settings['oauth2_client_id'];
                	   $client_secret = $this->settings['oauth2_client_secret'];

			   // check if access token should be refreshed
			   $expires_at = $this->settings['oauth2_expires_at'];
			   if ($expires_at < (time() - 100)) {
			      $callback_url = admin_url('options-general.php?page=wp-mendeley.php&access_mendeleyPluginOAuth2=true');
			      // retrieve new authorization token
			      $curl = curl_init(OAUTH2_REQUEST_TOKEN_ENDPOINT);
			      curl_setopt($curl, CURLOPT_POST, true);
			      curl_setopt($curl, CURLOPT_POSTFIELDS, 
			      "grant_type=refresh_token&refresh_token=".urlencode($this->settings['oauth2_refresh_token'])."&client_id=".urlencode($client_id)."&client_secret=".urlencode($client_secret)."&redirect_uri=".urlencode($callback_url)
				       );
			      curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1);
		 	      // basic authentication ...
			      curl_setopt($curl, CURLOPT_HTTPHEADER, array(
				 "Authorization: Basic " . base64_encode($this->settings['oauth2_client_id'] . ":" . $this->settings['oauth2_client_secret'])
				 )); // do not addslashes or urlencode here!!!!
			      $auth = curl_exec($curl);
			      if (!$auth) {
				 $auth = curl_error($curl);
			         $access_token = nil;
			      } else {
			      	 $secret = json_decode($auth);
			      	 $access_token = $secret->access_token;
			      }
			      if (strlen("$access_token")>0) {
 				 $this->settings['oauth2_access_token'] = $access_token;
				 $expires_in = $secret->expires_in;
 				 $this->settings['oauth2_expires_at'] = time()+(integer)$expires_in;
 				 $this->settings['oauth2_refresh_token'] = $secret->refresh_token;
				 update_option($this->adminOptionsName, $this->settings);
			         if ($this->settings['debug'] === 'true') {
				    echo "<p>Successfully refreshed access token ...</p>";
			         }
			      } else {
?>
<div class="updated"><p><strong><?php _e("Failed refreshing OAuth2 access token: $auth", "MendeleyPlugin"); ?></strong></p></div>
<?php
			      }
			   }

			   if (!(strpos($url, MENDELEY_API_URL) === 0)) {
			      $url = MENDELEY_API_URL . $url;
			   }
			   $curl = curl_init($url);
			   curl_setopt($curl, CURLOPT_HTTPHEADER, array( 'Authorization: Bearer ' . $access_token));
			   curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
			   curl_setopt($curl, CURLOPT_VERBOSE, true);
			   curl_setopt($curl, CURLOPT_HEADER, true);

			   // send request
			   if ($this->settings['debug'] === 'true') {
				echo "<p>Request: ".$url."</p>";
			   }
			   $resp = curl_exec($curl);
			   if (!$resp) {
				echo "<p>Mendeley Plugin Error: Failed accessing Mendeley API: " . curl_error($curl) . "</p>";
			   }
			   if ($this->settings['debug'] === 'true') {
				echo "<p>Response: ".$resp."</p>";
			   }
			   $header_size = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
			   $header = substr($resp, 0, $header_size);
			   $body = substr($resp, $header_size);
			   // parse headers
			   foreach (explode("\r\n", $header) as $i => $line) {
			       if ($i === 0) {
                	           $headers['http_code'] = $line;
			       } else {
			           list ($key, $value) = explode(': ', $line);
				   if ($key === "Link") {
				        $pos1 = strpos($value, "rel=");
				        if ($pos1) {
					    $pos2 = strpos($value, "\"", $pos1+6);
					    $tmps = substr($value, $pos1+5, $pos2-$pos1-5);
					    $key = $key . "-" . $tmps;
        				} 
				        $pos1 = strpos($value, "<");
				        $pos2 = strpos($value, ">");
					$tmps = substr($value, $pos1+1, $pos2-$pos1-1);
					$value = $tmps;
				   }
				   $headers[$key] = $value;
            		       }
			   }
			}
			$result = json_decode($body);
			if (!is_null($result->error)) {
				echo "<p>Mendeley Plugin Error: Got return code " . $result->error . " when trying to access Mendeley API</p>";
				$error_message = $result->error;
			}
			return $result;
		}
		
		// shortcode for displaying a list of references (documents)
		function processShortcodeList($attrs = null) {
			return $this->formatCollection($attrs);
		}

		// shortcode for displaying details for a particular reference (document)
		function processShortcodeDetails($attrs = null, $content = null) {
			$docid = $_GET["docid"];
			if (!$docid) {
				return 'MendeleyPlugin: No docid specified on details page.';
			}
			if (!$content) {
				$templatefile = __DIR__.'/mendeley-details-template.tpl';
				if(file_exists($templatefile)){
                                	$content = file_get_contents($templatefile);
                        	}
                        	else {
					return 'MendeleyPlugin: No template or template file available for details page.';
                        	}
			}
			$doc = $this->getDocument($docid);

			// load document file to cache if option is switched on
			if ($this->settings['cache_files'] === "true") {
			   $this->loadFileToCache($doc);
			}

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
						case 'authors':
                        		                $tmps = $this->detailsFormat($doc, $matches[1][$i]);
						        break;
						case 'editors':
                        		                $tmps = $this->detailsFormat($doc, $matches[1][$i]);
						        break;
						case 'mendeley_url':
                        		                $tmps = $this->detailsFormat($doc, $matches[1][$i]);
						        break;
						case 'url':
                        		                $tmps = $this->detailsFormat($doc, $matches[1][$i]);
						        break;
						case 'doi':
                        		                $tmps = $this->detailsFormat($doc, $matches[1][$i]);
						        break;
						case 'isbn':
                        		                $tmps = $this->detailsFormat($doc, $matches[1][$i]);
						        break;
						case 'filelink':
							$tmps = "";
						        $url = $this->getFileCacheUrl($doc);
							if (!$url==null) {
							   $tmps = "<a href='$url'>PDF</a>";
							}
							break;
						case 'coverimage':
							$tmps = "";
						        $url = $this->getCoverImageUrl($doc);
							if (!$url==null) {
							   $tmps = "<img src='$url' align='left' style='margin-right: 10px;'/>";
							}
							break;
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
				case 'doi' :
				        return $doc->identifiers->doi;
					break;
				case 'url' :
				        return $doc->websites[0];
					break;
				case 'mendeley_url' :
				        return "http://www.mendeley.com/research/".$doc->id."/";
					break;
				default:
					return $doc->$token;
			}
		}
		
		/* This is the main function in the plugin - creating a formatted list of references
		   input parameters are:
		   - attrs: different attribute specifying what should be included in the list
  		     - type = "folders", "groups"
		     - id = UUID
		     - groupby
		     - grouporder = "desc", "asc"
		     - sortyby
		     - sortorder
		     - csl
		     - csladd
		     - filter
		     - maxdocs
		   - maxdocs: a maximum number of references to be included in the list or 0 if there is no maximum
		   - style: the style to format the references - possible values (comma separated) are "short", "cover", "link"
		*/
		function formatCollection($attrs = NULL, $maxdocs = 0, $style="") {
			$type = $attrs['type'];
			if (empty($type)) { $type = "folders"; }
			// map singular cases to plural
			if ($type === "folder") { $type = "folders"; }

			$id = $attrs['id'];
			if (empty($id)) { $id = 0; }
			if ($type === 'own') {
			   $id = 0;
			   $type = "groups";
			}

			// overwrite parameter if set ...
			$tmpstyle = $attrs['style'];
			if (!empty($tmpstyle)) { $style = $tmpstyle; }
			$showcover = false;
			$showlink = false;
			if ($style) {
			   if (strpos($style, 'cover') !== false) {
			      $showcover = true;
			   }
			   if (strpos($style, 'link') !== false) {
			      $showlink = true;
			   }
			}

			if ($type === "group") { $type = "groups"; }
			// map illegal types to "groups"
			if (!(($type === "groups") OR ($type === "folders"))) {
			   $type = "groups";
			}
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
			   if ($this->settings['debug'] === 'true') {
				$result .= "<p>Mendeley Plugin: using cached output</p>";
			   }
			   return $result . $cacheresult;
			}
			$cacheresult = "";

			// type can be folders or groups
			if ($type === 'groups') {
			   $docarr = $this->getDocumentsForGroup($id);
			} else { // 'folders'
			   $res = $this->getDocumentListForFolder($id);
			   // now get the document data
			   $docarr = $this->getDocuments($res);
			}

			// and now sort and/or group the documents
			if (isset($sortby)) {
			   $docarr = $this->groupDocuments($docarr, $sortby, $sortorder);
			}
			if (isset($groupby)) {
			   $docarr = $this->groupDocuments($docarr, $groupby, $grouporder);
			} 

			if ($this->settings['debug'] === 'true') {
				$result .= "<p>Mendeley Plugin: Unfiltered results count: " . count($docarr) . " ($error_message)</p>";
			} else {
				if (strlen($error_message)>0) {
					$result .= "<p>Mendeley Plugin: no results - error message: $error_message</p>";
					$error_message = "";
				}
			}

			// process all refrerences in document array (list of results) 
			// - apply filter if specified and print groupby headings if needed
			$countfiltered = 0;
			$currentgroupbyval = "";
			$groupbyval = "";
			foreach($docarr as $doc) {
				// check filter
				if (!is_null($filter)) {
					$filtertrue = $this->checkFilter($filter, $doc);
					if ($filtertrue == 0) { continue; }
					$countfiltered++;
				}
				// check if groupby-value has changed
				if (isset($groupby)) {
					$groupbyval = $doc->$groupby;
					if (!($groupbyval === $currentgroupbyval)) {
						$result = $result . '<h2 class="wpmgrouptitle">' . $groupbyval . '</h2>';
						$cacheresult = $cacheresult . '<h2 class="wpmgrouptitle">' . $groupbyval . '</h2>';
						$currentgroupbyval = $groupbyval;
					}
				}

				if (($showcover == true) or ($showlink == true)) {
				   // load document file to cache if option is switched on
				   if ($this->settings['cache_files'] === "true") {
			   	      $this->loadFileToCache($doc);
				   }
				}
			        if (strpos($style, 'short') !== false) {
				   $tmps = '<li class="wpmlistref">' . $this->formatDocumentShort($doc,Null,$showcover,$showlink) .  '</li>';
				   $result .= $tmps;
				   $cacheresult .= $tmps;
				} else {
				   // do static formatting or formatting with CSL stylesheet
				   $tmps = $this->formatDocument($doc,$csl,False,$showcover,$showlink);
				   $result .= $tmps;
				   $cacheresult .= $tmps;
				}

				if ($maxdocs > 0) {
					if ($countfiltered >= $maxdocs) {
					   if ($this->settings['debug'] === 'true') {
					      $result .= "<p>Mendeley Plugin: aborting output because maximum number of documents ($maxdocs) reached</p>";
					   }		      
					   break;
					}
				}	
			}
			if ($this->settings['debug'] === 'true') {
				$result .= "<p>Mendeley Plugin: Filtered results count: " . $countfiltered . "</p>";
			}
			$this->updateOutputInCache($cacheid, $cacheresult);
			return $result;
		}		

		/* check if a given document ($doc) matches the given filter
		   return 1 if the check is true, 0 otherwise */
		function checkFilter($filter, $doc) {
			if (!isset($filter)) {
			   return 1;
			}
			$filter = print_r($filter, true); // there seem to be problems with non-string filter values
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
			   switch ($filterattr) {
			   case "author":
				$author_arr = $doc->authors;
				if (is_array($author_arr)) {
					$tmps = $this->comma_separated_names($author_arr);
                       			if (stristr($tmps, $filterval) === FALSE) {
                               			return 0;
                       			}
				} else { return 0; } // if there is no author attribute ...
				break;
			   case "editor":
                               	$editor_arr = $doc->editors;
				if (is_array($editor_arr)) {
					$tmps = $this->comma_separated_names($editor_arr);
                       			if (stristr($tmps, $filterval) === FALSE) {
                               			return 0;
                       			}
				} else { return 0; }
				break;
			   case "tag":
			   case "tags":
                               	$tag_arr = $doc->tags;
				$ismatch = 0;
				if (is_array($tag_arr)) {
                               		for($i = 0; $i < sizeof($tag_arr); ++$i) {
                               			if (!(stristr($tag_arr[$i], $filterval) === FALSE)) {
                               				$ismatch = 1;
						}
                               		}
                               	} else {
				        if (!(stristr($tag_arr, $filterval) === FALSE)) {
						$ismatch = 1;
					}
				}
				if ($ismatch == 0) {
				   	return 0;
				}
				break;
			   case "keyword":
			   case "keywords":
                               	$keyword_arr = $doc->keywords;
				$ismatch = 0;
				if (is_array($keyword_arr)) {
                               		for($i = 0; $i < sizeof($keyword_arr); ++$i) {
                               			if (!(stristr($keyword_arr[$i], $filterval) === FALSE)) {
                               				$ismatch = 1;
						}
                               		}
                               	} else {
				        if (!(stristr($tag_arr, $filterval) === FALSE)) {
						$ismatch = 1;
					}
                               	}
				if ($ismatch == 0) {
				   return 0;
				}
				break;
			   default:
                               	// other attributes
				if (!isset($doc->{$filterattr})) {
				   continue;
				}
                                if (!(strcmp($filterval, $doc->{$filterattr})==0)) {
				   return 0;
                                }
			   } // switch
			} // foreach singlefilter
			return 1;
		}

		
		/* get the information for all documents in a Mendeley group
		   and return them in an array */
		function getDocumentsForGroup($id) {
			global $headers; // headers from sendAuthorizedRequest()
			if (is_null($id)) return NULL;
			// check cache
			$cacheid="documents-group-$id";
			$docarr = $this->getDocumentListFromCache($cacheid);
			if (!is_null($docarr)) {
				return $docarr;
			}

			$request_count = 500;
			$url = "documents?group_id=$id&view=all&order=desc&sort=created&limit=$request_count";
			if ("$id" === "0") { // "own"
			   $url = "documents?group_id=&authored=true&view=all&order=desc&sort=created&limit=$request_count";
			}
			$docarr = $this->sendAuthorizedRequest($url);
			$mendeley_count = 0 + $headers["Mendeley-Count"];
			if ($mendeley_count > $request_count) { // pagination ...
  			    $url = $headers["Link-next"];
			    while ($url) {
			        $docarradd = $this->sendAuthorizedRequest($url);
				$docarr = array_merge($docarr, $docarradd);
  			        $url = $headers["Link-next"];
			    }
			}

			// update cache
			if (is_array($docarr)) {
			   $this->updateDocumentListInCache($cacheid, $docarr);
			} else {
			   $docarr = array();
			}
			return $docarr;
		}


		/* get the ids of all documents in a Mendeley folder
		   and return them in an array */
		function getDocumentListForFolder($id) {
			if (is_null($id)) return NULL;
			// check cache
			$cacheid="doclist-folder-$id";
			$docids = $this->getDocumentListFromCache($cacheid);
			if (!is_null($docids)) {
				return $docids;
			}

			$request_count = 500;
			$url = "folders/$id/documents?limit=$request_count&view=all";
			$docids = $this->sendAuthorizedRequest($url);
			if (is_null($docids)) {
				$docids = array(0 => $result->id);
			}
			$mendeley_count = 0 + $headers["Mendeley-Count"];
			if ($mendeley_count > $request_count) { // pagination ...
  			    $url = $headers["Link-next"];
			    while ($url) {
			        $docidsadd = $this->sendAuthorizedRequest($url);
				if ($docidsadd) {
				    $docids = array_merge($docids, $docidsadd);
  			            $url = $headers["Link-next"];
				} else { $url = null; }
			    }
			}

			$this->updateDocumentListInCache($cacheid, $docids);
			return $docids;
		}
		
		/* get all attributes (array) for a given document */
		function getDocument($docid) {
			if (is_null($docid)) return NULL;
			// check cache
			$result = $this->getDocumentFromCache($docid);
			if (!is_null($result)) return $result;
			$url = "documents/$docid?view=all";
			$result = $this->sendAuthorizedRequest($url);
			$this->updateDocumentInCache($docid, $result);
			return $result;
		}
		/* get the ids/names of all groups for the current user */
		function getGroups() {
			$url = "groups/?limit=500"; // 500 is the maximum possible
			$result = $this->sendAuthorizedRequest($url);
			return $result;
		}
		/* get the ids/names of all folders for the current user */
		function getFolders() {
			$url = "folders/?limit=500"; // 500 is the maximum possible
			$result = $this->sendAuthorizedRequest($url);
			return $result;
		}

		/* get the meta information (array) for all document ids in
		   the array given as an input parameter */
		function getDocuments($docidarr, $count=0) {
			$res = array();
			if(is_array($docidarr)) {
			   if ($count == 0) { $count = sizeof($docidarr); }
			   for($i=0; $i < $count; $i++) {
				$docid = $docidarr[$i]->id;
				$doc = $this->getDocument($docid);
				$res[] = $doc;
			   }
			}
			return $res;
		}
		
		/* sort and group the documents that have been loaded using loadDocumentData,
		   i.e. $docarr holds an array of meta information arrays, after
		   the function ran, the meta information arrays (document objects)
		   will be grouped according to the groupby parameter. */
		function groupDocuments($docarr, $groupby, $sortorder) {
			if (!is_array($docarr)) {
			   return $docarr;
			}
			$grpvalues = array();
			for($i=0; $i < sizeof($docarr); $i++) {
				$doc = $docarr[$i];
				if (isset($doc->$groupby)) {
					$grpval = $doc->$groupby;
					// If array (like authors, take the first one)
					if (isset($grpval)) {
					    if (is_array($grpval)) {
						if (is_object($grpval[0])) {
							if (isset($grpval[0]->last_name)) {
								$grpval = $grpval[0]->last_name . $grpval[0]->first_name;
							} else {
								$grpval = strval(print_r($grpval[0], true));
							}
						} else {
							$grpval = strval($grpval[0]);
						}
					    }
					}
				}
				if (isset($grpval)) {
					$grpval = $grpval . $doc->added;
					$grpvalues[$grpval][] = $doc;
				}
			}

			if (stripos($sortorder, "asc") === false) { 
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
			type  ['journal' or 'book' or 'generic' or 'book_section' or 'conference_proceedings' or 'working_paper' or 'report' or 'web_page' or 'thesis' or 'magazine_article' or 'statute' or 'patent' or 'newspaper_article' or 'computer_program' or 'hearing' or 'television_broadcast' or 'encyclopedia_article' or 'case' or 'film' or 'bill']
			title
			month
			year
			created
			authors*
			editors*
			source
			issue
			volume
			city
			publisher
			abstract
			pages
			tags*
			keywords*
			identifiers* (issn,...)
			websites*
			institution
			city
			edition
			series_editor
			translators*
			country
			genre			
			publisher
			series
			chapter
			id
		*/
		function formatDocument($doc, $csl=Null, $textonly=False, $showcover=False, $showlink=False) {
			$result = '';

                        // format document with a given CSL style and the CiteProc.php
                        if ($csl != Null && class_exists("citeproc")){
                        	// read the given CSL style from XML document, load it to a string, and convert it to an object
				$cacheid = "csl-".$csl;
				$csl_file = $this->getOutputFromCache($cacheid);
				if (empty($csl_file)) {
				        $curl = curl_init($csl);
					curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                        		$csl_file = curl_exec($curl);
					if (curl_getinfo($curl,CURLINFO_HTTP_CODE) < 400) {
					   if ($csl_file !== false) {
					      $this->updateOutputInCache($cacheid, $csl_file);
					   } else {
					      echo "<p>Mendeley Plugin Error: Failed accessing Menedley API: " . curl_error($curl) . "</p>";
					   }
					} else {
					   echo "<p>Mendeley Plugin Error: Failed accessing Mendeley API: " . curl_getinfo($curl,CURLINFO_HTTP_CODE) . "</p>";
					   $csl_file = "";
					}
					curl_close($curl);
				}
                        	$csl_object = simplexml_load_string($csl_file);

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
                                if (isset($doc->source)) {
                                        $docdata->container_title = $doc->source;
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
                                if (isset($doc->websites)) {
                                   if (isset($doc->websites[0])) {
                                        $docdata->URL = $doc->websites[0];
				   }
				}
                                if (isset($doc->identifiers)) {
                                        $docdata->DOI = $doc->identifiers->doi;
                                        $docdata->ISBN = $doc->identifiers->isbn;
                                        $docdata->PMID = $doc->identifiers->pmid;
				}
				// show cover image?
			        if (!$textonly) {
				   if ($showcover) {
			   	      $url = $this->getCoverImageUrl($doc);
			   	      if (!$url==null) {
			      	         $result .= "<img src='$url' align='left' width='50' style='margin-right: 5px;'/>";
			   	      }
				   }
				}
                                // execute citeproc with new stdClass
                                $cp = new citeproc($csl_file);
                                $result .= $cp->render($docdata,'bibliography')."\n";
				if ($showlink) {
				   $url = $this->getFileCacheUrl($doc);
				   if (!$url==null) {
				      $result .= " <a href='$url'>PDF</a>";
				   }
				}
				$result .= "<br clear='all'/>\n";
			}
                        else {
			    if (!$textonly) {
                        	$result .= '<p class="wpmref">';
				// show cover image?
				if ($showcover) {
			   	   $url = $this->getCoverImageUrl($doc);
			   	   if (!$url==null) {
			      	      $result .= "<img src='$url' align='left' width='50' style='margin-right: 5px;'/>";
			   	   }
				}
			    }
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
				if (isset($doc->source)) {
					$result .= ', ' . $doc->source;
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
				if (isset($doc->identifiers)) {
				   if (isset($doc->identifiers->doi)) {
                                	$result .= ', doi:' . $doc->identifiers->doi;
				   }
				}
				} else {
				$result .= '<span class="wpmauthors">' . $authors . '</span> ' .
			        	'<span class="wpmyear">(' . $doc->year . ')</span> ' . 
			        	'<span class="wpmtitle">' . $doc->title . '</span>';
				if (isset($doc->source)) {
					$result .= ', <span class="wpmoutlet">' . 
				    	$doc->source . '</span>';
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
				if (isset($doc->websites)) {
                                   if (isset($doc->websites[0])) {
				        $url = $doc->websites[0];
					foreach(explode(chr(10),$url) as $urlitem) {
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
				   }
				}
				if (isset($doc->identifiers)) {
				   if (isset($doc->identifiers->doi)) {
                                	$atext = "doi:" . $doc->identifiers->doi;
                                	$result .= ', <span class="wpmurl"><a target="_blank" href="http://dx.doi.org/' . $doc->identifiers->doi . 
						'"><span class="wpmurl' . $atext . '">' . $atext . '</span></a></span>';
                                   }
				}
		             }
			     if ($showlink) {
				$url = $this->getFileCacheUrl($doc);
				if (!$url==null) {
				   $result .= " <a href='$url'>PDF</a>";
				}
			     }
			     if (!$textonly) { 
				$result .= "<br clear='all'/>";
			        $result .= '</p>' . "\n"; 
		             }
			}
			return $result;
		}

		function &mendeleyNames2CiteProcNames($names) {
			if (!$names) return $names;
			foreach ($names as $rank => $name) {
				$name->given = $name->first_name;
				$name->family = $name->last_name;
			}
			return $names;
		}
		function mendeleyType2CiteProcType($type) {
			if (!isset($this->type_map)) {
				$this->type_map = array(
						'Book' => 'book',
						'book' => 'book',
						'Book Section' => 'chapter',
						'book_section' => 'chapter',
						'Journal Article' => 'article-journal',
						'journal' => 'article-journal',
						'Magazine Article' => 'article-magazine',
						'magazine_article' => 'article-magazine',
						'Newspaper Article' => 'article-newspaper',
						'newspaper_article' => 'article-newspaper',
						'Conference Proceedings' => 'paper-conference',
						'conference_proceedings' => 'paper-conference',
						'Report' => 'report',
						'report' => 'report',
						'working_paper' => 'report',
						'Thesis' => 'thesis',
						'thesis' => 'thesis',
						'Case' => 'legal_case',
						'Encyclopedia Article' => 'entry-encyclopedia',
						'encyclopedia_article' => 'entry-encyclopedia',
						'Web Page' => 'webpage',
						'web_page' => 'webpage',
						'Working Paper' => 'report',
						'Generic' => 'chapter', 
						);
			}
			return $this->type_map[$type];
		}
		
		function formatDocumentShort($doc,$csl=Null,$showcover=false,$showlink=false) {
			if ($this->settings['detail_tips'] === 'false') {
				$tmps = '<span class="wpmtitle">';
			} else {
				$tmps = '<span class="wpmtitle" title="'.$this->formatDocument($doc,$csl,true).'">';
			}
			if ($showcover) {
			   $url = $this->getCoverImageUrl($doc);
			   if (!$url==null) {
			      $tmps .= "<img src='$url' align='left' width='50' style='margin-right: 5px;'/>";
			   }
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
				if (isset($doc->websites)) {
                                   if (isset($doc->websites[0])) {
					$url = $doc->websites[0];
					$tmps .= '<a href="' .  $url . '">' . $doc->title . '</a>';
				   }
				} else {
					$tmps .= '<a href="http://www.mendeley.com/research/' . $doc->id . '/">' . $doc->title . '</a>';
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
			$tmps .= '"id" : "http://www.mendeley.com/research/' . $doc->id . '/"' . ",\n";
			$tmps .= '"pub-type": ' . json_encode($doc->type) .  ",\n"; 
			$tmps .= '"label" : ' . json_encode($doc->title) . ",\n";
			if (isset($doc->source)) {
				$tmps .= '"booktitle" : ' . json_encode($doc->source) . ",\n";
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
			if (isset($doc->websites)) {
                           if (isset($doc->websites[0])) {
			        $url = $doc->websites[0];
				$tmps .= '"url" : ' . json_encode($url) . ",\n";
			   }
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
		function getDocumentListFromCache($cid) {
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
		function updateDocumentListInCache($cid, $doc) {
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

		/* functions dealing with a file cache (for caching PDF files
		   and thumbnail images */

		function initFileCache() {
		   // check if DIR exists and create if neccessary
		   if (!file_exists(ABSPATH . '/wp-content/cache/')) {
		      mkdir(ABSPATH . '/wp-content/cache/');
		   }
		   if (!file_exists(FILE_CACHE_DIR)) {
		      mkdir(FILE_CACHE_DIR);
		   }
		   if (!file_exists(FILE_CACHE_DIR . "failed/")) {
		      mkdir(FILE_CACHE_DIR . "failed/");
		   }
		}

		function fileExistsInCache($doc) {
		   $filename = FILE_CACHE_DIR . $doc->id."pdf";
		   if (file_exists($filename)) {
		      return true;
		   }
		   return false;
		}

		function deleteFileFromCache($doc) {
		   if ($this->filesExistsInCache($doc)) {
		      $filename = FILE_CACHE_DIR . $doc->id."pdf";
		      unlink($filename);
		   } 
		   unlink(FILE_CACHE_DIR . "failed/" . $doc->id);
		}

		function hasFailedToCacheFile($doc) {
		   if (file_exists(FILE_CACHE_DIR . "failed/" . $doc->id)) {
		      return true;
		   }
		   return false;
		}

		function setFailedToCacheFile($doc, $value) {
		   if ($value = false) {
		      unlink(FILE_CACHE_DIR . "failed/" . $doc->id);
		   } else {
		      if (!file_exists(FILE_CACHE_DIR . "failed/" . $doc->id)) {
		         file_put_contents(FILE_CACHE_DIR . "failed/" . $doc->id, "");
		      }
		   }
		}

		// load pdf file for given document to cache (if it exists)
		function loadFileToCache($doc) {
		   global $headers;

		   $fileid = null;
		   $file_name = null;
		   $filehash = null;

		   // check if we already have a file in the cache
		   $filename = FILE_CACHE_DIR . $doc->id . ".pdf";
		   if (file_exists($filename)) {
		      return true;
		   }
		   $this->setFailedToCacheFile($doc, false);

		   // first get file information from Mendeley API
		   $url = "files?document_id=" . $doc->id;
		   $filearr = $this->sendAuthorizedRequest($url);
		   if (is_array($filearr)) {
		      for ($i=0; $i < sizeof($filearr); $i++) {
		         $file = $filearr[$i];
			 if ($file->mime_type == "application/pdf") {
			    $fileid = $file->id;
			    $file_name = $file_name;
			    $filehash = $file->filehash;
			 }
		      }
		   }
		   if (!$fileid) {
		      return false;
		   }

		   // now try to load the file
		   $url = "files/".$fileid;
		   $filename = $doc->id.".pdf";
		   $result = $this->sendAuthorizedRequest($url);
             	   $fileurl = $headers['Location'];
		   if ($fileurl) {
		      $content = file_get_contents($fileurl);
		      file_put_contents(FILE_CACHE_DIR . $filename, $content);
		      return true;
		   } else {
		      $this->setFailedToCache($doc, true);
		   } 
		   return false;
		}

		// get the URL to the cached copy of a PDF file or null if there is no cached copy
		function getFileCacheUrl($doc) {
		   // check if url should/can be dispayed
		   $tags = $doc->tags;
		   foreach($tags as $tag) {
		      if ($tag === "nofilelink") {
		         return null;
		      }
		   }
		   $filename = FILE_CACHE_DIR . $doc->id . ".pdf";
		   if (file_exists($filename)) {
		      return FILE_CACHE_URL . $doc->id . ".pdf";
		   }
		   return null;
		}

		// get the URL to the cover image png (and generate it if needed)
		function getCoverImageUrl($doc) {
		   $filename = FILE_CACHE_DIR . $doc->id . ".png";
		   if (file_exists($filename)) {
		      return FILE_CACHE_URL . $doc->id . ".png";
		   }
		   $filenamepdf = FILE_CACHE_DIR . $doc->id . ".pdf";
		   if (!file_exists($filenamepdf)) {
		      return null;
		   }
		   // generate png ...
		   $im = new imagick($filenamepdf."[0]");
                   $im->resampleImage (10, 10, imagick::FILTER_UNDEFINED,1);
                   $im->setCompressionQuality(80);
                   $im->setImageFormat('png');
                   $im->writeImage($filename);
                   $im->clear();
                   $im->destroy();
		   return FILE_CACHE_URL . $doc->id . ".png";
		}


		function getOptions() {
			if ($this->settings != null)
				return $this->settings;
			$this->settings = array(
				'debug' => 'false',
				'cache_collections' => 'week',
				'cache_docs' => 'week',
				'cache_output' => 'day',
				'cache_files' => 'false',
				'oauth2_client_id' => '',
				'oauth2_client_secret' => '',
				'oauth2_access_token' => '',
				'version' => PLUGIN_VERSION,
				'db_version' => 0 );
			$tmpoptions = get_option($this->adminOptionsName);
			if (!empty($tmpoptions)) {
				foreach ($tmpoptions as $key => $option)
					$this->settings[$key] = $option;
			}
			update_option($this->adminOptionsName, $this->settings);			
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
 				$singleNameObject = $singleNameObject->first_name . ' ' . $singleNameObject->last_name;
			}
			return array_reduce($nameObjectsArray, array(&$this, "comma_concatenate"));
		}

		
		/**
		 *
		 */
		function printAdminPage() {
			$this->getOptions();
			$callback_url = admin_url('options-general.php?page=wp-mendeley.php&access_mendeleyPluginOAuth2=true');
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
				if (isset($_POST['cacheFiles'])) {
					$this->settings['cache_files'] = $_POST['cacheFiles'];
				}
				if (isset($_POST['oauth2ClientId'])) {
					$this->settings['oauth2_client_id'] = $_POST['oauth2ClientId'];
				}
				if (isset($_POST['oauth2ClientSecret'])) {
					$this->settings['oauth2_client_secret'] = stripslashes($_POST['oauth2ClientSecret']);
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
			if (isset($_POST['emptycache_mendeleyPlugin'])) {
			        global $wpdb;
			        $table_name = $wpdb->prefix . "mendeleycache";
				$sql = "delete from ".$table_name." where id>0";
				require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
				dbDelta($sql);
?>
<div class="updated"><p><strong><?php _e("Caches emptied.", "MendeleyPlugin"); ?></strong></p></div>
<?php
			}
			if (isset($_POST['request_mendeleyPluginOAuth2'])) {
				if (isset($_POST['oauth2ClientId'])) {
					$this->settings['oauth2_client_id'] = $_POST['oauth2ClientId'];
				}
				if (isset($_POST['oauth2ClientSecret'])) {
					$this->settings['oauth2_client_secret'] = stripslashes($_POST['oauth2ClientSecret']);
				}
				update_option($this->adminOptionsName, $this->settings);

				$client_id = $this->settings['oauth2_client_id'];
                		$client_secret = $this->settings['oauth2_client_secret'];
				$auth_url = OAUTH2_AUTHORIZE_ENDPOINT . "?client_id=$client_id&response_type=code&scope=all&redirect_uri=".urlencode($callback_url);
				redirect($auth_url);
				exit;
			}
			// check if we should start a access_token request (callback) (OAuth2)
			if (isset($_GET['access_mendeleyPluginOAuth2']) &&
				(strcmp($_GET['access_mendeleyPluginOAuth2'],'true')==0)) {
				if (isset($_POST['error'])) {
?>
<div class="updated"><p><strong><?php _e("Failed OAuth2 authorization: ".$_POST['error'], "MendeleyPlugin"); ?></strong></p></div>
<?php
				}
				if (isset($_GET['code'])) {
				   $client_id = $this->settings['oauth2_client_id'];
                		   $client_secret = $this->settings['oauth2_client_secret'];

				   // retrieve full authorization token
				   $curl = curl_init(OAUTH2_REQUEST_TOKEN_ENDPOINT);
				   curl_setopt($curl, CURLOPT_POST, true);
				   curl_setopt($curl, CURLOPT_POSTFIELDS, 
				       "grant_type=authorization_code&code=".urlencode($_GET['code'])."&client_id=".urlencode($client_id)."&client_secret=".urlencode($client_secret)."&redirect_uri=".urlencode($callback_url)
				       );
				   curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1);
				   // basic authentication ...
				   curl_setopt($curl, CURLOPT_HTTPHEADER, array(
				       "Authorization: Basic " . base64_encode($client_id . ":" . $client_secret)
				       )); // do not addslashes or urlencode here!!!!
				   $auth = curl_exec($curl);
				   if (!$auth) {
				      $auth = curl_error($curl);
				      $access_token = nil;
				   } else {
				      $secret = json_decode($auth);
				      $access_token = $secret->access_token;
				   }
				   if (strlen("$access_token")>0) {
 				      $this->settings['oauth2_access_token'] = $access_token;
				      $expires_in = $secret->expires_in;
 				      $this->settings['oauth2_expires_at'] = time()+(integer)$expires_in;
 				      $this->settings['oauth2_refresh_token'] = $secret->refresh_token;
				      update_option($this->adminOptionsName, $this->settings);
?>
<div class="updated"><p><strong><?php _e("New OAuth2 access token retrieved.", "MendeleyPlugin"); ?></strong></p></div>
<?php
				   } else {
?>
<div class="updated"><p><strong><?php _e("Failed retrieving OAuth2 access token: $auth", "MendeleyPlugin"); ?></strong></p></div>
<?php
				   }
				}
			}
			// delete the access token
			if (isset($_POST['delete_mendeleyPluginOAuth2'])) {
 			      $this->settings['oauth2_access_token'] = "";
			      update_option($this->adminOptionsName, $this->settings);
?>
<div class="updated"><p><strong><?php _e("OAuth2 access token deleted.", "MendeleyPlugin"); ?></strong></p></div>
<?php
			}
			// refresh the access token
			if (isset($_POST['refresh_mendeleyPluginOAuth2'])) {
			   $client_id = $this->settings['oauth2_client_id'];
                	   $client_secret = $this->settings['oauth2_client_secret'];
			   // retrieve new authorization token
			   $curl = curl_init(OAUTH2_REQUEST_TOKEN_ENDPOINT);
			   curl_setopt($curl, CURLOPT_POST, true);
			   curl_setopt($curl, CURLOPT_POSTFIELDS, 
			      "grant_type=refresh_token&refresh_token=".urlencode($this->settings['oauth2_refresh_token'])."&client_id=".urlencode($client_id)."&client_secret=".urlencode($client_secret)."&redirect_uri=".urlencode($callback_url)
				       );
			   curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1);
		 	   // basic authentication ...
			   curl_setopt($curl, CURLOPT_HTTPHEADER, array(
			      "Authorization: Basic " . base64_encode($client_id . ":" . $client_secret)
			      )); // do not addslashes or urlencode here!!!!
			   $auth = curl_exec($curl);
			   if (!$auth) {
			      $auth = curl_error($curl);
			      $access_token = nil;
			   } else {
			      $secret = json_decode($auth);
			      $access_token = $secret->access_token;
			   }
			   if (strlen("$access_token")>0) {
 			      $this->settings['oauth2_access_token'] = $access_token;
			      $expires_in = $secret->expires_in;
 			      $this->settings['oauth2_expires_at'] = time()+(integer)$expires_in;
 			      $this->settings['oauth2_refresh_token'] = $secret->refresh_token;
			      update_option($this->adminOptionsName, $this->settings);
?>
<div class="updated"><p><strong><?php _e("OAuth2 access token refreshed.", "MendeleyPlugin"); ?></strong></p></div>
<?php
			   } else {
?>
<div class="updated"><p><strong><?php _e("Failed refreshing OAuth2 access token: $auth", "MendeleyPlugin"); ?></strong></p></div>
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
<li>- [mendeley type="groups" id="f506234b-5892-3044-a805-462121e73a1e" sortby="year" sortorder="desc"]
<li>- [mendeley type="groups" id="f506234b-5892-3044-a805-462121e73a1e" groupby="" filter=""], filter=ATTRNAME=AVALUE[;ATTRNAME=AVALUE], e.g. author=Michael Koch
<li>- [mendeley type="groups" id="f506234b-5892-3044-a805-462121e73a1e" csl="http://DOMAINNAME/csl/csl_style.csl"]
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

<p>To turn on caching is important, because Mendeley currently imposes a rate limit to requests to the service. See <a href="http://dev.mendeley.com/docs/rate-limiting">http://dev.mendeley.com/docs/rate-limiting</a> for more details on this restriction.</p>

<p>
 Cache files
    <select name="cacheFiles" size="1">
      <option value="false" id="false" <?php if ($this->settings['cache_files'] === "false") { echo(' selected="selected"'); }?>>no</option>
      <option value="true" id="true" <?php if ($this->settings['cache_files'] === "true") { echo(' selected="selected"'); }?>>yes</option>
    </select><br/>
</p>

<p>File caching will download PDFs associated with the documents to the local machine and make them available in the details view.</p>

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
<div class="submit">
<input type="submit" name="emptycache_mendeleyPlugin" value="Empty Caches">
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
the information from Mendeley Groups and Folders. For using this API you first need to request a Client Id and a Client Secret from Mendeley. These values have to be entered in the following two field. To request the key
and the secret go to <a href="http://dev.mendeley.com/">http://dev.mendeley.com/</a> and register a new application.</p>

<p>Mendeley plugin redirection URL (to copy for your <a href="http://dev.mendeley.com/applications/register/">client id request at Mendeley</a>)<br/>
<input type="text" readonly="readonly" name="oauth2RedirectionUrl" value="<?php echo $callback_url; ?>" size="80"></input></p>
<p>Mendeley API Client Id<br/>
<input type="text" name="oauth2ClientId" value="<?php echo $this->settings['oauth2_client_id']; ?>" size="60"></input></p>
<p>Mendeley API Client Secret<br/>
<input type="text" name="oauth2ClientSecret" value="<?php echo $this->settings['oauth2_client_secret']; ?>" size="60"></input></p>
<p>Mendeley Access Token<br/>
<input type="text" readonly="readonly" name="oauth2AccessToken" value="<?php echo $this->settings['oauth2_access_token']; ?>" size="80"></input><br/>
Expires at: <?php echo date('d.m.Y H:i:s', $this->settings['oauth2_expires_at']); echo " (current time: ". date('d.m.Y H:i:s', time()).")";  ?></p>

<p>Since Groups and Folders are user-specific, the plugin needs to be authorized to access this 
information in the name of a particular user. The Mendeley API uses the OAuth2 protocol for doing this. 
When you press the button bellow, the plugin requests authorization from Mendeley. Therefore, you will be asked by
Mendeley to log in and to authorize the request from the login. As a result an Access Token will be generated
and stored in the plugin.</p>

<div class="submit">
<input type="submit" name="request_mendeleyPluginOAuth2" value="Request and Authorize Access Token">
<input type="submit" name="refresh_mendeleyPluginOAuth2" value="Refresh Access Token">
<input type="submit" name="delete_mendeleyPluginOAuth2" value="Delete Access Token">
</div>

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
			return $this->formatCollection($attrs, $maxdocs, "short");
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

			if ($type === 'own') {
			   $id = 0;
			   $type = "groups";
			}

			// type can be folders or groups
			if ($type === 'groups') {
			   $docarr = $this->getDocumentsForGroup($id);
			} else { // 'folders'
			   $res = $this->getDocumentListForFolder($id);
			   // now get the document data
			   $docarr = $this->getDocuments($res);
			}

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
        parent::WP_Widget(false, $name = 'Mendeley My Publications');	
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

function detailsFormatMap1($el) { return $el->last_name.', '.$el->first_name; }
function detailsFormatMap2($el) { return $el; }

?>
