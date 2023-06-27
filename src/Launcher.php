<?php

namespace PHPLife {
    if (!defined('DS')) {
        define('DS', DIRECTORY_SEPARATOR);
    }
    /**
     *---------------------------------------------------------------
     * Autoloader / Compser
     *---------------------------------------------------------------
     *
     * We need to access our dependencies & autloader..
     */
    require __DIR__ . DS . '..' . DS . 'vendor' . DS . 'autoload.php';

    use PGF\{
        Window,
        Common\FrameLimiter,

        Shader\Shader,
        Shader\Program,

        Drawing\Drawer2D,

        Texture\Texture
    };

    $window = new Window;

    // configure the window
    $window->setHint(GLFW_CONTEXT_VERSION_MAJOR, 3);
    $window->setHint(GLFW_CONTEXT_VERSION_MINOR, 3);
    $window->setHint(GLFW_OPENGL_PROFILE, GLFW_OPENGL_CORE_PROFILE);
    $window->setHint(GLFW_OPENGL_FORWARD_COMPAT, GL_TRUE);

    // open it
    $window->open('2D Ball');

    // enable vsync
    $window->setSwapInterval(1);

    // create frame limiter
    $fl = new FrameLimiter();

    /**
     * Create drawer
     */
    $drawer = new Drawer2D($window);

    /**
     * Main loop
     */
    while (!$window->shouldClose())
    {
        // swap
        $window->swapBuffers();

        $fl->wait();
    }

}