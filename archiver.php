<?php
//Kickstart the framework
require_once("vendor/autoload.php");
$f3 = Base::instance();

//Load configuration
$f3->config('config/config.ini');

//Load routes
$f3->config('config/routes.ini');

//Set debug level
$f3->set('DEBUG', $f3->get('debug_level'));

//Set cache directory
$f3->set('CACHE', 'folder=tmp/cache/');

//Set temp dir for compiled templates
$f3->set('TEMP', 'tmp/');

//Autoload classes
$f3->set('AUTOLOAD', 'app/classes/');

//Set template dir
$f3->set('UI', 'app/templates/');

//Allow up to 4GB memory usage
ini_set("memory_limit", "4096M");

//Set default timezone
date_default_timezone_set('America/New_York');

//Run dat code
$f3->run();