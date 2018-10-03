<?php

$meta['default_sort'] = array('multichoice', '_choices' => array('none','ascending','descending'));
$meta['hide_index'] = array('onoff');
$meta['index_priority'] = array('regex', '_pattern' => "/^((start|inside|outside),)*(start|inside|outside)?$/");
$meta['nocache'] = array('onoff');
$meta['hide_acl_nsnotr'] = array('onoff');
$meta['show_acl'] = array('onoff');
$meta['useheading'] = array('onoff');