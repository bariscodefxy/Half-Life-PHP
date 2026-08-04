<?php 

namespace App;

use Error;
use GL\VectorGraphics\VGContext;

use VISU\Graphics\{RenderTarget, Viewport, Camera, CameraProjectionMode};
use VISU\Graphics\Rendering\RenderContext;
use VISU\Geo\Transform;

use VISU\Quickstart\QuickstartApp;

class Application extends QuickstartApp
{
    /**
     * You do not have to use a camera at all if you don't want to.
     * But for sake of this example we will use one to determine a fixed viewport.
     * This is what you would typically do in a 2D game.
     */
    private Camera $camera;

    private ?Viewport $viewport = null;

    /**
     * A function that is invoked once the app is ready to run.
     * This happens exactly just before the game loop starts.
     * 
     * Here you can prepare your game state, register services, callbacks etc.
     */
    public function ready() : void
    {
        parent::ready();

        // again you don't have to use a camera at all
        // we use one because in this example we don't want to couple 
        // the viewport to the actual window size
        $this->camera = new Camera(CameraProjectionMode::orthographicStaticWorld, new Transform);
        // in this quickstart example we use VG which with a camera 
        // this forces us to flip the viewport in Y direction so that -y is up
        $this->camera->flipViewportY = true;

        // load the inconsolata font to display the current score
        if ($this->vg->createFont('inconsolata', VISU_PATH_FRAMEWORK_RESOURCES_FONT . '/inconsolata/Inconsolata-Regular.ttf') === -1) {
            throw new Error('Inconsolata font could not be loaded.');
        }
    }

    /**
     * Draw the scene. (You most definetly want to use this)
     * 
     * This is called from within the Quickstart render pass where the pipeline is already
     * prepared, a VG frame is also already started.
     */
    public function draw(RenderContext $context, RenderTarget $renderTarget) : void
    {
        // clear the screen
        $renderTarget->framebuffer()->clear(GL_COLOR_BUFFER_BIT | GL_STENCIL_BUFFER_BIT);

        // calculate the viewport
        $this->viewport = $this->camera->getViewport($renderTarget);
        
        // transform the VG space by the camera view
        $this->camera->transformVGSpace($this->viewport, $this->vg);
    }

    /**
     * Update the games state
     * This method might be called multiple times per frame, or not at all if
     * the frame rate is very high.
     * 
     * The update method should step the game forward in time, this is the place
     * where you would update the position of your game objects, check for collisions
     * and so on. 
     */
    public function update() : void
    {
        parent::update();
    }
}