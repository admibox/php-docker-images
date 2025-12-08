<?php
if (empty(getenv('XHPROF_ENABLE_PHP_PROXY'))) {
	die();
}

$uri = $_SERVER['DOCUMENT_URI'];

$xhroot = getenv('XHPROF_HTML_DIR') ?: '/opt/xhprof/xhprof_html';
$path = getenv('XHPROF_HTML_PATH') ?: '/xhprof';

$contentTypes = [
	'css' => function($file = false) {
		header("Content-Type: text/css");
    	$file && header("Content-Length: " . filesize($file));
	},
	'js' => function($file) {
		header("Content-Type: text/javascript");
    	$file && header("Content-Length: " . filesize($file));
	},
];

if (strpos($uri, $path) !== 0) {
	die();
}

$uri = substr($uri, strlen($path));
$pi = pathinfo($uri);
$ext = strtolower($pi['extension']);

$lookFor = $xhroot . $uri;

if (in_array($ext, ['js', 'css', 'jpg', 'png']) && file_exists($lookFor)) {
	if (isset($contentTypes[$ext])) {
		$contentTypes[$ext]($lookFor);
	}
	readfile($lookFor);
} else if (in_array($ext, ['php']) && file_exists($lookFor)) {
	include($lookFor);
} else if (is_dir($lookFor) && file_exists($lookFor . '/index.php')) {
	include($lookFor . '/index.php');
}
