<?php

$meta['default_sort'] = array('multichoice', '_choices' => array('none','ascending','descending'));
$meta['hide_index'] = array('onoff');
$meta['index_priority'] = array('regex', '_pattern' => "/^((start|inside|outside),)*(start|inside|outside)?$/");
$meta['nocache'] = array('onoff');
$meta['hide_acl_nsnotr'] = array('onoff');
$meta['show_acl'] = array('onoff');
$meta['useheading'] = array('onoff');
$meta['showhead'] = array('onoff');
$meta['show_leading_ns'] = array('onoff');
$meta['nswildcards'] = array('onoff');
$meta['pagename_sanitize'] = array('onoff');
$meta['sort_collator_locale'] = array('string');  // Note : the list of locales could be retreived by https://www.php.net/manual/en/resourcebundle.locales.php
