<?php

namespace App\Game;

/**
 * Static world-space triangle mesh with a coarse XZ spatial hash.
 *
 * Feeds triangle-accurate collision to PlayerMovement: each entry is a
 * non-degenerate triangle from the parsed map (already world space, floor
 * re-centered at y = 0). The grid buckets every triangle by the 1m XZ cells
 * its footprint touches, so per-tick collision queries only touch a handful of
 * nearby triangles instead of the whole 10k-triangle map.
 */
class TriangleCollisionWorld
{
    /**
     * World-space vertex positions, flat x,y,z triples, concatenated over all
     * meshes. Shared with the GPU model data.
     *
     * @var array<float>
     */
    public array $positions;

    /**
     * Triangle indices into $positions, three per triangle.
     *
     * @var array<int>
     */
    public array $indices;

    /**
     * Grid cell size in world units.
     */
    public float $cellSize;

    private float $gridMinX;
    private float $gridMinZ;

    /**
     * cellKey "cx,cz" => list of triangle offsets into $indices.
     *
     * @var array<string, array<int>>
     */
    private array $grid = [];

    /**
     * @param array<float> $positions flat x,y,z vertex triples
     * @param array<int> $indices three vertex indices per triangle
     * @param float $cellSize grid cell size in world units
     */
    public function __construct(array $positions, array $indices, float $cellSize = 1.0)
    {
        $this->positions = $positions;
        $this->indices = $indices;
        $this->cellSize = $cellSize;

        $minX = PHP_FLOAT_MAX;
        $minZ = PHP_FLOAT_MAX;
        $maxX = -PHP_FLOAT_MAX;
        $maxZ = -PHP_FLOAT_MAX;
        $vertexCount = intdiv(count($positions), 3);
        for ($i = 0; $i < $vertexCount; $i++) {
            $x = $positions[$i * 3];
            $z = $positions[$i * 3 + 2];
            if ($x < $minX) {
                $minX = $x;
            }
            if ($x > $maxX) {
                $maxX = $x;
            }
            if ($z < $minZ) {
                $minZ = $z;
            }
            if ($z > $maxZ) {
                $maxZ = $z;
            }
        }
        $this->gridMinX = floor($minX);
        $this->gridMinZ = floor($minZ);

        $triCount = intdiv(count($indices), 3);
        for ($t = 0; $t < $triCount; $t++) {
            $i0 = $indices[$t * 3] * 3;
            $i1 = $indices[$t * 3 + 1] * 3;
            $i2 = $indices[$t * 3 + 2] * 3;

            $ax = $positions[$i0];
            $az = $positions[$i0 + 2];
            $bx = $positions[$i1];
            $bz = $positions[$i1 + 2];
            $tx = $positions[$i2];
            $tz = $positions[$i2 + 2];

            $minCellX = (int) floor((min($ax, $bx, $tx) - $this->gridMinX) / $cellSize);
            $maxCellX = (int) floor((max($ax, $bx, $tx) - $this->gridMinX) / $cellSize);
            $minCellZ = (int) floor((min($az, $bz, $tz) - $this->gridMinZ) / $cellSize);
            $maxCellZ = (int) floor((max($az, $bz, $tz) - $this->gridMinZ) / $cellSize);

            for ($czi = $minCellZ; $czi <= $maxCellZ; $czi++) {
                for ($cxi = $minCellX; $cxi <= $maxCellX; $cxi++) {
                    $key = $cxi . ',' . $czi;
                    if (!isset($this->grid[$key])) {
                        $this->grid[$key] = [];
                    }
                    $this->grid[$key][] = $t * 3;
                }
            }
        }
    }

    /**
     * Triangle offsets (into $indices) whose XZ cell footprint overlaps the
     * axis aligned square [cx-r, cx+r] x [cz-r, cz+r].
     *
     * @return array<int>
     */
    public function querySquare(float $qcx, float $qcz, float $r) : array
    {
        $minCellX = (int) floor(($qcx - $r - $this->gridMinX) / $this->cellSize);
        $maxCellX = (int) floor(($qcx + $r - $this->gridMinX) / $this->cellSize);
        $minCellZ = (int) floor(($qcz - $r - $this->gridMinZ) / $this->cellSize);
        $maxCellZ = (int) floor(($qcz + $r - $this->gridMinZ) / $this->cellSize);

        $seen = [];
        $tris = [];
        for ($czi = $minCellZ; $czi <= $maxCellZ; $czi++) {
            for ($cxi = $minCellX; $cxi <= $maxCellX; $cxi++) {
                $cell = $this->grid[$cxi . ',' . $czi] ?? null;
                if ($cell === null) {
                    continue;
                }
                foreach ($cell as $offset) {
                    if (!isset($seen[$offset])) {
                        $seen[$offset] = true;
                        $tris[] = $offset;
                    }
                }
            }
        }
        return $tris;
    }

    /**
     * The three vertex positions of the triangle starting at index offset.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float, 6: float, 7: float, 8: float}
     */
    public function vertexTriple(int $offset) : array
    {
        $p = $this->positions;
        $i0 = $this->indices[$offset] * 3;
        $i1 = $this->indices[$offset + 1] * 3;
        $i2 = $this->indices[$offset + 2] * 3;
        return [
            $p[$i0], $p[$i0 + 1], $p[$i0 + 2],
            $p[$i1], $p[$i1 + 1], $p[$i1 + 2],
            $p[$i2], $p[$i2 + 1], $p[$i2 + 2],
        ];
    }
}
