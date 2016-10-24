<?php

$meta['startpages_outside'] = array('onoff');
$meta['default_sort'] = array('multichoice', '_choices' => array('none','ascending','descending'));
$meta['index_priority'] = array('regex', '_pattern' => "^((start|inside|outside),)*(start|inside|outside)?$")