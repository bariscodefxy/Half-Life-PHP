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
 * A large textured sphere centered on the map origin that renders behind all
 * map geometry, giving a simple static sky. The dome is big enough that the
 * player never approaches its surface but stays inside the camera far plane.
 */
class SkyDome
{
    public const RADIUS = 5000.0;
    public const STACKS = 32;
    public const SLICES = 64;

    private const TEXTURE_PATH = __DIR__ . '/../../resources/textures/sky.png';
    private const MODEL_NAME = 'sky_dome';

    public function __construct(
        private GLState $gl,
        private LPModelCollection $models,
    )
    {
    }

    /**
     * Spawns the sky dome entity. The model is built lazily on first use so a
     * GL context (texture + vertex buffer upload) is required.
     */
    public function build(EntitiesInterface $entities) : void
    {
        if (!$this->models->has(self::MODEL_NAME)) {
            $this->models->add($this->buildModel());
        }

        $entity = $entities->create();
        $entities->attach($entity, new DynamicRenderableModel(self::MODEL_NAME));

        $transform = $entities->attach($entity, new Transform);
        $transform->setPosition(new Vec3(0, 0, 0));
        $transform->markDirty();
    }

    /**
     * Builds the dome mesh: a UV sphere whose triangles face inward so they
     * stay visible from inside (the deferred light pass leaves GL_CULL_FACE
     * enabled), with the texture's deep-blue zenith at the dome's top and the
     * warm horizon band at the equator.
     */
    private function buildModel() : LPModel
    {
        $buffer = new FloatBuffer;
        $R = self::RADIUS;

        for ($i = 0; $i < self::STACKS; $i++) {
            $phi0 = M_PI * $i / self::STACKS;
            $phi1 = M_PI * ($i + 1) / self::STACKS;

            for ($j = 0; $j < self::SLICES; $j++) {
                $theta0 = 2 * M_PI * $j / self::SLICES;
                $theta1 = 2 * M_PI * ($j + 1) / self::SLICES;

                [$p00, $n00, $u00, $v00] = $this->corner($R, $phi0, $theta0);
                [$p01, $n01, $u01, $v01] = $this->corner($R, $phi0, $theta1);
                [$p10, $n10, $u10, $v10] = $this->corner($R, $phi1, $theta0);
                [$p11, $n11, $u11, $v11] = $this->corner($R, $phi1, $theta1);

                // quad (a = top-left, b = top-right, c = bottom-left, d = bottom-right)
                // wound so the triangles face INWARD: from inside the sphere the
                // dome is always visible even with GL_CULL_FACE left enabled by
                // the deferred light pass
                $this->pushVertex($buffer, $p00, $n00, $u00, $v00);
                $this->pushVertex($buffer, $p10, $n10, $u10, $v10);
                $this->pushVertex($buffer, $p11, $n11, $u11, $v11);

                $this->pushVertex($buffer, $p00, $n00, $u00, $v00);
                $this->pushVertex($buffer, $p11, $n11, $u11, $v11);
                $this->pushVertex($buffer, $p01, $n01, $u01, $v01);
            }
        }

        $vertexBuffer = new LPVertexBuffer($this->gl);
        $vertexBuffer->uploadData($buffer);

        $half = $R * 1.0001;
        $aabb = new AABB(new Vec3(-$half, -$half, -$half), new Vec3($half, $half, $half));

        $material = new LPMaterial(self::MODEL_NAME, new Vec3(1.0, 1.0, 1.0), 0.0);
        $material->texture = $this->loadTexture();

        $mesh = new LPMesh(
            $material,
            $vertexBuffer,
            0,
            (int) ($buffer->size() / 8),
            $aabb
        );

        $model = new LPModel(self::MODEL_NAME, [$mesh]);
        $model->aabb = $aabb;

        return $model;
    }

    /**
     * Returns [position, normal, u, v] for one sphere corner.
     */
    private function corner(float $R, float $phi, float $theta) : array
    {
        $x = $R * sin($phi) * cos($theta);
        $y = $R * cos($phi);
        $z = $R * sin($phi) * sin($theta);

        $inv = 1.0 / $R;
        $normal = [$x * $inv, $y * $inv, $z * $inv];

        // u wraps around the sphere; v runs horizon (0) -> zenith (1). The
        // texture loads vertically flipped (row 0 = bottom = horizon glow,
        // row 255 = top = deep blue), so v=1 samples the zenith color.
        $u = $theta / (2 * M_PI);
        $v = 1.0 - min(1.0, 2 * $phi / M_PI);

        return [[$x, $y, $z], $normal, $u, $v];
    }

    private function pushVertex(FloatBuffer $buffer, array $p, array $n, float $u, float $v) : void
    {
        $buffer->push($p[0]);
        $buffer->push($p[1]);
        $buffer->push($p[2]);

        $buffer->push($n[0]);
        $buffer->push($n[1]);
        $buffer->push($n[2]);

        $buffer->push($u);
        $buffer->push($v);
    }

    /**
     * Loads the sky texture as an sRGB, point-sampled texture matching the map
     * texture pipeline.
     */
    private function loadTexture() : Texture
    {
        $texture = new Texture($this->gl, self::MODEL_NAME . '_tex');

        $options = new TextureOptions;
        $options->isSRGB = true;
        $options->generateMipmaps = true;
        $options->minFilter = GL_NEAREST_MIPMAP_NEAREST;
        $options->magFilter = GL_NEAREST;
        $options->wrapS = GL_CLAMP_TO_EDGE;
        $options->wrapT = GL_CLAMP_TO_EDGE;

        $texture->loadFromFile(self::TEXTURE_PATH, $options);

        return $texture;
    }
}
