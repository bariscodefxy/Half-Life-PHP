<?php

namespace PHPLife\System;

use GameContainer;
use GL\Math\GLM;
use GL\Math\Quat;
use GL\Math\Vec3;
use PHPLife\Component\GameCameraComponent;
use PHPLife\Component\HeightmapComponent;
use VISU\ECS\EntitiesInterface;
use VISU\Geo\Math;
use VISU\Geo\Transform;
use VISU\Graphics\Camera;
use VISU\OS\Input;
use VISU\OS\InputContextMap;
use VISU\OS\Key;
use VISU\Signal\Dispatcher;
use VISU\Signals\Input\CursorPosSignal;
use VISU\Signals\Input\KeySignal;
use VISU\Signals\Input\ScrollSignal;
use VISU\System\VISUCameraSystem;

class CameraSystem extends VISUCameraSystem
{
    /**
     * Default camera mode is game in the game...
     */
    protected int $visuCameraMode = self::CAMERA_MODE_GAME;

    /**
     * GameContainer
     */
    protected GameContainer $container;

    /**
     * keyboardHandlerId
     */
    private int $keyboardHandlerId;

    /**
     * EntitiesInterface
     */
    private EntitiesInterface $entities;

    /**
     * keys
     */
    private $keys = [];

    /**
     * Constructor
     */
    public function __construct(
        Input                     $input,
        protected InputContextMap $inputContext,
        Dispatcher                $dispatcher,
        GameContainer             $container
    )
    {
        parent::__construct($input, $dispatcher);

        $this->container = $container;
        $this->drawWeapon = false;

        $this->keyboardHandlerId = $this->container->resolveVisuDispatcher()->register('input.key', [$this, 'handleKeyboardEvent']);
    }

    /**
     * Keyboard event handler
     */
    public function handleKeyboardEvent(KeySignal $signal): void
    {
        if ($signal->action == INPUT::PRESS) {
            if ($signal->key === Key::W) {
                $this->keys[Key::W] = true;
            }
            if ($signal->key === Key::S) {
                $this->keys[Key::S] = true;
            }
            if ($signal->key === Key::A) {
                $this->keys[Key::A] = true;
            }
            if ($signal->key === Key::D) {
                $this->keys[Key::D] = true;
            }

            if ($signal->key === Key::SPACE) {
                $this->keys[Key::SPACE] = true;
            }
        }
        if ($signal->action == INPUT::RELEASE) {
            if ($signal->key === Key::W) {
                $this->keys[Key::W] = false;
            }
            if ($signal->key === Key::S) {
                $this->keys[Key::S] = false;
            }
            if ($signal->key === Key::A) {
                $this->keys[Key::A] = false;
            }
            if ($signal->key === Key::D) {
                $this->keys[Key::D] = false;
            }

            if ($signal->key === Key::SPACE) {
                $this->keys[Key::SPACE] = false;
            }
        }
    }

    /**
     * Registers the system, this is where you should register all required components.
     *
     * @return void
     */
    public function register(EntitiesInterface $entities): void
    {
        parent::register($entities);

        $entities->setSingleton(new GameCameraComponent);
    }

    /**
     * Unregisters the system, this is where you can handle any cleanup.
     *
     * @return void
     */
    public function unregister(EntitiesInterface $entities): void
    {
        parent::unregister($entities);

        $entities->removeSingleton(GameCameraComponent::class);
    }

    /**
     * Override this method to handle the cursor position in game mode
     *
     * @param CursorPosSignal $signal
     * @return void
     */
    protected function handleCursorPosVISUGame(EntitiesInterface $entities, CursorPosSignal $signal): void
    {
        $gameCamera = $entities->getSingleton(GameCameraComponent::class);

        $width = 0;
        $height = 0;
        glfwGetWindowSize($this->container->resolveWindowMain()->getGLFWHandle(), $width, $height);

        $sensitivity = 5;
        $x = 0;
        $y = 0;
        if ($signal->x > $width / 2) {
            $x = 0.35 * $sensitivity;
        } else if ($signal->x < $width / 2) {
            $x = -0.35 * $sensitivity;
        }

        if ($signal->y > $height / 2) {
            $y = 0.2 * $sensitivity;
        } else if ($signal->y < $height / 2) {
            $y = -0.2 * $sensitivity;
        }

        // first person controller
        $gameCamera->rotationVelocity->x = $gameCamera->rotationVelocity->x - ($x * $gameCamera->rotationVelocityMouse);
        $gameCamera->rotationVelocity->y = $gameCamera->rotationVelocity->y - ($y * $gameCamera->rotationVelocityMouse);
        glfwSetCursorPos($this->container->resolveWindowMain()->getGLFWHandle(), $width / 2, $height / 2);
        glfwSetInputMode($this->container->resolveWindowMain()->getGLFWHandle(), GLFW_CURSOR, GLFW_CURSOR_HIDDEN);
    }

    /**
     * Override this method to handle the scroll wheel in game mode
     *
     * @param ScrollSignal $signal
     * @return void
     */
    protected function handleScrollVISUGame(EntitiesInterface $entities, ScrollSignal $signal): void
    {
        $gameCamera = $entities->getSingleton(GameCameraComponent::class);

        // $c = $gameCamera->focusRadius / $gameCamera->focusRadiusMax;
        // $newRadius = $gameCamera->focusRadius + ($signal->y * $c * $gameCamera->focusRadiusZoomFactor);
        // $gameCamera->setFocusRadius($newRadius);
    }

    /**
     * Override this method to update the camera in game mode
     *
     * @param EntitiesInterface $entities
     */
    public function updateGameCamera(EntitiesInterface $entities, Camera $camera): void
    {
        $this->entities = $entities;

        // skip until we have a heightmap
        if (!$entities->hasSingleton(HeightmapComponent::class)) {
            return;
        }

        $gameCamera = $entities->getSingleton(GameCameraComponent::class);
        $heightmap = $entities->getSingleton(HeightmapComponent::class);

        // calulate the focus point velocity update
        $speed = 0.3;

        if (@$this->keys[Key::W]) {
            $dir = Math::projectOnPlane($camera->transform->dirForward(), Transform::worldUp());
            $dir->normalize();

            $gameCamera->focusPointVelocity = $gameCamera->focusPointVelocity + ($dir * $speed);
        } elseif (@$this->keys[Key::S]) {
            $dir = Math::projectOnPlane($camera->transform->dirBackward(), Transform::worldUp());
            $dir->normalize();

            $gameCamera->focusPointVelocity = $gameCamera->focusPointVelocity + ($dir * $speed);
        }

        if (@$this->keys[Key::A]) {
            $dir = Math::projectOnPlane($camera->transform->dirLeft(), Transform::worldUp());
            $dir->normalize();

            $gameCamera->focusPointVelocity = $gameCamera->focusPointVelocity + ($dir * $speed);
        } elseif (@$this->keys[Key::D]) {
            $dir = Math::projectOnPlane($camera->transform->dirRight(), Transform::worldUp());
            $dir->normalize();

            $gameCamera->focusPointVelocity = $gameCamera->focusPointVelocity + ($dir * $speed);
        }

        if(@$this->keys[Key::SPACE])
        {
            $gameCamera->focusPoint += new Vec3(0, 50, 0);
        }

        // update the focus point itself
        $gameCamera->focusPoint = $gameCamera->focusPoint + $gameCamera->focusPointVelocity;
        $gameCamera->focusPointVelocity = $gameCamera->focusPointVelocity * $gameCamera->focusPointVelocityDamp;

        // update the cameras rotation in euler angles
        $gameCamera->rotation = $gameCamera->rotation + $gameCamera->rotationVelocity;

        // clamp the rotation on the y axis
        $gameCamera->rotation->y = Math::clamp($gameCamera->rotation->y, -90.0, 90.0);

        // apply dampening to the rotation
        $gameCamera->rotationVelocity = $gameCamera->rotationVelocity * $gameCamera->rotationVelocityDamp;

        // use the eular angles to to rotate the camera in the correct direction
        $camera->transform->position = $gameCamera->focusPoint->copy();
        $camera->transform->orientation = new Quat;
        $camera->transform->orientation->rotate(GLM::radians($gameCamera->rotation->x), Transform::worldUp());
        $camera->transform->orientation->rotate(GLM::radians($gameCamera->rotation->y), Transform::worldRight());
        $camera->transform->moveBackward($gameCamera->focusRadius);

        // ensure the camera is always above the terrain
        $camera->transform->position->y = max(
            $camera->transform->position->y,
            $heightmap->heightmap->getHeightAt($camera->transform->position->x, $camera->transform->position->z) + 70.0 // min 1.0 above the terrain
        );
    }
}