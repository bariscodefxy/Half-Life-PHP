<?php

namespace App\Game;

use GL\Math\Vec3;
use VISU\ECS\EntitiesInterface;
use VISU\Geo\AABB;
use VISU\System\VISULowPoly\LPModelCollection;

/**
 * A playable map: provides the shared renderable models, spawns its geometry
 * entities and exposes the colliders and spawn point for the player.
 */
interface GameMap
{
    /**
     * The model collection all maps share. Each map adds its own models
     * under unique names.
     */
    public function getModels() : LPModelCollection;

    /**
     * Spawns the map geometry entities into the world.
     */
    public function build(EntitiesInterface $entities) : void;

    /**
     * The solid AABB colliders for the player physics.
     *
     * @return array<AABB>
     */
    public function getColliders() : array;

    /**
     * The triangle-accurate collision world for this map, or null when the map
     * only provides AABB colliders.
     */
    public function getTriangleWorld() : ?TriangleCollisionWorld;

    /**
     * The feet position the player spawns at.
     */
    public function getSpawnPoint() : Vec3;
}
