<?php

use VISU\Quickstart;
use VISU\Quickstart\QuickstartOptions;

$container = require __DIR__ . '/../bootstrap.php';

$quickstart = new Quickstart(function(QuickstartOptions $options) use ($container)
{
    $options->appClass = \App\Application::class;
    $options->container = $container;
    $options->windowTitle = $container->getParameter('project.name'); // defined in: /app.ctn
    $options->windowWidth = 1280;
    $options->windowHeight = 720;
    // uncapped FPS (no vsync); gameplay ticks at the monitor's refresh rate
    $options->gameLoopTickRate = 144.0;
    $options->windowVsync = false;
});

$quickstart->run();