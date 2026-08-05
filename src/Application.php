<?php

namespace App;

use App\Game\CrossfireMap;
use App\Game\FPSCameraSystem;
use App\Game\GameMap;
use App\Game\Hud;
use App\Game\MapCollisionDebugView;
use App\Game\SkyDome;
use App\Menu\MainMenu;
use VISU\Component\VISULowPoly\DynamicRenderableModel;
use VISU\Graphics\Camera;
use VISU\Graphics\RenderTarget;
use VISU\Graphics\Rendering\RenderContext;
use VISU\Graphics\Rendering\Renderer\Debug3DRenderer;
use VISU\Graphics\Rendering\Resource\RenderTargetResource;
use VISU\Graphics\ShaderProgram;
use VISU\Graphics\ShaderStage;
use VISU\OS\CursorMode;
use VISU\OS\Input;
use VISU\OS\Key;
use VISU\Quickstart\QuickstartApp;
use VISU\Signals\Input\KeySignal;
use VISU\System\VISULowPoly\LPModelCollection;
use VISU\System\VISULowPoly\LPRenderingSystem;

class Application extends QuickstartApp
{
    private const SCREEN_MENU = 'menu';
    private const SCREEN_GAME = 'game';

    private MainMenu $menu;
    private Hud $hud;
    private CrossfireMap $crossfireMap;
    private SkyDome $skyDome;
    private GameMap $currentMap;
    private FPSCameraSystem $fpsCamera;
    private LPRenderingSystem $renderingSystem;
    private MapCollisionDebugView $collisionDebugView;

    private string $screen = self::SCREEN_MENU;

    /**
     * A function that is invoked once the app is ready to run.
     * This happens exactly just before the game loop starts.
     *
     * Here you can prepare your game state, register services, callbacks etc.
     */
    public function ready() : void
    {
        // both maps share one model collection so their models can coexist
        // and the renderer only ever needs to draw the visible ones
        $modelCollection = new LPModelCollection;
        $this->crossfireMap = new CrossfireMap($this->gl, $modelCollection);
        $this->currentMap = $this->crossfireMap;
        $this->skyDome = new SkyDome($this->gl, $modelCollection);

        // Replace the deferred directional-light shader with an unlit variant:
        // the gbuffer albedo is gamma-corrected and shown at full brightness so
        // the map textures read clearly with no sun shading. Registered under
        // the same name before the VISU light pass is lazily loaded, so the
        // LPRenderingSystem picks this program up instead.
        $unlitLighting = new ShaderProgram($this->gl);
        $unlitLighting->attach(new ShaderStage(ShaderStage::VERTEX, <<<'GLSL'
        #version 330 core
        layout (location = 0) in vec3 a_position;
        layout (location = 1) in vec2 a_texture_cords;

        out vec2 v_texture_cords;

        void main()
        {
            v_texture_cords = a_texture_cords;
            gl_Position = vec4(a_position, 1.0);
        }
        GLSL));
        $unlitLighting->attach(new ShaderStage(ShaderStage::FRAGMENT, <<<'GLSL'
        #version 330 core

        in vec2 v_texture_cords;
        out vec4 fragment_color;

        uniform sampler2D gbuffer_albedo;

        const float gamma = 2.2;

        void main()
        {
            // albedo is stored linear; re-encode so the texture shows at its
            // original sRGB brightness with no directional lighting
            vec3 albedo = texture(gbuffer_albedo, v_texture_cords).rgb;
            vec3 fragment = pow(albedo, vec3(1.0 / gamma));
            fragment_color = vec4(fragment, 1.0);
        }
        GLSL));
        $unlitLighting->link();
        $this->shaders->setShaderProgram('visu/lowpoly/deferred_lightpass', $unlitLighting);

        // create and bind the 3D systems before parent::ready() registers them
        $this->renderingSystem = new LPRenderingSystem($this->gl, $this->shaders, $modelCollection);
        // SSAO is very expensive on the integrated GPU; the low-poly test map
        // gets no visible benefit from it
        $this->renderingSystem->enableSSAO = false;
        $this->fpsCamera = new FPSCameraSystem($this->input, $this->dispatcher, $this->options->gameLoopTickRate);

        $this->bindSystems([
            $this->renderingSystem,
            $this->fpsCamera,
        ]);

        // 3D debug line renderer + the F2 map collisions view. F2 toggles the
        // quickstart debug metrics overlay; the collision view is kept in sync
        // so both turn on and off together.
        Debug3DRenderer::setGlobalInstance(new Debug3DRenderer($this->gl));
        $this->collisionDebugView = new MapCollisionDebugView;
        $this->collisionDebugView->setMap($this->currentMap);
        $this->collisionDebugView->setPositionProvider(fn() => $this->fpsCamera->getPlayerPosition());
        $this->dispatcher->register('input.key', function(KeySignal $keySignal) {
            if ($keySignal->key == Key::F2 && $keySignal->action == Input::PRESS) {
                $this->collisionDebugView->toggle();
            }
        });

        parent::ready();

        $this->menu = new MainMenu($this);
        $this->menu->ready();
        $this->menu->onNewGame = function() {
            $this->newGame();
        };

        $this->hud = new Hud($this);
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

        // keep the camera system draining its input queues every tick so no
        // stale mouse offsets accumulate while the game is not running
        if ($this->fpsCamera->getActiveCameraEntity() !== 0) {
            $this->updateSystem($this->fpsCamera);
        }

        if ($this->screen === self::SCREEN_GAME
            && $this->input->hasKeyBeenPressedThisFrame(Key::ESCAPE)) {
            $this->backToMenu();
        }
    }

    /**
     * Prepare / setup additional render passes before the quickstart draw pass.
     */
    public function setupDrawBefore(RenderContext $context, RenderTargetResource $renderTarget) : void
    {
        if ($this->screen !== self::SCREEN_GAME) {
            return;
        }

        // render the 3D scene into the quickstart target before the VG HUD
        // pass draws on top of it
        $this->renderingSystem->setRenderTarget($renderTarget);
        $this->renderSystem($this->fpsCamera, $context);
        $this->renderSystem($this->renderingSystem, $context);

        // map collisions wireframe on top of the scene, tied to F1
        if ($this->collisionDebugView->enabled) {
            $this->collisionDebugView->queue();
            Debug3DRenderer::getGlobalInstance()->attachPass($context->pipeline, $renderTarget);
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
        if ($this->screen === self::SCREEN_GAME) {
            $this->hud->draw($context, $renderTarget);
            return;
        }

        $this->menu->draw($context, $renderTarget);
    }

    /**
     * Starts a fresh game: rebuilds the test map and spawns the FPS player.
     */
    private function newGame() : void
    {
        $entitiesToDestroy = [];
        foreach ($this->entities->view(DynamicRenderableModel::class) as $entity => $unused) {
            $entitiesToDestroy[] = $entity;
        }
        foreach ($this->entities->view(Camera::class) as $entity => $unused) {
            $entitiesToDestroy[] = $entity;
        }
        foreach ($entitiesToDestroy as $entity) {
            $this->entities->destroy($entity);
        }

        $this->currentMap->build($this->entities);
        $this->skyDome->build($this->entities);
        if ($this->currentMap->getTriangleWorld() !== null) {
            $this->fpsCamera->setTriangleWorld($this->currentMap->getTriangleWorld());
        } else {
            $this->fpsCamera->setColliders($this->currentMap->getColliders());
        }
        $this->collisionDebugView->setMap($this->currentMap);
        $this->fpsCamera->drainInput();
        $this->fpsCamera->spawnFPSPlayer($this->entities, $this->currentMap->getSpawnPoint());
        $this->fpsCamera->setEnabled(true);

        $this->input->setCursorMode(CursorMode::DISABLED);
        $this->screen = self::SCREEN_GAME;
    }

    /**
     * Returns to the main menu.
     */
    private function backToMenu() : void
    {
        $this->fpsCamera->setEnabled(false);
        $this->input->setCursorMode(CursorMode::NORMAL);
        $this->screen = self::SCREEN_MENU;
    }
}
