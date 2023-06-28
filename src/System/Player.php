<?php

namespace PHPLife\System;
use PHPLife\Scene\LevelScene;

class Player
{
    /**
     * @var LevelScene
     */
    private LevelScene $levelScene;

    /**
     * @var CameraSystem
     */
    private CameraSystem $cameraSystem;

    /**
     * @param CameraSystem $cameraSystem
     * @param LevelScene $levelScene
     * @throws \VISU\Exception\VISUException
     */
    public function __construct(CameraSystem $cameraSystem, LevelScene $levelScene)
    {
        $this->levelScene = $levelScene;
        $this->cameraSystem = $cameraSystem;
        $this->cameraSystem->getActiveCamera($levelScene->entities)->fieldOfView = 90.0;
    }

}