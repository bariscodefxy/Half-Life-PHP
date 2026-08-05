<?php

namespace App\Game;

use GL\Buffer\FloatBuffer;
use GL\Math\Vec3;
use VISU\Component\VISULowPoly\DynamicRenderableModel;
use VISU\ECS\EntitiesInterface;
use VISU\Geo\AABB;
use VISU\Geo\Transform;
use VISU\Graphics\GLState;
use VISU\Graphics\Texture;
use VISU\Graphics\TextureOptions;
use VISU\System\VISULowPoly\LPMaterial;
use VISU\System\VISULowPoly\LPMesh;
use VISU\System\VISULowPoly\LPModel;
use VISU\System\VISULowPoly\LPModelCollection;
use VISU\System\VISULowPoly\LPVertexBuffer;

/**
 * The Crossfire glTF map (CC-BY-4.0 by Sketchfab uploader; see
 * resources/maps/crossfire/license.txt).
 *
 * Loading is lazy: the CPU side (parsing + occupancy grid colliders) and the
 * GPU side (vertex buffer + textures) only run once the map is first selected,
 * keeping startup fast when the test map is used.
 */
class CrossfireMap implements GameMap
{
    private const GLTF_PATH = __DIR__ . '/../../resources/maps/crossfire/scene.gltf';
    private const SCALE = 0.1;
    private const MODEL_NAME = 'crossfire';

    /**
     * @var array<AABB>
     */
    private array $colliders = [];

    private Vec3 $spawnPoint;

    private ?GLTFMapData $data = null;
    private ?TriangleCollisionWorld $triangleWorld = null;
    private ?LPModel $model = null;

    /**
     * Cached materials keyed by glTF material index.
     *
     * @var array<int, LPMaterial>
     */
    private array $materials = [];

    public function __construct(
        private GLState $gl,
        private LPModelCollection $models,
    )
    {
        $this->spawnPoint = new Vec3(0, 1, 0);
    }

    /**
     * Parses the map and builds the GPU model + colliders exactly once.
     * Requires a current GL context (texture + vertex buffer upload).
     */
    public function ensureLoaded() : void
    {
        if ($this->data !== null) {
            return;
        }

        $this->data = GLTFMapLoader::parse(self::GLTF_PATH, self::SCALE);

        $result = GLTFMapLoader::buildColliders($this->data);
        $this->colliders = $result['colliders'];
        $this->triangleWorld = GLTFMapLoader::buildTriangleWorld($this->data);

        $this->spawnPoint = GLTFMapLoader::findSpawnPoint($result['cells']);

        $this->model = $this->buildModel();
        $this->models->add($this->model);
    }

    public function getModels() : LPModelCollection
    {
        return $this->models;
    }

    /**
     * @return array<AABB>
     */
    public function getColliders() : array
    {
        $this->ensureLoaded();
        return $this->colliders;
    }

    public function getTriangleWorld() : ?TriangleCollisionWorld
    {
        $this->ensureLoaded();
        return $this->triangleWorld;
    }

    public function getSpawnPoint() : Vec3
    {
        $this->ensureLoaded();
        return $this->spawnPoint;
    }

    public function build(EntitiesInterface $entities) : void
    {
        $this->ensureLoaded();

        $entity = $entities->create();
        $entities->attach($entity, new DynamicRenderableModel(self::MODEL_NAME));

        $transform = $entities->attach($entity, new Transform);
        $transform->setPosition(new Vec3(0, 0, 0));
        $transform->setScale(new Vec3(1, 1, 1));
        $transform->markDirty();
    }

    /**
     * Builds the GPU model: one shared vertex buffer, one mesh per glTF
     * primitive, materials with their baseColor texture.
     */
    private function buildModel() : LPModel
    {
        $vertexBuffer = new LPVertexBuffer($this->gl);
        $buffer = new FloatBuffer;

        $meshData = [];
        foreach ($this->data->meshes as $i => $mesh) {
            $vertexOffset = (int) ($buffer->size() / 8);

            foreach ($mesh['indices'] as $index) {
                $vi = $index * 3;

                $buffer->push($mesh['positions'][$vi]);
                $buffer->push($mesh['positions'][$vi + 1]);
                $buffer->push($mesh['positions'][$vi + 2]);

                $buffer->push($mesh['normals'][$vi]);
                $buffer->push($mesh['normals'][$vi + 1]);
                $buffer->push($mesh['normals'][$vi + 2]);

                $uvi = $index * 2;

                $buffer->push($mesh['uvs'][$uvi]);
                $buffer->push($mesh['uvs'][$uvi + 1]);
            }

            $meshData[] = [
                'name' => $mesh['name'],
                'materialIndex' => $mesh['materialIndex'],
                'vertexOffset' => $vertexOffset,
                'vertexCount' => (int) ($buffer->size() / 8) - $vertexOffset,
                'aabb' => $this->meshAabb($mesh),
            ];
        }

        $vertexBuffer->uploadData($buffer);

        $meshes = [];
        foreach ($meshData as $i => $md) {
            $meshes[] = new LPMesh(
                $this->materialFor($md['materialIndex']),
                $vertexBuffer,
                $md['vertexOffset'],
                $md['vertexCount'],
                $md['aabb']
            );
        }

        $model = new LPModel(self::MODEL_NAME, $meshes);
        $model->recalculateAABB();

        return $model;
    }

    /**
     * Returns the cached (or freshly built) material for a glTF material index.
     */
    private function materialFor(int $materialIndex) : LPMaterial
    {
        if (isset($this->materials[$materialIndex])) {
            return $this->materials[$materialIndex];
        }

        $color = new Vec3(1.0, 1.0, 1.0);
        $texture = null;

        $material = $this->data->materials[$materialIndex] ?? null;
        if ($material !== null) {
            $factor = $material['pbrMetallicRoughness']['baseColorFactor'] ?? null;
            if (is_array($factor) && count($factor) >= 3) {
                $color = new Vec3((float) $factor[0], (float) $factor[1], (float) $factor[2]);
            }

            $textureIndex = $material['pbrMetallicRoughness']['baseColorTexture']['index'] ?? null;
            if ($textureIndex !== null) {
                $imageIndex = $this->data->textures[$textureIndex]['source'] ?? null;
                $uri = $imageIndex !== null ? ($this->data->images[$imageIndex]['uri'] ?? null) : null;
                if ($uri !== null) {
                    $path = $this->data->directory . DIRECTORY_SEPARATOR . $uri;
                    if (is_file($path)) {
                        $texture = $this->loadTexture('crossfire_tex_' . $imageIndex, $path);
                    }
                }
            }
        }

        $this->materials[$materialIndex] = new LPMaterial('crossfire_mat_' . $materialIndex, $color, 0.0);
        $this->materials[$materialIndex]->texture = $texture;

        return $this->materials[$materialIndex];
    }

    /**
     * Loads a baseColor PNG as an sRGB, point-sampled texture so the flat
     * Half-Life style colors survive the deferred pipeline's gamma handling.
     */
    private function loadTexture(string $name, string $path) : Texture
    {
        $texture = new Texture($this->gl, $name);

        $options = new TextureOptions;
        $options->isSRGB = true;
        $options->generateMipmaps = true;
        $options->minFilter = GL_NEAREST_MIPMAP_NEAREST;
        $options->magFilter = GL_NEAREST;

        $texture->loadFromFile($path, $options);

        return $texture;
    }

    /**
     * World space AABB of a parsed mesh's positions.
     */
    private function meshAabb(array $mesh) : AABB
    {
        $minX = $minY = $minZ = PHP_FLOAT_MAX;
        $maxX = $maxY = $maxZ = -PHP_FLOAT_MAX;

        $positions = $mesh['positions'];
        for ($i = 0; $i < count($positions); $i += 3) {
            $minX = min($minX, $positions[$i]);
            $minY = min($minY, $positions[$i + 1]);
            $minZ = min($minZ, $positions[$i + 2]);
            $maxX = max($maxX, $positions[$i]);
            $maxY = max($maxY, $positions[$i + 1]);
            $maxZ = max($maxZ, $positions[$i + 2]);
        }

        return new AABB(
            new Vec3($minX, $minY, $minZ),
            new Vec3($maxX, $maxY, $maxZ)
        );
    }
}
