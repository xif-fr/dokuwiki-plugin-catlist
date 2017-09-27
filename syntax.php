<?php
/**
 * Plugin catlist : Displays a list of the pages of a namespace recursively
 *
 * @license	  GPL 2 (http://www.gnu.org/licenses/gpl.html)
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

if (!defined('SCANDIR_SORT_NONE')) {
	define('SCANDIR_SORT_NONE', 0);
	define('SCANDIR_SORT_ASCENDING', 0);
	define('SCANDIR_SORT_DESCENDING', 1);
}

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

		$_default_sort_map = array("none" => SCANDIR_SORT_NONE,
		                           "ascending" => SCANDIR_SORT_ASCENDING,
		                           "descending" => SCANDIR_SORT_DESCENDING);
		$_index_priority_map = array("start" => CATLIST_INDEX_START,
		                             "outside" => CATLIST_INDEX_OUTSIDE,
		                             "inside" => CATLIST_INDEX_INSIDE);

		$data = array('displayType' => CATLIST_DISPLAY_LIST, 'nsInBold' => true, 'expand' => 6,
		              'exclupage' => array(), 'excluns' => array(), 'exclunsall' => array(), 'exclunspages' => array(), 'exclunsns' => array(),
		              'exclutype' => 'id', 
		              'createPageButtonNs' => true, 'createPageButtonSubs' => false, 
		              'head' => true, 'headTitle' => NULL, 'smallHead' => false, 'linkStartHead' => true, 'hn' => 'h1',
		              'NsHeadTitle' => true, 'nsLinks' => CATLIST_NSLINK_AUTO,
		              'columns' => 0, 'maxdepth' => 0,
		              'scandir_sort' => $_default_sort_map[$this->getConf('default_sort')],
		              'hide_index' => (boolean)$this->getConf('hide_index'),
		              'index_priority' => array(),
		              'nocache' => (boolean)$this->getConf('nocache') );

		$index_priority = explode(',', $this->getConf('index_priority'));
		foreach ($index_priority as $index_type) {
			if (!array_key_exists($index_type, $_index_priority_map)) {
				msg("catlist: invalid index type in index_priority", -1);
				return FALSE;
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
		
		// Namespace options
		$this->_checkOption($match, "forceLinks", $data['nsLinks'], CATLIST_NSLINK_FORCE); // /!\ Deprecated
		$this->_checkOptionParam($match, "nsLinks", $data['nsLinks'], array( "none" => CATLIST_NSLINK_NONE, 
		                                                                     "auto" => CATLIST_NSLINK_AUTO, 
		                                                                     "force" => CATLIST_NSLINK_FORCE ));
		$this->_checkOption($match, "noNSHeadTitle", $data['NsHeadTitle'], false);
		if ($data['NsHeadTitle'] == false) 
			$data['nsLinks'] = CATLIST_NSLINK_NONE;

		// Exclude options
		for ($found; preg_match("/-(exclu(page|ns|nsall|nspages|nsns)):\"([^\\/\"]+)\" /i", $match, $found); ) {
			$data[strtolower($found[1])][] = $found[3];
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
		$this->_checkOption($match, "sortAscending", $data['scandir_sort'], SCANDIR_SORT_ASCENDING);
		$this->_checkOption($match, "sortDescending", $data['scandir_sort'], SCANDIR_SORT_DESCENDING);
		
		// Remove other options and warn about
		for ($found; preg_match("/ (-.*)/", $match, $found); ) {
			msg(sprintf($this->getLang('unknownoption'), htmlspecialchars($found[1])), -1);
			$match = str_replace($found[0], '', $match);
		}
		
		// Looking for the wanted namespace. Now, only the wanted namespace remains in $match. Then clean the namespace id
		$ns = trim($match);
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
			return FALSE;
		}
		$data['ns'] = implode(':', $split);
		return $data;
	}

	/**************************************************************************************/
	/************************************ Tree walking ************************************/

	function _isExcluded ($item, $exclutype, $arrayRegex) {
		if ($arrayRegex === true) return true;
		global $conf;
		if ((strlen($conf['hidepages']) != 0) && preg_match('/'.$conf['hidepages'].'/i', $item['id'])) return true;
		foreach($arrayRegex as $regex) {
			if (preg_match('/'.$regex.(($exclutype=='title')?'/':'/i'), $item[$exclutype])) {
				return true;
			}
		}
		return false;
	}

	function _getStartPage ($index_priority, $parid, $parpath, $name, $force, &$exists) {
		$exists = FALSE;
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
				$exists = TRUE;
				return $index_id_map[$index_type];
			}
		}
		if ($force && isset($index_priority[0])) 
			return $index_id_map[0];
		else
			return FALSE;
	}

	function _walk (&$data) {
		global $conf;
			// Prepare
		$ns = $data['ns'];
		$path = $conf['savedir'].'/pages/'.str_replace(':', '/', $ns);
		if ($conf['fnencode'] == 'safe')
			$path = SafeFn::encode($path);
		if (!is_dir($path)) {
			msg(sprintf($this->getLang('dontexist'), $ns), -1);
			return FALSE;
		}
			// Main page
		$main = array( 'id' => $ns.':',
		               'exist' => false,
		               'title' => NULL );
		resolve_pageid('', $main['id'], $main['exist']);
		if ($data['headTitle'] !== NULL) 
			$main['title'] = $data['headTitle'];
		else {
			if ($main['exist']) $main['title'] = p_get_first_heading($main['id'], true);
			if (is_null($main['title'])) $main['title'] = end(explode(':', $ns));
		}
		$data['main'] = $main;
			// Recursion
		$data['tree'] = array();
		$data['index_pages'] = array( $main['id'] );
		$this->_walk_recurse($data, $path, $ns, false, false, 1, $data['maxdepth'], $data['tree'], $data['index_pages']);
		return TRUE;
	}

	function _walk_recurse (&$data, $path, $ns, $excluPages, $excluNS, $depth, $maxdepth, &$_TREE) {
		$scanDirs = @scandir($path, $data['scandir_sort']);
		if ($scanDirs === false) {
			msg("catlist: can't open directory of namespace ".$ns, -1);
			return;
		}
		foreach ($scanDirs as $file) {
			if ($file[0] == '.' || $file[0] == '_') continue;
			$name = utf8_decodeFN(str_replace('.txt', '', $file));
			$id = ($ns == '') ? $name : $ns.':'.$name;
			$item = array('id' => $id, 'name'  => $name, 'title' => NULL);
				// It's a namespace
			if (is_dir($path.'/'.$file)) {
					// Index page of the namespace
				$index_exists = FALSE;
				$index_id = $this->_getStartPage($data['index_priority'], $ns, $path, $name, ($data['nsLinks']==CATLIST_NSLINK_FORCE), $index_exists);
				if ($index_exists)
					$data['index_pages'][] = $index_id;
					// Exclusion
				if ($excluNS) continue;
				if ($this->_isExcluded($item, $data['exclutype'], $data['excluns'])) continue;
					// Namespace
				$item['title'] = ($index_exists && $data['NsHeadTitle']) ? p_get_first_heading($index_id, true) : $name;
				$item['linkdisp'] = ($index_exists && ($data['nsLinks']==CATLIST_NSLINK_AUTO)) || ($data['nsLinks']==CATLIST_NSLINK_FORCE);
				$item['linkid'] = $index_id;
					// Button
				$item['buttonid'] = $data['createPageButtonSubs'] ? $id.':' : NULL;
					// Recursion if wanted
				$item['_'] = array();
				$okdepth = ($depth < $maxdepth) || ($maxdepth == 0);
				if (!$this->_isExcluded($item, $data['exclutype'], $data['exclunsall']) && $okdepth) {
					$exclunspages = $this->_isExcluded($item, $data['exclutype'], $data['exclunspages']);
					$exclunsns = $this->_isExcluded($item, $data['exclutype'], $data['exclunsns']);
					$this->_walk_recurse($data, $path.'/'.$file, $id, $exclunspages, $exclunsns, $depth+1, $maxdepth, $item['_']);
				}
					// Tree
				$_TREE[] = $item;
			} else 
				// It's a page
			if (!$excluPages) {
				if (substr($file, -4) != ".txt") continue;
					// Page title
				$title = p_get_first_heading($id, true);
				if (!is_null($title)) $item['title'] = $title;
					// Exclusion
				if ($this->_isExcluded($item, $data['exclutype'], $data['exclupage'])) continue;
					// Tree
				$_TREE[] = $item;
			}
		}
	}
	
	/***********************************************************************************/
	/************************************ Rendering ************************************/

	function render ($mode, Doku_Renderer $renderer, $data) {		
		if (!is_array($data)) return FALSE;
		$ns = $data['ns'];

			// Disabling cache
		if ($data['nocache']) 
			$renderer->nocache();

			// Walk namespace tree
		$this->_walk($data);

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
		$perm_create = auth_quickaclcheck($ns.':*') >= AUTH_CREATE;
		$ns_button = ($ns == '') ? '' : $ns.':';
		if ($data['createPageButtonNs'] && $perm_create) $this->_displayAddPageButton($renderer, $ns_button, $data['displayType']);
		if ($data['displayType'] == CATLIST_DISPLAY_LIST) $renderer->doc .= '</ul>';
		
		return TRUE;
	}
	
	function _recurse (&$renderer, $data, $_TREE) {
		foreach ($_TREE as $item) {
			if (isset($item['_'])) {
				// It's a namespace
				$perms = auth_quickaclcheck($item['id'].':*');
				$item['linkdisp'] = $item['linkdisp'] && ($perms >= AUTH_READ);
				$item['buttonid'] = ($perms >= AUTH_CREATE) ? $item['buttonid'] : NULL;
				$this->_displayNSBegin($renderer, $data, $item['title'], $item['linkdisp'], $item['linkid']);
				if ($perms >= AUTH_READ)
					$this->_recurse($renderer, $data, $item['_']);
				$this->_displayNSEnd($renderer, $data['displayType'], $item['buttonid']);
			} else { 
				// It's a page
				if (auth_quickaclcheck($id) < AUTH_READ) continue;
				if ($data['hide_index'] && in_array($item['id'], $data['index_pages'])) continue;
				$this->_displayPage($renderer, $item, $data['displayType']);
			}
		}
	}

	function _displayNSBegin (&$renderer, $data, $title, $displayLink, $idLink) {
		if ($data['displayType'] == CATLIST_DISPLAY_LIST) {
			$warper_ns = ($data['nsInBold']) ? 'strong' : 'span';
			$renderer->doc .= '<li class="catlist-ns"><'.$warper_ns.' class="li catlist-nshead">';
			if ($displayLink) $renderer->internallink($idLink, $title);
			else $renderer->doc .= htmlspecialchars($title);
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
	
	function _displayPage (&$renderer, $item, $displayType) {
		if ($displayType == CATLIST_DISPLAY_LIST) {
			$renderer->doc .= '<li class="catlist-page">';
			$renderer->internallink(':'.$item['id'], $item['title']);
			$renderer->doc .= '</li>';
		} else if ($displayType == CATLIST_DISPLAY_LINE) {
			$renderer->internallink(':'.$item['id'], $item['title']);
			$renderer->doc .= ' ';
		}
	}
	
	function _displayAddPageButton (&$renderer, $ns, $displayType) {
		global $conf;
		$html = ($displayType == CATLIST_DISPLAY_LIST) ? 'li' : 'span';
		$renderer->doc .= '<'.$html.' class="catlist_addpage"><button class="button" onclick="button_add_page(this, \''.DOKU_URL.'\',\''.DOKU_SCRIPT.'\', \''.$ns.'\', '.$conf['useslash'].', '.$conf['userewrite'].', \''.$conf['sepchar'].'\')">'.$this->getLang('addpage').'</button></'.$html.'>';
	}
	
}
