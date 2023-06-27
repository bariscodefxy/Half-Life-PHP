<?php

namespace PHPLife {
    if (!defined('DS')) {
        define('DS', DIRECTORY_SEPARATOR);
    }
    /**
     *---------------------------------------------------------------
     * Autoloader / Composer
     *---------------------------------------------------------------
     *
     * We need to access our dependencies & autoloader..
     */
    $container = require __DIR__ . DS . '..' . DS . 'bootstrap.php';

    set_time_limit(0);

    /**
     *---------------------------------------------------------------
     * Initialize Game
     *---------------------------------------------------------------
     *
     * Starts GLFW, load the game entry point and start the game loop.
     */
    glfwInit();

    // load & start the game
    $game = $container->get('game');
    $game->start();

    // clean up glfw
    glfwTerminate();
}