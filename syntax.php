<?php
/**
 * Plugin catlist : Displays a list of the pages of a namespace recursively
 *
 * @license   MIT
 * @author    Félix Faisant <xcodexif@xif.fr>
 *
 */

if (!defined('DOKU_INC')) die('meh.');

if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN', DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/search.php');
require_once(DOKU_INC.'inc/pageutils.php');
require_once(DOKU_INC.'inc/parserutils.php');

define('CATLIST_DISPLAY_LIST', 1);
define('CATLIST_DISPLAY_LINE', 2);

define('CATLIST_NSLINK_AUTO', 0);
define('CATLIST_NSLINK_NONE', 1);
define('CATLIST_NSLINK_FORCE', 2);

define('CATLIST_INDEX_START', 0);
define('CATLIST_INDEX_OUTSIDE', 1);
define('CATLIST_INDEX_INSIDE', 2);

define('CATLIST_SORT_NONE', 0);
define('CATLIST_SORT_ASCENDING', 1);
define('CATLIST_SORT_DESCENDING', 2);

class syntax_plugin_catlist extends DokuWiki_Syntax_Plugin {

	function connectTo ($aMode) {
		$this->Lexer->addSpecialPattern('<catlist[^>]*>', $aMode, 'plugin_catlist');
	}

	function getSort () {
		return 189;
	}

	function getType () {
		return 'substition';
	}
	
	/*********************************************************************************************/
	/************************************ <catlist> directive ************************************/
	
	function _checkOption(&$match, $option, &$varAffected, $valIfFound){
		if (preg_match('/-'.$option.' /i', $match, $found)) {
			$varAffected = $valIfFound;
			$match = str_replace($found[0], '', $match);
		}
	}
	function _checkOptionParam(&$match, $option, &$varAffected, $varAssoc){
		if (preg_match('/-'.$option.':('.implode('|',array_keys($varAssoc)).') /i', $match, $found)) {
			$varAffected = $varAssoc[$found[1]];
			$match = str_replace($found[0], '', $match);
		}
	}
	
	function handle ($match, $state, $pos, Doku_Handler $handler) {
		global $conf;

		$_default_sort_map = array("none" => CATLIST_SORT_NONE,
		                           "ascending" => CATLIST_SORT_ASCENDING,
		                           "descending" => CATLIST_SORT_DESCENDING);
		$_index_priority_map = array("start" => CATLIST_INDEX_START,
		                             "outside" => CATLIST_INDEX_OUTSIDE,
		                             "inside" => CATLIST_INDEX_INSIDE);

		$data = array('displayType' => CATLIST_DISPLAY_LIST, 'nsInBold' => true, 'expand' => 6,
		              'exclupage' => array(), 'excluns' => array(), 'exclunsall' => array(), 'exclunspages' => array(), 'exclunsns' => array(),
		              'exclutype' => 'id', 
		              'createPageButtonNs' => true, 'createPageButtonSubs' => false, 'pagename_sanitize' => (boolean)$this->getConf('pagename_sanitize'),
		              'head' => (boolean)$this->getConf('showhead'),
		              'headTitle' => NULL, 'smallHead' => false, 'linkStartHead' => true, 'hn' => 'h1',
		              'useheading' => (boolean)$this->getConf('useheading'),
		              'nsuseheading' => NULL, 'nsLinks' => CATLIST_NSLINK_AUTO,
		              'columns' => 0, 'maxdepth' => 0,
		              'sort_order' => $_default_sort_map[$this->getConf('default_sort')], 
		              'sort_by_title' => false, 'sort_by_type' => false, 'sort_by_date' => false, 'sort_collator' => $this->getConf('sort_collator_locale'),
		              'hide_index' => (boolean)$this->getConf('hide_index'),
		              'index_priority' => array(),
		              'nocache' => (boolean)$this->getConf('nocache'),
		              'hide_nsnotr' => (boolean)$this->getConf('hide_acl_nsnotr'), 'show_pgnoread' => false, 'show_perms' => (boolean)$this->getConf('show_acl'),
		              'show_leading_ns' => (boolean)$this->getConf('show_leading_ns'),
		              'show_notfound_error' => true );

		$index_priority = explode(',', $this->getConf('index_priority'));
		foreach ($index_priority as $index_type) {
			if (!array_key_exists($index_type, $_index_priority_map)) {
				msg("catlist: invalid index type in index_priority", -1);
				return false;
			}
			$data['index_priority'][] = $_index_priority_map[$index_type];
		}
		$match = utf8_substr($match, 9, -1).' ';
		
		// Display options
		$this->_checkOption($match, "displayList", $data['displayType'], CATLIST_DISPLAY_LIST);
		$this->_checkOption($match, "displayLine", $data['displayType'], CATLIST_DISPLAY_LINE);
		$this->_checkOption($match, "noNSInBold", $data['nsInBold'], false);
		if (preg_match("/-expandButton:([0-9]+)/i", $match, $found)) {
			$data['expand'] = intval($found[1]);
			$match = str_replace($found[0], '', $match);
		}
		$this->_checkOption($match, "noHeadTitle", $data['useheading'], false);
		$this->_checkOption($match, "forceHeadTitle", $data['useheading'], true);
		$data['nsuseheading'] = $data['useheading'];
		$this->_checkOption($match, "noNSHeadTitle", $data['nsuseheading'], false);
		$this->_checkOption($match, "hideNotFoundMsg", $data['show_notfound_error'], false);

		// Namespace options
		$this->_checkOption($match, "forceLinks", $data['nsLinks'], CATLIST_NSLINK_FORCE); // /!\ Deprecated
		$this->_checkOptionParam($match, "nsLinks", $data['nsLinks'], array( "none" => CATLIST_NSLINK_NONE, 
		                                                                     "auto" => CATLIST_NSLINK_AUTO, 
		                                                                     "force" => CATLIST_NSLINK_FORCE ));

		// Exclude options
		for ($found; preg_match("/-(exclu(page|ns|nsall|nspages|nsns)!?):\"([^\\/\"]+)\" /i", $match, $found); ) {
			$option = strtolower($found[1]);
			// is regex negated ?
			if (substr($option,-1) == "!") {
				$data[substr($option,0,-1)][] = array('regex' => $found[3], 'neg' => true);
			} else {
				$data[$option][] = array('regex' => $found[3], 'neg' => false);
			}
			$match = str_replace($found[0], '', $match);
		}
		for ($found; preg_match("/-(exclu(page|ns|nsall|nspages|nsns)) /i", $match, $found); ) {
			$data[strtolower($found[1])] = true;
			$match = str_replace($found[0], '', $match);
		}
		// Exclude type (exclude based on id, name, or title)
		$this->_checkOption($match, "excludeOnID", $data['exclutype'], 'id');
		$this->_checkOption($match, "excludeOnName", $data['exclutype'], 'name');
		$this->_checkOption($match, "excludeOnTitle", $data['exclutype'], 'title');
		// Exclude page/namespace id list
		$data['excludelist'] = array();
		for ($found; preg_match("/-exclude:\\{([^\\}]*)\\} /", $match, $found); ) {
			$list = explode(' ', $found[1]); 
			$data['excludelist'] = array_merge($data['excludelist'], $list);
			$match = str_replace($found[0], '', $match);
		}

		// Max depth
		if (preg_match("/-maxDepth:([0-9]+)/i", $match, $found)) {
			$data['maxdepth'] = intval($found[1]);
			$match = str_replace($found[0], '', $match);
		}

		// Columns
		if (preg_match("/-columns:([0-9]+)/i", $match, $found)) {
			$data['columns'] = intval($found[1]);
			$match = str_replace($found[0], '', $match);
		}

		// Head options
		$this->_checkOption($match, "noHead", $data['head'], false);
		$this->_checkOption($match, "showHead", $data['head'], true);
		$this->_checkOption($match, "smallHead", $data['smallHead'], true);
		$this->_checkOption($match, "noLinkStartHead", $data['linkStartHead'], false);
		if (preg_match("/-(h[1-5])/i", $match, $found)) {
			$data['hn'] = $found[1];
			$match = str_replace($found[0], '', $match);
		}
		if (preg_match("/-titleHead:\"([^\"]*)\"/i", $match, $found)) {
			$data['headTitle'] = $found[1];
			$match = str_replace($found[0], '', $match);
		}
		
		// Create page button options
		$this->_checkOption($match, "noAddPageButton", $data['createPageButtonNs'], false);
		$this->_checkOption($match, "addPageButtonEach", $data['createPageButtonSubs'], true);
		
		// Sorting options
		$this->_checkOption($match, "sortAscending", $data['sort_order'], CATLIST_SORT_ASCENDING);
		$this->_checkOption($match, "sortDescending", $data['sort_order'], CATLIST_SORT_DESCENDING);
		$this->_checkOption($match, "sortByTitle", $data['sort_by_title'], true);
		$this->_checkOption($match, "sortByType", $data['sort_by_type'], true);
		$this->_checkOption($match, "sortByCreationDate", $data['sort_by_date'], 'created');
		$this->_checkOption($match, "sortByModifDate", $data['sort_by_date'], 'modified');

		// ACL options
		$this->_checkOption($match, "ACLshowPage", $data['show_pgnoread'], true);
		$this->_checkOption($match, "ACLhideNs", $data['hide_nsnotr'], true);

		// Remove other options and warn about
		for ($found; preg_match("/ (-.*)/", $match, $found); ) {
			msg(sprintf($this->getLang('unknownoption'), htmlspecialchars($found[1])), -1);
			$match = str_replace($found[0], '', $match);
		}
		
		// Looking for the wanted namespace. Now, only the wanted namespace remains in $match. Then clean the namespace id
		$ns = trim($match);
		if ((boolean)$this->getConf('nswildcards')) {
			global $ID;
			$parsepagetemplate_data = array('id' => $ID, 'tpl' => $ns, 'doreplace' => true);
			$ns = parsePageTemplate($parsepagetemplate_data);
		}
		if ($ns == '') $ns = '.'; // If there is nothing, we take the current namespace
		global $ID;
		if ($ns[0] == '.') $ns = getNS($ID).':'.$ns; // If it start with a '.', it is a relative path
		$split = explode(':', $ns);
		for ($i = 0; $i < count($split); $i++) {
			if ($split[$i] === '' || $split[$i] === '.') {
				array_splice($split, $i, 1);
				$i--;
			} else if ($split[$i] == '..') {
				if ($i != 0) {
					array_splice($split, $i-1, 2);
					$i -= 2;
				} else break;
			}
		}
		if ($split[0] == '..') {
			// Path would be outside the 'pages' directory
			msg($this->getLang('outofpages'), -1);
			return false;
		}
		$data['ns'] = implode(':', $split);
		return $data;
	}

	/**************************************************************************************/
	/************************************ Tree walking ************************************/

		/* Utility function to check is a given page/namespace ($item) is excluded
		 * based on the relevant list of blacklisting/whitelisting regexes $arrayRegex
		 * ( array of array('regex'=>the_regex,'neg'=>false/true) ). The exclusion
		 * is based on item title, full id or name ($exclutype).
		 */
	function _isExcluded ($item, $exclutype, $arrayRegex) {
		if ($arrayRegex === true) return true;
		global $conf;
		if ((strlen($conf['hidepages']) != 0) && preg_match('/'.$conf['hidepages'].'/i', $item['id'])) return true;
		foreach($arrayRegex as $regex) {
			if (!is_array($regex)) // temporary, for transitioning to v2021-07-21
				$regex = array('regex' => $regex, 'neg' => false);
			$match = preg_match('/'.$regex['regex'].(($exclutype=='title')?'/':'/i'), $item[$exclutype]);
			if ($regex['neg']) {
				if ($match === 0)
					return true;
			} else {
				if ($match === 1)
					return true;
			}
		}
		return false;
	}

	function _getStartPage ($index_priority, $parid, $parpath, $name, $force) {
		$exists = false;
		if ($parid != '') $parid .= ':';
		global $conf;
		$index_path_map = array( CATLIST_INDEX_START => $parpath.'/'.$name.'/'.$conf['start'].'.txt',
		                         CATLIST_INDEX_OUTSIDE => $parpath.'/'.$name.'.txt',
		                         CATLIST_INDEX_INSIDE => $parpath.'/'.$name.'/'.$name.'.txt' );
		$index_id_map = array( CATLIST_INDEX_START => $parid .$name.':'.$conf['start'],
		                       CATLIST_INDEX_OUTSIDE => $parid .$name,
		                       CATLIST_INDEX_INSIDE => $parid .$name.':'.$name );
		foreach ($index_priority as $index_type) {
			if (is_file($index_path_map[$index_type])) {
				$exists = true;
				return array(true, $index_id_map[$index_type], $index_path_map[$index_type]);
			}
		}
		if ($force && isset($index_priority[0])) 
			return array(false, $index_id_map[0], null);
		else
			return array(false, false, null);
		// returns ($index_exists, $index_id, $index_filepath)
	}

	function _getMetadata ($id, $filepath) {
		$meta = p_get_metadata($id, $key='', $render=METADATA_RENDER_USING_SIMPLE_CACHE);
		if (!isset($meta['date']['modified']))
			$meta['date']['modified'] = @filemtime($filepath);
		if (!isset($meta['contributor']))
			$meta['contributor'] = $meta['creator'];
		return $meta;
	}

		/* Entry function for tree walking, called in render()
		 *
		 * $data contains the various options initialized and parsed in handle(), and will be passed along
		 * the tree walking. Moreover, $data['tree'] is filled by the pages found by _walk_recurse(), and
		 * will contain the full tree, minus the excluded pages (however, permissions are only evaluated at
		 * rendering time) and up to the max depth. _walk() prepares and start the tree walking.
		 */
	function _walk (&$data) {
		global $conf;

			// Get the directory path from namespace id, and check if it exists
		$ns = $data['ns'];
		if($ns == '%%CURRENT_NAMESPACE%%')
			$ns = getNS(cleanID(getID())); // update namespace to the one currently displayed
		$path = str_replace(':', '/', $ns);
		$path = $conf['datadir'].'/'.utf8_encodeFN($path);
		if (!is_dir($path)) {
			if ($data['show_notfound_error'])
				msg(sprintf($this->getLang('dontexist'), $ns), -1);
			return false;
		}

			// Info on the main page (the "header" page)
		$main = array( 'id' => $ns.':',
		               'exist' => false,
		               'title' => NULL );
		resolve_pageid('', $main['id'], $main['exist']);
		if ($data['headTitle'] !== NULL) 
			$main['title'] = $data['headTitle'];
		else {
			if ($data['useheading'] && $main['exist']) 
				$main['title'] = p_get_first_heading($main['id'], true);
			if (is_null($main['title'])) {
				$ex = explode(':', $ns);
				$main['title'] = end($ex);
			}
		}
		$data['main'] = $main;

			// Preparing other stuff
		if (!isset($data['sort_collator']) || $data['sort_collator'] == "")
			$data['sort_collator'] = NULL;
		else {
			$locale = $data['sort_collator'];
			$coll = collator_create($locale);
			if (!isset($coll)) {
				msg("catlist sorting: can't create Collator object: ".intl_get_error_message(), -1);
				$data['sort_collator'] = NULL;
			} else {
				$coll->setAttribute(Collator::CASE_FIRST, Collator::UPPER_FIRST);
				$coll->setAttribute(Collator::NUMERIC_COLLATION, Collator::ON);
				$data['sort_collator'] = $coll;
			}
		}

			// Start the recursion
		if (!isset($data['excludelist'])) // temporary, for transitioning to v2021-07-21
			$data['excludelist'] = array();
		$data['tree'] = array();
		$data['index_pages'] = array( $main['id'] );
		$this->_walk_recurse($data, $path, $ns, "", false, false, 1/*root depth is 1*/, $data['tree']/*root*/);
		return true;
	}

		/* Recursive function for tree walking.
		 * 
		 * Scans the current namespace by looking directly at the filesystem directory
		 * for .txt files (pages) and sub-directories (namespaces). Excludes pages/namespaces
		 * based on the various exclusion options. The current/local directory path, namesapce
		 * ID and relative namespace ID are respectively $path, $ns and $relns.
		 * $data is described above. $data['tree'] is not modified directly, but only through
		 * $_TREE which is the *local* tree view (ie. a reference of a $data['tree'] node) and
		 * where found children are added. Optionally sorts this list of children.
		 * The local tree depth is $depth. $excluPages, $excluNS are flags indicates if the
		 * sub-pages/namespaces should be excluded. Fills $data['index_pages'] with all
		 * namespace IDs where an index has been found.
		 */
	function _walk_recurse (&$data, $path, $ns, $relns, $excluPages, $excluNS, $depth, &$_TREE) {
		$scanDirs = @scandir($path, SCANDIR_SORT_NONE);
		if ($scanDirs === false) {
			msg("catlist: can't open directory of namespace ".$ns, -1);
			return;
		}
		foreach ($scanDirs as $file) {
			if ($file[0] == '.' || $file[0] == '_') continue;
			$name = utf8_decodeFN(str_replace('.txt', '', $file));
			$id = ($ns == '') ? $name : $ns.':'.$name;
			$rel_id = ($relns == '') ? $name : $relns.':'.$name;
			$item = array('id' => $id, 'rel_id' => $rel_id, 'name' => $name, 'title' => NULL);

				// ID exclusion
			if (in_array($rel_id, $data['excludelist'])) continue;

				// It's a namespace
			if (is_dir($path.'/'.$file)) {
					// Index page of the namespace
				list($index_exists, $index_id, $index_filepath) = $this->_getStartPage($data['index_priority'], $ns, $path, $name, ($data['nsLinks']==CATLIST_NSLINK_FORCE));
				if ($index_exists)
					$data['index_pages'][] = $index_id;
					// Exclusion
				if ($excluNS) continue;
				if ($this->_isExcluded($item, $data['exclutype'], $data['excluns'])) continue;
					// Namespace
				if ($index_exists) {
					$item['metadata'] = $this->_getMetadata($index_id, $index_filepath);
					if ($data['nsuseheading'] && isset($item['metadata']['title']))
						$item['title'] = $item['metadata']['title'];
				}
				if (is_null($item['title']))
					$item['title'] = $name;
				$item['linkdisp'] = ($index_exists && ($data['nsLinks']==CATLIST_NSLINK_AUTO)) || ($data['nsLinks']==CATLIST_NSLINK_FORCE);
				$item['linkid'] = $index_id;
					// Button
				$item['buttonid'] = $data['createPageButtonSubs'] ? $id.':' : NULL;
					// Recursion if wanted
				$item['_'] = array();
				$okdepth = ($depth < $data['maxdepth']) || ($data['maxdepth'] == 0);
				$exclude_content = $this->_isExcluded($item, $data['exclutype'], $data['exclunsall'])
				                   || in_array($rel_id.':', $data['excludelist']);
				if (!$exclude_content && $okdepth) {
					$exclunspages = $this->_isExcluded($item, $data['exclutype'], $data['exclunspages']);
					$exclunsns = $this->_isExcluded($item, $data['exclutype'], $data['exclunsns']);
					$this->_walk_recurse($data, $path.'/'.$file, $id, $rel_id, $exclunspages, $exclunsns, $depth+1, $item['_']);
				}
					// Tree
				$_TREE[] = $item;
			} else

				// It's a page
			if (!$excluPages) {
				if (substr($file, -4) != ".txt") continue;
					// Page title
				$item['metadata'] = $this->_getMetadata($id, $file);
				if ($data['useheading'] && isset($item['metadata']['title'])) {
					$item['title'] = $item['metadata']['title'];
				}
				if (is_null($item['title']))
					$item['title'] = $name;
					// Exclusion
				if ($this->_isExcluded($item, $data['exclutype'], $data['exclupage'])) continue;
					// Tree
				$_TREE[] = $item;
			}

				// Sorting
			if ($data['sort_order'] != CATLIST_SORT_NONE) {
				usort($_TREE, function ($a, $b) use ($data) {
					$a_is_folder = isset($a['_']);
					$b_is_folder = isset($b['_']);
					// if one or the other is folder, comparison is done
					if ($data['sort_by_type'] && ($a_is_folder xor $b_is_folder )) 
						return $b_is_folder;
					// else, compare date or name
					if ($data['sort_by_date'] === false) {
						// by name
						$a_title = ($data['sort_by_title'] ? $a['title'] : $a['name']);
						$b_title = ($data['sort_by_title'] ? $b['title'] : $b['name']);
						if (!is_null($data['sort_collator'])) 
							$r = $data['sort_collator']->compare($a_title, $b_title);
						else
							$r = strnatcasecmp($a_title, $b_title);
					} else {
						// by date
						$field = $data['sort_by_date'];
						$a_date = (isset($a['metadata']['date'][$field]) ? $a['metadata']['date'][$field] : 0);
						$b_date = (isset($b['metadata']['date'][$field]) ? $b['metadata']['date'][$field] : 0);
						$r = $a_date <=> $b_date;
					}
					if ($data['sort_order'] == CATLIST_SORT_DESCENDING)
						$r *= -1;
					return $r;
				});
			}
		}
	}
	
	/***********************************************************************************/
	/************************************ Rendering ************************************/

	function render ($mode, Doku_Renderer $renderer, $data) {
		if (!is_array($data)) return false;
		$ns = $data['ns'];

			// Disabling cache
		if ($data['nocache']) 
			$renderer->nocache();

			// Walk namespace tree
		$r = $this->_walk($data);
		if ($r == false) return false;

			// Write params for the add page button
		global $conf;
		if (!isset($data['pagename_sanitize'])) // temporary, for transitioning to v2022-06-25
			$data['pagename_sanitize'] = true;
		$renderer->doc .= '<script type="text/javascript"> catlist_baseurl = "'.DOKU_URL.'"; catlist_basescript = "'.DOKU_SCRIPT.'"; catlist_useslash = '.$conf['useslash'].'; catlist_userewrite = '.$conf['userewrite'].'; catlist_sepchar = "'.$conf['sepchar'].'"; catlist_deaccent = '.$conf['deaccent'].'; catlist_pagename_sanitize = '.$data['pagename_sanitize'].'; </script>';

			// Display headline
		if ($data['head']) {
			$html_tag_small = ($data['nsInBold']) ? 'strong' : 'span';
			$html_tag = ($data['smallHead']) ? $html_tag_small : $data['hn'];
			$renderer->doc .= '<'.$html_tag.' class="catlist-head">';
			$main = $data['main'];
			if (($main['exist'] && $data['linkStartHead'] && !($data['nsLinks']==CATLIST_NSLINK_NONE)) || ($data['nsLinks']==CATLIST_NSLINK_FORCE)) 
				$renderer->internallink(':'.$main['id'], $main['title']);
			else 
				$renderer->doc .= htmlspecialchars($main['title']);
			$renderer->doc .= '</'.$html_tag.'>';
		}
		
			// Recurse and display
		$global_ul_attr = "";
		if ($data['columns'] != 0) { 
			$global_ul_attr = 'column-count: '.$data['columns'].';';
			$global_ul_attr = 'style="-webkit-'.$global_ul_attr.' -moz-'.$global_ul_attr.' '.$global_ul_attr.'" ';
			$global_ul_attr .= 'class="catlist_columns catlist-nslist" ';
		} else {
			$global_ul_attr = 'class="catlist-nslist" ';
		}
		if ($data['displayType'] == CATLIST_DISPLAY_LIST) $renderer->doc .= '<ul '.$global_ul_attr.'>';
		$this->_recurse($renderer, $data, $data['tree']);
		$perm_create = $this->_cached_quickaclcheck($ns.':*') >= AUTH_CREATE;
		$ns_button = ($ns == '') ? '' : $ns.':';
		if ($data['createPageButtonNs'] && $perm_create) $this->_displayAddPageButton($renderer, $ns_button, $data['displayType']);
		if ($data['displayType'] == CATLIST_DISPLAY_LIST) $renderer->doc .= '</ul>';
		
		return true;
	}
	
		/* Just cache the calls to auth_quickaclcheck, mainly for _any_child_perms */
	function _cached_quickaclcheck($id) {
		static $cache = array();
		if (!isset($cache[$id]))
			$cache[$id] = auth_quickaclcheck($id);
		return $cache[$id];
	}

		/* Walk the tree to see if any page/namespace below this has read access access, for show_leading_ns option */
	function _any_child_perms ($data, $_TREE) {
		foreach ($_TREE as $item) {
			if (isset($item['_'])) {
				$perms = $this->_cached_quickaclcheck($item['id'].':*');
				if ($perms >= AUTH_READ || $this->_any_child_perms($data, $item['_']))
					return true;
			} else {
				$perms = $this->_cached_quickaclcheck($item['id']);
				if ($perms >= AUTH_READ)
					return true;
			}
		}
		return false;
	}

	function _recurse (&$renderer, $data, $_TREE) {
		foreach ($_TREE as $item) {
			if (isset($item['_'])) {
				// It's a namespace
				$perms = $this->_cached_quickaclcheck($item['id'].':*');
				$perms_exemption = $data['show_perms'];
				// If we actually care about not showing the namespace because of permissions :
				if ($perms < AUTH_READ && !$perms_exemption) {
					// If show_leading_ns activated, walk the tree below this, see if any page/namespace below this has access
					if ($data['show_leading_ns'] && $this->_any_child_perms($data, $item['_'])) {
						$perms_exemption = true;
					} else {
						if ($data['hide_nsnotr']) continue;
						if ($data['show_pgnoread']) 
							$perms_exemption = true; // Add exception if show_pgnoread enabled, but hide_nsnotr prevails
					}
				}
				$linkdisp = $item['linkdisp'] && ($perms >= AUTH_READ);
				$item['buttonid'] = ($perms >= AUTH_CREATE) ? $item['buttonid'] : NULL;
				$this->_displayNSBegin($renderer, $data, $item['title'], $linkdisp, $item['linkid'], ($data['show_perms'] ? $perms : NULL));
				if ($perms >= AUTH_READ || $perms_exemption) 
					$this->_recurse($renderer, $data, $item['_']);
				$this->_displayNSEnd($renderer, $data['displayType'], $item['buttonid']);
			} else { 
				// It's a page
				$perms = $this->_cached_quickaclcheck($item['id']);
				if ($perms < AUTH_READ && !$data['show_perms'] && !$data['show_pgnoread']) 
					continue;
				if ($data['hide_index'] && in_array($item['id'], $data['index_pages'])) 
					continue;
				$displayLink = $perms >= AUTH_READ || $data['show_perms'];
				$this->_displayPage($renderer, $item, $data['displayType'], ($data['show_perms'] ? $perms : NULL), $displayLink);
			}
		}
	}

	function _displayNSBegin (&$renderer, $data, $title, $displayLink, $idLink, $perms) {
		if ($data['displayType'] == CATLIST_DISPLAY_LIST) {
			$warper_ns = ($data['nsInBold']) ? 'strong' : 'span';
			$renderer->doc .= '<li class="catlist-ns"><'.$warper_ns.' class="li catlist-nshead">';
			if ($displayLink) $renderer->internallink($idLink, $title);
			else $renderer->doc .= htmlspecialchars($title);
			if ($perms !== NULL) $renderer->doc .= ' [ns, perm='.$perms.']';
			$renderer->doc .= '</'.$warper_ns.'>';
			$renderer->doc .= '<ul class="catlist-nslist">';
		} 
		else if ($data['displayType'] == CATLIST_DISPLAY_LINE) {
			if ($data['nsInBold']) $renderer->doc .= '<strong>';
			if ($displayLink) $renderer->internallink($idLink, $title);
			else $renderer->doc .= htmlspecialchars($title);
			if ($data['nsInBold']) $renderer->doc .= '</strong>';
			$renderer->doc .= '[ ';
		}
	}
	
	function _displayNSEnd (&$renderer, $displayType, $nsAddButton) {
		if (!is_null($nsAddButton)) $this->_displayAddPageButton($renderer, $nsAddButton, $displayType);
		if ($displayType == CATLIST_DISPLAY_LIST) $renderer->doc .= '</ul></li>';
		else if ($displayType == CATLIST_DISPLAY_LINE) $renderer->doc .= '] ';
	}
	
	function _displayPage (&$renderer, $item, $displayType, $perms, $displayLink) {
		if ($displayType == CATLIST_DISPLAY_LIST) {
			$renderer->doc .= '<li class="catlist-page">';
			if ($displayLink) $renderer->internallink(':'.$item['id'], $item['title']);
			else $renderer->doc .= htmlspecialchars($item['title']);
			if ($perms !== NULL) $renderer->doc .= ' [page, perm='.$perms.']';
			$renderer->doc .= '</li>';
		} else if ($displayType == CATLIST_DISPLAY_LINE) {
			$renderer->internallink(':'.$item['id'], $item['title']);
			$renderer->doc .= ' ';
		}
	}
	
	function _displayAddPageButton (&$renderer, $ns, $displayType) {
		$html = ($displayType == CATLIST_DISPLAY_LIST) ? 'li' : 'span';
		$renderer->doc .= '<'.$html.' class="catlist_addpage"><button class="button" onclick="catlist_button_add_page(this,\''.$ns.'\')">'.$this->getLang('addpage').'</button></'.$html.'>';
	}

}
