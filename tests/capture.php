<?php

use GL\Buffer\UByteBuffer;
use VISU\Quickstart;
use VISU\Quickstart\QuickstartOptions;

$container = require __DIR__ . '/../bootstrap.php';

$options = new QuickstartOptions;
$options->appClass = \App\Application::class;
$options->container = $container;
$options->windowTitle = 'capture';
$options->windowWidth = 960;
$options->windowHeight = 540;
$options->windowVsync = false;
$options->windowHeadless = true;
$options->gameLoopTickRate = 144.0;

$quickstart = new Quickstart(function (QuickstartOptions $o) use ($options) {
    $o->appClass = $options->appClass;
    $o->container = $options->container;
    $o->windowTitle = $options->windowTitle;
    $o->windowWidth = $options->windowWidth;
    $o->windowHeight = $options->windowHeight;
    $o->windowVsync = $options->windowVsync;
    $o->windowHeadless = $options->windowHeadless;
    $o->gameLoopTickRate = $options->gameLoopTickRate;
});

$app = $quickstart->app();
$app->ready();

$ref = new ReflectionClass($app);
$toggle = $ref->getMethod('toggleMap');
$toggle->setAccessible(true);
$newGame = $ref->getMethod('newGame');
$newGame->setAccessible(true);

$which = $argv[1] ?? 'crossfire';
if ($which === 'crossfire') {
    $toggle->invoke($app);
}
$newGame->invoke($app);

// orient the camera for a good overview shot
$fps = $ref->getProperty('fpsCamera');
$fps->setAccessible(true);
$camSys = $fps->getValue($app);
$camRef = new ReflectionClass($camSys);
foreach (['yaw', 'pitch'] as $prop) {
    $p = $camRef->getProperty($prop);
    $p->setAccessible(true);
    $p->setValue($camSys, 0.0);
}
$camRef->getProperty('yaw')->setValue($camSys, (float) ($argv[2] ?? 0.0));
$camRef->getProperty('pitch')->setValue($camSys, (float) ($argv[3] ?? 0.0));

// render a few frames
for ($i = 0; $i < 5; $i++) {
    $app->update();
    $app->render(1.0 / 60.0);
}

// read the front buffer (swapBuffers already promoted the frame we rendered)
$w = $options->windowWidth;
$h = $options->windowHeight;
$buffer = new UByteBuffer;
$buffer->fill($w * $h * 4, 0);
glReadBuffer(GL_FRONT);
glReadPixels(0, 0, $w, $h, GL_RGBA, GL_UNSIGNED_BYTE, $buffer);

\GL\Texture\Texture2D::fromBuffer($w, $h, $buffer, 4)->writePNG(__DIR__ . '/../var/capture_' . $which . '.png');
echo "captured $which\n";
