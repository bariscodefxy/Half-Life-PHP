<?php

namespace App\Game;

use GL\Buffer\FloatBuffer;
use GL\Math\Vec3;
use VISU\Geo\AABB;
use VISU\Graphics\GLState;
use VISU\System\VISULowPoly\LPMaterial;
use VISU\System\VISULowPoly\LPMesh;
use VISU\System\VISULowPoly\LPModel;
use VISU\System\VISULowPoly\LPVertexBuffer;

class MeshBuilder
{
    /**
     * Builds a runtime unit cube model (half extent 0.5) for the low poly renderer.
     *
     * The model contains a single mesh with position + normal vertex data
     * (6 floats per vertex) matching the LPVertexBuffer layout.
     */
    public static function buildUnitCube(GLState $gl, string $name, Vec3 $color) : LPModel
    {
        $half = 0.5;

        $positions = [
            // +X face
            [1, -1, -1], [1, 1, -1], [1, 1, 1], [1, -1, 1],
            // -X face
            [-1, -1, 1], [-1, 1, 1], [-1, 1, -1], [-1, -1, -1],
            // +Y face
            [-1, 1, 1], [1, 1, 1], [1, 1, -1], [-1, 1, -1],
            // -Y face
            [-1, -1, -1], [1, -1, -1], [1, -1, 1], [-1, -1, 1],
            // +Z face
            [1, -1, 1], [1, 1, 1], [-1, 1, 1], [-1, -1, 1],
            // -Z face
            [-1, -1, -1], [-1, 1, -1], [1, 1, -1], [1, -1, -1],
        ];

        $normals = [
            [1, 0, 0], [-1, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, 1], [0, 0, -1],
        ];

        $buffer = new FloatBuffer;

        for ($face = 0; $face < 6; $face++) {
            $normal = $normals[$face];
            $base = $face * 4;

            foreach ([0, 1, 2, 0, 2, 3] as $corner) {
                $p = $positions[$base + $corner];

                $buffer->push($p[0] * $half);
                $buffer->push($p[1] * $half);
                $buffer->push($p[2] * $half);

                $buffer->push($normal[0]);
                $buffer->push($normal[1]);
                $buffer->push($normal[2]);

                $buffer->push(0.0);
                $buffer->push(0.0);
            }
        }

        $vertexBuffer = new LPVertexBuffer($gl);
        $vertexBuffer->uploadData($buffer);

        $aabb = new AABB(new Vec3(-$half, -$half, -$half), new Vec3($half, $half, $half));

        $mesh = new LPMesh(
            new LPMaterial($name, $color, 0.0),
            $vertexBuffer,
            0,
            (int) ($buffer->size() / 8),
            $aabb
        );

        $model = new LPModel($name);
        $model->meshes = [$mesh];
        $model->aabb = $aabb;

        return $model;
    }
}
