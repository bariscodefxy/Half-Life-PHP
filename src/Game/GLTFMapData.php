<?php

namespace App\Game;

use VISU\Geo\AABB;

/**
 * The parsed, GPU-independent result of loading a glTF map file.
 *
 * Positions and normals are already in world space: the glTF scene graph
 * transforms are applied, the map scale is applied and the floor is
 * re-centered so the lowest point sits at y = 0. UVs are flipped (v' = 1 - v)
 * to match the vertically flipped textures produced by
 * VISU\Graphics\Texture::loadFromFile().
 */
class GLTFMapData
{
    /**
     * The directory containing the glTF file (used to resolve texture uris).
     */
    public string $directory = '';

    /**
     * The uniform scale applied to the model.
     */
    public float $scale = 1.0;

    /**
     * The offset added to every position y so the lowest point lands on 0.
     */
    public float $yOffset = 0.0;

    /**
     * The offset added to every position x so the map is centered on x = 0.
     */
    public float $xOffset = 0.0;

    /**
     * The offset added to every position z so the map is centered on z = 0.
     */
    public float $zOffset = 0.0;

    /**
     * The world space axis aligned bounding box of the whole map.
     */
    public AABB $aabb;

    /**
     * Parsed meshes. Each entry:
     *   'name'          string
     *   'materialIndex' int
     *   'positions'     flat float list (x,y,z interleaved)
     *   'normals'       flat float list (x,y,z interleaved)
     *   'uvs'           flat float list (u,v interleaved)
     *   'indices'       int list
     *
     * @var array<int, array{name: string, materialIndex: int, positions: array<float>, normals: array<float>, uvs: array<float>, indices: array<int>}>
     */
    public array $meshes = [];

    /**
     * Raw glTF material json keyed by material index.
     *
     * @var array<int, array>
     */
    public array $materials = [];

    /**
     * Raw glTF texture json keyed by texture index.
     *
     * @var array<int, array>
     */
    public array $textures = [];

    /**
     * Raw glTF image json keyed by image index (each may hold a 'uri').
     *
     * @var array<int, array>
     */
    public array $images = [];
}
