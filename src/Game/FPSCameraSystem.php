<?php

namespace App\Game;

use GL\Math\GLM;
use GL\Math\Quat;
use GL\Math\Vec3;
use VISU\ECS\EntitiesInterface;
use VISU\Geo\AABB;
use VISU\Graphics\Camera;
use VISU\Graphics\CameraProjectionMode;
use VISU\OS\CursorMode;
use VISU\OS\Input;
use VISU\OS\Key;
use VISU\Signal\Dispatcher;
use VISU\Signals\Input\CursorPosSignal;
use VISU\System\VISUCameraSystem;

class FPSCameraSystem extends VISUCameraSystem
{
    public const EYE_HEIGHT = 1.35;
    public const CROUCH_EYE_HEIGHT = PlayerMovement::CROUCH_EYE_HEIGHT;
    public const MOUSE_SENSITIVITY = 0.1;
    public const MAX_PITCH = 89.0;

    /**
     * Head bob: phase advance per unit of horizontal speed and the amplitude
     * of the vertical bob at full run speed.
     */
    private const BOB_FREQUENCY = 1.8;
    private const BOB_AMP = 0.04;

    /**
     * Fixed timestep derived from the game loop tick rate.
     */
    private float $dt;

    private float $yaw = 0.0;
    private float $pitch = 0.0;
    private float $bobPhase = 0.0;
    private bool $enabled = false;

    private PlayerMovement $movement;

    public function __construct(Input $input, Dispatcher $dispatcher, float $tickRate = 60.0)
    {
        parent::__construct($input, $dispatcher);

        $this->dt = 1.0 / max($tickRate, 1.0);
        $this->movement = new PlayerMovement;
        $this->visuCameraMode = self::CAMERA_MODE_GAME;
    }

    /**
     * Enables or disables the player controller.
     * While disabled, input queues are still drained so no stale
     * mouse offsets are applied when the game resumes.
     */
    public function setEnabled(bool $enabled) : void
    {
        $this->enabled = $enabled;
    }

    /**
     * Sets the AABB colliders the player resolves against.
     *
     * @param array<AABB> $colliders
     */
    public function setColliders(array $colliders) : void
    {
        $this->movement->setColliders($colliders);
    }

    /**
     * Switches the player to triangle-accurate collision against the given
     * world. Passing null reverts to the AABB collider path.
     */
    public function setTriangleWorld(?TriangleCollisionWorld $world) : void
    {
        $this->movement->setTriangleWorld($world);
    }

    /**
     * The player's current feet position.
     */
    public function getPlayerPosition() : Vec3
    {
        return new Vec3($this->movement->x, $this->movement->y, $this->movement->z);
    }

    /**
     * Drains any pending input signals without applying them.
     */
    public function drainInput() : void
    {
        while ($this->cursorQueue->shift()) {}
        while ($this->scrollQueue->shift()) {}
    }

    /**
     * Spawns the FPS player camera entity.
     *
     * @param Vec3 $feetPosition position of the player's feet
     */
    public function spawnFPSPlayer(EntitiesInterface $entities, Vec3 $feetPosition) : int
    {
        $entities->registerComponent(Camera::class);

        $cameraEntity = $entities->create();
        $camera = $entities->attach($cameraEntity, new Camera(CameraProjectionMode::perspective));
        // Mat4::perspective in the installed GLM expects radians, but VISU's
        // Camera passes the fieldOfView through verbatim. Store radians here.
        $camera->fieldOfView = GLM::radians(75.0);
        $camera->transform->setPosition(new Vec3($feetPosition->x, $feetPosition->y + self::EYE_HEIGHT, $feetPosition->z));
        $camera->transform->setOrientation(new Quat);

        $this->yaw = 0.0;
        $this->pitch = 0.0;
        $this->bobPhase = 0.0;
        $this->movement->teleport($feetPosition->x, $feetPosition->y, $feetPosition->z);

        $this->setActiveCameraEntity($cameraEntity);

        return $cameraEntity;
    }

    /**
     * Registers the system components and input signal queues.
     */
    public function register(EntitiesInterface $entities) : void
    {
        $entities->registerComponent(Camera::class);

        $this->cursorQueue = $this->dispatcher->createSignalQueue(Input::EVENT_CURSOR);
        $this->scrollQueue = $this->dispatcher->createSignalQueue(Input::EVENT_SCROLL);
    }

    /**
     * Updates the player controller.
     */
    public function update(EntitiesInterface $entities) : void
    {
        $camera = $this->getActiveCamera($entities);
        $camera->finalizeFrame();

        while ($cursorSignal = $this->cursorQueue->shift()) {
            $this->handleCursor($cursorSignal);
        }

        while ($this->scrollQueue->shift()) {}

        if (!$this->enabled || !$this->input->isContextUnclaimed()) {
            return;
        }

        $this->updatePlayer($entities, $camera);
    }

    /**
     * Applies mouse look offsets to yaw/pitch.
     */
    private function handleCursor(CursorPosSignal $signal) : void
    {
        if (!$this->enabled) {
            return;
        }

        if ($this->input->getCursorMode() !== CursorMode::DISABLED) {
            return;
        }

        $this->yaw -= $signal->offsetX * self::MOUSE_SENSITIVITY;
        $this->pitch -= $signal->offsetY * self::MOUSE_SENSITIVITY;

        $this->pitch = max(-self::MAX_PITCH, min(self::MAX_PITCH, $this->pitch));
    }

    /**
     * Moves the player: WASD, walk, jump and GoldSrc physics.
     */
    private function updatePlayer(EntitiesInterface $entities, Camera $camera) : void
    {
        $input = $this->input;
        $transform = $camera->transform;

        $forward = $transform->dirForward();
        $forward->y = 0.0;
        $forward->normalize();

        $right = $transform->dirRight();
        $right->y = 0.0;
        $right->normalize();

        $wishX = 0.0;
        $wishZ = 0.0;
        if ($input->isKeyPressed(Key::W)) {
            $wishX += $forward->x;
            $wishZ += $forward->z;
        }
        if ($input->isKeyPressed(Key::S)) {
            $wishX -= $forward->x;
            $wishZ -= $forward->z;
        }
        if ($input->isKeyPressed(Key::A)) {
            $wishX -= $right->x;
            $wishZ -= $right->z;
        }
        if ($input->isKeyPressed(Key::D)) {
            $wishX += $right->x;
            $wishZ += $right->z;
        }

        $walking = $input->isKeyPressed(Key::LEFT_SHIFT) || $input->isKeyPressed(Key::RIGHT_SHIFT);
        $crouching = $input->isKeyPressed(Key::LEFT_CONTROL)
            || $input->isKeyPressed(Key::RIGHT_CONTROL)
            || $input->isKeyPressed(Key::C);
        $this->movement->setCrouching($crouching);

        // GoldSrc ducks to SPEED_CROUCH (150u/s, the same as walk speed)
        $wishSpeed = $this->movement->isCrouching()
            ? PlayerMovement::WALK_SPEED
            : ($walking ? PlayerMovement::WALK_SPEED : PlayerMovement::RUN_SPEED);

        if ($input->isKeyPressed(Key::SPACE) && $this->movement->onGround) {
            $this->movement->jump();
        }

        $this->movement->update($wishX, $wishZ, $wishSpeed, $this->dt);

        // head bob: only while running on the ground, amplitude scales with speed
        $speed = sqrt(
            $this->movement->velX * $this->movement->velX
            + $this->movement->velZ * $this->movement->velZ
        );

        $bob = 0.0;
        if ($this->movement->onGround && $speed > 0.1) {
            $this->bobPhase += $speed * self::BOB_FREQUENCY * $this->dt;
            $bob = min($speed / PlayerMovement::RUN_SPEED, 1.0);
        }

        $bobY = sin($this->bobPhase * 2.0) * self::BOB_AMP * $bob;
        $bobX = sin($this->bobPhase) * self::BOB_AMP * 0.35 * $bob;

        $eyeHeight = $this->movement->isCrouching() ? self::CROUCH_EYE_HEIGHT : self::EYE_HEIGHT;

        $transform->setPosition(new Vec3(
            $this->movement->x + $bobX,
            $this->movement->y + $eyeHeight + $bobY,
            $this->movement->z,
        ));

        // orientation: yaw around Y, pitch around X
        $quatYaw = new Quat;
        $quatYaw->rotate(GLM::radians($this->yaw), new Vec3(0, 1, 0));

        $quatPitch = new Quat;
        $quatPitch->rotate(GLM::radians($this->pitch), new Vec3(1, 0, 0));

        $transform->setOrientation(Quat::multiply($quatYaw, $quatPitch));
        $transform->markDirty();
    }
}
