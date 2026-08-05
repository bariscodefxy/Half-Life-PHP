<?php

use ClanCats\Container\Container;

if (!defined('DS')) { define('DS', DIRECTORY_SEPARATOR); }

require __DIR__ . DS . 'vendor' . DS . 'autoload.php';

date_default_timezone_set('Europe/Zurich');

define('VISU_PATH_ROOT', __DIR__);
define('VISU_PATH_RESOURCES', VISU_PATH_ROOT . DS . 'resources');
define('VISU_PATH_APPCONFIG', VISU_PATH_ROOT . DS . 'app');

if (Phar::running()) {
    $basePath = dirname(Phar::running(false));
    define('VISU_PATH_CACHE', $basePath . DS . 'var' . DS . 'cache');
    define('VISU_PATH_STORE', $basePath . DS . 'var' . DS . 'storage');
} else {
    define('VISU_PATH_CACHE', VISU_PATH_ROOT . DS . 'var' . DS . 'cache');
    define('VISU_PATH_STORE', VISU_PATH_ROOT . DS . 'var' . DS . 'storage');
}

@mkdir(VISU_PATH_CACHE, 0777, true);
@mkdir(VISU_PATH_STORE, 0777, true);

define('VISU_PATH_FRAMEWORK_RESOURCES', VISU_PATH_ROOT . DS . 'vendor' . DS . 'phpgl' . DS . 'visu' . DS . 'resources');
define('VISU_PATH_FRAMEWORK_RESOURCES_FONT', VISU_PATH_FRAMEWORK_RESOURCES . DS . 'fonts');
define('VISU_PATH_FRAMEWORK_RESOURCES_SHADER', VISU_PATH_FRAMEWORK_RESOURCES . DS . 'shader');

$container = require __DIR__ . DS . 'vendor' . DS . 'phpgl' . DS . 'visu' . DS . 'bootstrap.php';

return $container;