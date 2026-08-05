<?php

namespace App\Game;

use GL\Math\Vec3;
use VISU\Geo\AABB;

/**
 * A minimal glTF 2.0 loader tuned for the Crossfire map asset.
 *
 * Pure PHP, no GL context required: it decodes the .bin buffer, walks the
 * node hierarchy, transforms positions/normals into world space, applies a
 * uniform scale and re-centers the floor at y = 0. The result can be used to
 * build GPU vertex data and occupancy-grid colliders alike.
 */
class GLTFMapLoader
{
    public const GL_COMPONENT_BYTE = 5120;
    public const GL_COMPONENT_UBYTE = 5121;
    public const GL_COMPONENT_SHORT = 5122;
    public const GL_COMPONENT_USHORT = 5123;
    public const GL_COMPONENT_INT = 5124;
    public const GL_COMPONENT_UINT = 5125;
    public const GL_COMPONENT_FLOAT = 5126;

    /**
     * Minimum vertical thickness of a collider slab. Flat terrain sheets have
     * zero-thickness triangle spans; giving them this much slab keeps the
     * player standing on the surface while making it fast enough to catch a
     * falling box without tunneling.
     */
    public const MIN_COLLIDER_THICKNESS = 0.5;

    private const TYPE_COMPONENT_COUNT = [
        'SCALAR' => 1,
        'VEC2' => 2,
        'VEC3' => 3,
        'VEC4' => 4,
        'MAT4' => 16,
    ];

    /**
     * Parses a glTF scene into world space GLTFMapData.
     *
     * @param string $gltfPath absolute path to the .gltf file
     * @param float $scale uniform scale applied to the model
     */
    public static function parse(string $gltfPath, float $scale = 1.0) : GLTFMapData
    {
        $gltf = json_decode((string) file_get_contents($gltfPath), true);
        if (!is_array($gltf)) {
            throw new \RuntimeException("Failed to parse glTF json: {$gltfPath}");
        }

        $directory = dirname($gltfPath);
        $buffers = self::loadBuffers($gltf, $directory);
        $bufferViews = $gltf['bufferViews'] ?? [];
        $accessors = $gltf['accessors'] ?? [];

        // pre-decode accessors referenced by any primitive
        $decoded = [];
        $meshes = $gltf['meshes'] ?? [];
        $primitivesByMesh = [];
        foreach ($meshes as $meshIndex => $mesh) {
            $primitivesByMesh[$meshIndex] = [];
            foreach ($mesh['primitives'] ?? [] as $primitive) {
                $attributes = $primitive['attributes'] ?? [];
                $needed = [$attributes['POSITION'] ?? null, $attributes['NORMAL'] ?? null, $attributes['TEXCOORD_0'] ?? null, $primitive['indices'] ?? null];
                foreach ($needed as $accessorIndex) {
                    if ($accessorIndex !== null && !isset($decoded[$accessorIndex])) {
                        $decoded[$accessorIndex] = self::decodeAccessor($accessors[$accessorIndex], $bufferViews, $buffers);
                    }
                }
                $primitivesByMesh[$meshIndex][] = $primitive;
            }
        }

        // compute world matrices for every node
        $nodes = $gltf['nodes'] ?? [];
        $parents = array_fill(0, count($nodes), -1);
        foreach ($nodes as $nodeIndex => $node) {
            foreach ($node['children'] ?? [] as $child) {
                $parents[$child] = $nodeIndex;
            }
        }

        $worldMatrices = array_fill(0, count($nodes), null);
        $roots = $gltf['scenes'][$gltf['scene'] ?? 0]['nodes'] ?? [];
        foreach ($roots as $root) {
            self::computeWorldMatrices($nodes, $parents, $worldMatrices, $root, self::identityMatrix());
        }

        $data = new GLTFMapData;
        $data->directory = $directory;
        $data->scale = $scale;

        // accumulate world positions (without the floor offset yet)
        $min = null;
        $max = null;

        foreach ($primitivesByMesh as $meshIndex => $primitives) {
            $mesh = $meshes[$meshIndex];
            foreach ($primitives as $primitive) {
                if (($primitive['mode'] ?? 4) !== 4) {
                    continue;
                }

                $attributes = $primitive['attributes'] ?? [];
                $positionIndex = $attributes['POSITION'] ?? null;
                if ($positionIndex === null) {
                    continue;
                }

                $nodeIndex = self::findMeshNode($nodes, $meshIndex);
                $matrix = $nodeIndex === null ? self::identityMatrix() : $worldMatrices[$nodeIndex];

                $positions = $decoded[$positionIndex];
                $normals = isset($attributes['NORMAL']) ? $decoded[$attributes['NORMAL']] : null;
                $uvs = isset($attributes['TEXCOORD_0']) ? $decoded[$attributes['TEXCOORD_0']] : null;
                $indices = isset($primitive['indices']) ? $decoded[$primitive['indices']] : null;

                if ($indices === null) {
                    // no index accessor: generate a sequential one
                    $indices = range(0, (int) (count($positions) / 3) - 1);
                }

                $outPositions = [];
                $outNormals = [];
                $outUvs = [];

                foreach ($indices as $index) {
                    $i = $index * 3;
                    $p = self::transformPoint($matrix, $positions[$i], $positions[$i + 1], $positions[$i + 2]);

                    $outPositions[] = $p[0];
                    $outPositions[] = $p[1];
                    $outPositions[] = $p[2];

                    if ($normals !== null) {
                        $n = self::transformNormal($matrix, $normals[$i], $normals[$i + 1], $normals[$i + 2]);
                        $outNormals[] = $n[0];
                        $outNormals[] = $n[1];
                        $outNormals[] = $n[2];
                    } else {
                        $outNormals[] = 0.0;
                        $outNormals[] = 1.0;
                        $outNormals[] = 0.0;
                    }

                    if ($uvs !== null) {
                        $u = $uvs[$index * 2];
                        $v = $uvs[$index * 2 + 1];
                        $outUvs[] = $u;
                        $outUvs[] = 1.0 - $v; // textures are vertically flipped
                    } else {
                        $outUvs[] = 0.0;
                        $outUvs[] = 0.0;
                    }

                    if ($min === null) {
                        $min = $p;
                        $max = $p;
                    } else {
                        $min[0] = min($min[0], $p[0]);
                        $min[1] = min($min[1], $p[1]);
                        $min[2] = min($min[2], $p[2]);
                        $max[0] = max($max[0], $p[0]);
                        $max[1] = max($max[1], $p[1]);
                        $max[2] = max($max[2], $p[2]);
                    }
                }

                $data->meshes[] = [
                    'name' => $mesh['name'] ?? 'mesh_' . $meshIndex,
                    'materialIndex' => $primitive['material'] ?? -1,
                    'positions' => $outPositions,
                    'normals' => $outNormals,
                    'uvs' => $outUvs,
                    // the vertex arrays above are already expanded to one
                    // entry per index reference, so the topology is identity
                    'indices' => range(0, (int) (count($outPositions) / 3) - 1),
                ];
            }
        }

        if ($min === null) {
            throw new \RuntimeException("glTF map contains no renderable mesh primitives: {$gltfPath}");
        }

        // re-center the floor at y = 0 and center the map horizontally
        $xOffset = -($min[0] + $max[0]) / 2.0;
        $yOffset = -$min[1];
        $zOffset = -($min[2] + $max[2]) / 2.0;
        $data->xOffset = $xOffset;
        $data->yOffset = $yOffset;
        $data->zOffset = $zOffset;

        foreach ($data->meshes as &$meshData) {
            for ($i = 0; $i < count($meshData['positions']); $i += 3) {
                $meshData['positions'][$i] += $xOffset;
                $meshData['positions'][$i + 1] += $yOffset;
                $meshData['positions'][$i + 2] += $zOffset;
            }
        }
        unset($meshData);

        $min[0] += $xOffset;
        $min[1] += $yOffset;
        $min[2] += $zOffset;
        $max[0] += $xOffset;
        $max[1] += $yOffset;
        $max[2] += $zOffset;

        $data->aabb = new AABB(
            new Vec3($min[0], $min[1], $min[2]),
            new Vec3($max[0], $max[1], $max[2])
        );

        $data->materials = $gltf['materials'] ?? [];
        $data->textures = $gltf['textures'] ?? [];
        $data->images = $gltf['images'] ?? [];

        return $data;
    }

    /**
     * Loads the raw bytes of every buffer, resolving file uris relative to
     * the gltf directory and decoding data: uris.
     *
     * @return array<int, string>
     */
    private static function loadBuffers(array $gltf, string $directory) : array
    {
        $buffers = [];
        foreach ($gltf['buffers'] ?? [] as $buffer) {
            $uri = $buffer['uri'] ?? null;
            if ($uri === null) {
                throw new \RuntimeException('glTF buffers without a uri are not supported');
            }

            if (str_starts_with($uri, 'data:')) {
                $comma = strpos($uri, ',');
                if ($comma === false) {
                    throw new \RuntimeException('Malformed data uri in glTF buffer');
                }
                $payload = substr($uri, $comma + 1);
                $meta = substr($uri, 5, $comma - 5);
                if (str_contains($meta, ';base64')) {
                    $payload = base64_decode($payload, true);
                } else {
                    $payload = rawurldecode($payload);
                }
                $buffers[] = (string) $payload;
            } else {
                $path = $directory . DIRECTORY_SEPARATOR . $uri;
                $bytes = @file_get_contents($path);
                if ($bytes === false) {
                    throw new \RuntimeException("Failed to read glTF buffer: {$path}");
                }
                $buffers[] = $bytes;
            }
        }

        return $buffers;
    }

    /**
     * Decodes one accessor into a flat array of native PHP values.
     *
     * @return array<int, int|float>
     */
    private static function decodeAccessor(array $accessor, array $bufferViews, array $buffers) : array
    {
        if (isset($accessor['sparse'])) {
            throw new \RuntimeException('Sparse glTF accessors are not supported');
        }

        $bufferView = $bufferViews[$accessor['bufferView']];
        $buffer = $buffers[$bufferView['buffer']];

        $componentType = $accessor['componentType'];
        $count = $accessor['count'];
        $components = self::TYPE_COMPONENT_COUNT[$accessor['type']] ?? 1;

        $componentSize = self::componentSize($componentType);
        $stride = $bufferView['byteStride'] ?? ($componentSize * $components);
        $byteOffset = ($bufferView['byteOffset'] ?? 0) + ($accessor['byteOffset'] ?? 0);

        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $offset = $byteOffset + $i * $stride;
            for ($c = 0; $c < $components; $c++) {
                $componentOffset = $offset + $c * $componentSize;
                $slice = substr($buffer, $componentOffset, $componentSize);
                $result[] = self::decodeComponent($componentType, $slice);
            }
        }

        return $result;
    }

    /**
     * Returns the size in bytes of a glTF component type.
     */
    private static function componentSize(int $componentType) : int
    {
        return match ($componentType) {
            self::GL_COMPONENT_BYTE, self::GL_COMPONENT_UBYTE => 1,
            self::GL_COMPONENT_SHORT, self::GL_COMPONENT_USHORT => 2,
            self::GL_COMPONENT_INT, self::GL_COMPONENT_UINT, self::GL_COMPONENT_FLOAT => 4,
            default => throw new \RuntimeException("Unsupported glTF component type: {$componentType}"),
        };
    }

    /**
     * Decodes a single little-endian component.
     *
     * @return int|float
     */
    private static function decodeComponent(int $componentType, string $slice) : int|float
    {
        return match ($componentType) {
            self::GL_COMPONENT_FLOAT => unpack('f', $slice)[1],
            self::GL_COMPONENT_UINT => unpack('V', $slice)[1],
            self::GL_COMPONENT_INT => self::toSigned(unpack('V', $slice)[1], 32),
            self::GL_COMPONENT_USHORT => unpack('v', $slice)[1],
            self::GL_COMPONENT_SHORT => self::toSigned(unpack('v', $slice)[1], 16),
            self::GL_COMPONENT_UBYTE => ord($slice),
            self::GL_COMPONENT_BYTE => self::toSigned(ord($slice), 8),
            default => throw new \RuntimeException("Unsupported glTF component type: {$componentType}"),
        };
    }

    /**
     * Sign-extends an unsigned value of the given bit width.
     */
    private static function toSigned(int $value, int $bits) : int
    {
        $sign = 1 << ($bits - 1);
        if (($value & $sign) !== 0) {
            return $value - (1 << $bits);
        }

        return $value;
    }

    /**
     * Finds the first node that instantiates the given mesh.
     */
    private static function findMeshNode(array $nodes, int $meshIndex) : ?int
    {
        foreach ($nodes as $nodeIndex => $node) {
            if (($node['mesh'] ?? null) === $meshIndex) {
                return $nodeIndex;
            }
        }

        return null;
    }

    /**
     * Recursively computes world matrices for all nodes.
     *
     * @param array<int, array|null> $worldMatrices
     */
    private static function computeWorldMatrices(array $nodes, array $parents, array &$worldMatrices, int $nodeIndex, array $parentMatrix) : void
    {
        $local = self::localMatrix($nodes[$nodeIndex]);
        $world = self::mat4Mul($parentMatrix, $local);
        $worldMatrices[$nodeIndex] = $world;

        foreach ($nodes[$nodeIndex]['children'] ?? [] as $child) {
            self::computeWorldMatrices($nodes, $parents, $worldMatrices, $child, $world);
        }
    }

    /**
     * Builds the local TRS matrix for a node (glTF column-major layout).
     *
     * @return array<int, array<int, float>> 4x4 column-major
     */
    private static function localMatrix(array $node) : array
    {
        if (isset($node['matrix'])) {
            return self::arrayToMat4($node['matrix']);
        }

        $translation = $node['translation'] ?? [0.0, 0.0, 0.0];
        $rotation = $node['rotation'] ?? [0.0, 0.0, 0.0, 1.0];
        $scale = $node['scale'] ?? [1.0, 1.0, 1.0];

        $rx = $rotation[0];
        $ry = $rotation[1];
        $rz = $rotation[2];
        $rw = $rotation[3];

        $sx = $scale[0];
        $sy = $scale[1];
        $sz = $scale[2];

        $rotationMatrix = self::mat4([
            [1 - 2 * $ry * $ry - 2 * $rz * $rz, 2 * $rx * $ry - 2 * $rw * $rz, 2 * $rx * $rz + 2 * $rw * $ry, 0],
            [2 * $rx * $ry + 2 * $rw * $rz, 1 - 2 * $rx * $rx - 2 * $rz * $rz, 2 * $ry * $rz - 2 * $rw * $rx, 0],
            [2 * $rx * $rz - 2 * $rw * $ry, 2 * $ry * $rz + 2 * $rw * $rx, 1 - 2 * $rx * $rx - 2 * $ry * $ry, 0],
            [0, 0, 0, 1],
        ]);

        $scaleMatrix = self::mat4([
            [$sx, 0, 0, 0],
            [0, $sy, 0, 0],
            [0, 0, $sz, 0],
            [0, 0, 0, 1],
        ]);

        $translationMatrix = self::mat4([
            [1, 0, 0, $translation[0]],
            [0, 1, 0, $translation[1]],
            [0, 0, 1, $translation[2]],
            [0, 0, 0, 1],
        ]);

        // M = T * R * S
        return self::mat4Mul(self::mat4Mul($translationMatrix, $rotationMatrix), $scaleMatrix);
    }

    /**
     * Converts a glTF flat 16-float column-major matrix into a 4x4 column-major array.
     *
     * @param array<int, float> $flat
     * @return array<int, array<int, float>>
     */
    private static function arrayToMat4(array $flat) : array
    {
        return [
            [$flat[0], $flat[1], $flat[2], $flat[3]],
            [$flat[4], $flat[5], $flat[6], $flat[7]],
            [$flat[8], $flat[9], $flat[10], $flat[11]],
            [$flat[12], $flat[13], $flat[14], $flat[15]],
        ];
    }

    /**
     * Builds a 4x4 matrix from rows.
     *
     * @param array<int, array<int, float>> $rows
     * @return array<int, array<int, float>>
     */
    private static function mat4(array $rows) : array
    {
        $m = [];
        for ($c = 0; $c < 4; $c++) {
            for ($r = 0; $r < 4; $r++) {
                $m[$c][$r] = $rows[$r][$c];
            }
        }

        return $m;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private static function identityMatrix() : array
    {
        return [
            [1.0, 0.0, 0.0, 0.0],
            [0.0, 1.0, 0.0, 0.0],
            [0.0, 0.0, 1.0, 0.0],
            [0.0, 0.0, 0.0, 1.0],
        ];
    }

    /**
     * Column-major 4x4 matrix multiplication (a * b).
     *
     * @param array<int, array<int, float>> $a
     * @param array<int, array<int, float>> $b
     * @return array<int, array<int, float>>
     */
    private static function mat4Mul(array $a, array $b) : array
    {
        $out = [];
        for ($c = 0; $c < 4; $c++) {
            for ($r = 0; $r < 4; $r++) {
                $v = 0.0;
                for ($k = 0; $k < 4; $k++) {
                    $v += $a[$k][$r] * $b[$c][$k];
                }
                $out[$c][$r] = $v;
            }
        }

        return $out;
    }

    /**
     * Transforms a point (w = 1) by a column-major matrix.
     *
     * @param array<int, array<int, float>> $m
     * @return array{0: float, 1: float, 2: float}
     */
    private static function transformPoint(array $m, float $x, float $y, float $z) : array
    {
        return [
            $m[0][0] * $x + $m[1][0] * $y + $m[2][0] * $z + $m[3][0],
            $m[0][1] * $x + $m[1][1] * $y + $m[2][1] * $z + $m[3][1],
            $m[0][2] * $x + $m[1][2] * $y + $m[2][2] * $z + $m[3][2],
        ];
    }

    /**
     * Transforms a direction (w = 0) by the inverse-transpose of the upper-left
     * 3x3 of a matrix and normalizes. Reflections (negative scale) flip normal
     * directions, which a raw matrix transform would get wrong.
     *
     * @param array<int, array<int, float>> $m
     * @return array{0: float, 1: float, 2: float}
     */
    private static function transformNormal(array $m, float $x, float $y, float $z) : array
    {
        $m00 = $m[0][0];
        $m01 = $m[1][0];
        $m02 = $m[2][0];
        $m10 = $m[0][1];
        $m11 = $m[1][1];
        $m12 = $m[2][1];
        $m20 = $m[0][2];
        $m21 = $m[1][2];
        $m22 = $m[2][2];

        $det = $m00 * ($m11 * $m22 - $m12 * $m21)
            - $m01 * ($m10 * $m22 - $m12 * $m20)
            + $m02 * ($m10 * $m21 - $m11 * $m20);
        if (abs($det) < 1e-12) {
            return [0.0, 1.0, 0.0];
        }
        $invDet = 1.0 / $det;

        // 3x3 inverse
        $i00 = ($m11 * $m22 - $m12 * $m21) * $invDet;
        $i01 = ($m02 * $m21 - $m01 * $m22) * $invDet;
        $i02 = ($m01 * $m12 - $m02 * $m11) * $invDet;
        $i10 = ($m12 * $m20 - $m10 * $m22) * $invDet;
        $i11 = ($m00 * $m22 - $m02 * $m20) * $invDet;
        $i12 = ($m02 * $m10 - $m00 * $m12) * $invDet;
        $i20 = ($m10 * $m21 - $m11 * $m20) * $invDet;
        $i21 = ($m01 * $m20 - $m00 * $m21) * $invDet;
        $i22 = ($m00 * $m11 - $m01 * $m10) * $invDet;

        // apply the transpose
        $nx = $i00 * $x + $i10 * $y + $i20 * $z;
        $ny = $i01 * $x + $i11 * $y + $i21 * $z;
        $nz = $i02 * $x + $i12 * $y + $i22 * $z;

        $length = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
        if ($length < 1e-9) {
            return [0.0, 1.0, 0.0];
        }

        return [$nx / $length, $ny / $length, $nz / $length];
    }

    /**
     * Builds a 1 meter XZ occupancy grid of solid colliders from the parsed
     * map data.
     *
     * Every non-degenerate triangle contributes its real vertical span
     * [minY, maxY] to every cell its footprint overlaps. Spans that overlap or
     * nearly touch are merged per cell, so walls become solid columns, flat
     * floors become thin slabs and ramps behave as solid walls (the player
     * walks on top, never through). This is deliberately orientation-agnostic:
     * low-poly maps have plenty of near-degenerate slivers whose geometric
     * normals are unreliable.
     *
     * Returns the collider list plus a per-cell lookup used for spawn point
     * selection.
     *
     * @return array{colliders: array<AABB>, cells: array<string, array<AABB>>}
     */
    public static function buildColliders(GLTFMapData $data, float $cellSize = 1.0) : array
    {
        $gridMinX = floor($data->aabb->min->x);
        $gridMinZ = floor($data->aabb->min->z);

        $cellKey = fn (int $cx, int $cz) : string => $cx . ',' . $cz;

        $cellSpans = [];

        foreach ($data->meshes as $meshData) {
            $positions = $meshData['positions'];
            $indices = $meshData['indices'];

            for ($t = 0; $t < count($indices); $t += 3) {
                $i0 = $indices[$t] * 3;
                $i1 = $indices[$t + 1] * 3;
                $i2 = $indices[$t + 2] * 3;

                $ax = $positions[$i0];
                $ay = $positions[$i0 + 1];
                $az = $positions[$i0 + 2];
                $bx = $positions[$i1];
                $by = $positions[$i1 + 1];
                $bz = $positions[$i1 + 2];
                $cx = $positions[$i2];
                $cy = $positions[$i2 + 1];
                $cz = $positions[$i2 + 2];

                // skip degenerate (zero area) triangles
                $u1 = $bx - $ax;
                $u2 = $by - $ay;
                $u3 = $bz - $az;
                $v1 = $cx - $ax;
                $v2 = $cy - $ay;
                $v3 = $cz - $az;

                $areaSquared = ($u2 * $v3 - $u3 * $v2) ** 2
                    + ($u3 * $v1 - $u1 * $v3) ** 2
                    + ($u1 * $v2 - $u2 * $v1) ** 2;
                if ($areaSquared < 1e-12) {
                    continue;
                }

                $minX = min($ax, $bx, $cx);
                $maxX = max($ax, $bx, $cx);
                $minY = min($ay, $by, $cy);
                $maxY = max($ay, $by, $cy);
                $minZ = min($az, $bz, $cz);
                $maxZ = max($az, $bz, $cz);

                $cellMinX = (int) floor(($minX - $gridMinX) / $cellSize);
                $cellMaxX = (int) floor(($maxX - $gridMinX) / $cellSize);
                $cellMinZ = (int) floor(($minZ - $gridMinZ) / $cellSize);
                $cellMaxZ = (int) floor(($maxZ - $gridMinZ) / $cellSize);

                for ($czi = $cellMinZ; $czi <= $cellMaxZ; $czi++) {
                    for ($cxi = $cellMinX; $cxi <= $cellMaxX; $cxi++) {
                        $key = $cellKey($cxi, $czi);
                        if (!isset($cellSpans[$key])) {
                            $cellSpans[$key] = [];
                        }
                        $cellSpans[$key][] = [$minY, $maxY];
                    }
                }
            }
        }

        $colliders = [];
        $cells = [];

        foreach ($cellSpans as $key => $spans) {
            // merge overlapping / nearly touching spans into solid AABBs
            usort($spans, fn (array $a, array $b) => $a[0] <=> $b[0]);
            $merged = [];
            foreach ($spans as $span) {
                $mergedCount = count($merged);
                if ($mergedCount > 0 && $span[0] - $merged[$mergedCount - 1][1] < 0.1) {
                    $merged[$mergedCount - 1][1] = max($merged[$mergedCount - 1][1], $span[1]);
                } else {
                    $merged[] = $span;
                }
            }

            [$cx, $cz] = self::parseCellKey($key);
            foreach ($merged as $range) {
                // Flat terrain is a zero-thickness sheet: its triangle span is
                // exactly the surface height. Instead of discarding these, give
                // every near-degenerate span a minimum slab thickness so the
                // player stands on the surface instead of falling through it.
                if ($range[1] - $range[0] < self::MIN_COLLIDER_THICKNESS) {
                    $range[0] = $range[1] - self::MIN_COLLIDER_THICKNESS;
                }
                $aabb = new AABB(
                    new Vec3($gridMinX + $cx * $cellSize, $range[0], $gridMinZ + $cz * $cellSize),
                    new Vec3($gridMinX + ($cx + 1) * $cellSize, $range[1], $gridMinZ + ($cz + 1) * $cellSize)
                );
                $colliders[] = $aabb;
                $cells[$key][] = $aabb;
            }
        }

        return ['colliders' => $colliders, 'cells' => $cells];
    }

    /**
     * Builds a triangle-accurate collision world from the parsed map data.
     *
     * Every non-degenerate triangle is kept in world space, so the collision
     * mesh matches the visible map exactly (stairs, ramps, walls). Degenerate
     * (zero-area) triangles are skipped, mirroring buildColliders().
     */
    public static function buildTriangleWorld(GLTFMapData $data, float $cellSize = 1.0) : TriangleCollisionWorld
    {
        $positions = [];
        $indices = [];

        foreach ($data->meshes as $meshData) {
            $meshPositions = $meshData['positions'];
            $meshIndices = $meshData['indices'];

            for ($t = 0; $t < count($meshIndices); $t += 3) {
                $i0 = $meshIndices[$t] * 3;
                $i1 = $meshIndices[$t + 1] * 3;
                $i2 = $meshIndices[$t + 2] * 3;

                $ax = $meshPositions[$i0];
                $ay = $meshPositions[$i0 + 1];
                $az = $meshPositions[$i0 + 2];
                $bx = $meshPositions[$i1];
                $by = $meshPositions[$i1 + 1];
                $bz = $meshPositions[$i1 + 2];
                $cx = $meshPositions[$i2];
                $cy = $meshPositions[$i2 + 1];
                $cz = $meshPositions[$i2 + 2];

                $u1 = $bx - $ax;
                $u2 = $by - $ay;
                $u3 = $bz - $az;
                $v1 = $cx - $ax;
                $v2 = $cy - $ay;
                $v3 = $cz - $az;

                $areaSquared = ($u2 * $v3 - $u3 * $v2) ** 2
                    + ($u3 * $v1 - $u1 * $v3) ** 2
                    + ($u1 * $v2 - $u2 * $v1) ** 2;
                if ($areaSquared < 1e-12) {
                    continue;
                }

                $base = intdiv(count($positions), 3);
                $positions[] = $ax;
                $positions[] = $ay;
                $positions[] = $az;
                $positions[] = $bx;
                $positions[] = $by;
                $positions[] = $bz;
                $positions[] = $cx;
                $positions[] = $cy;
                $positions[] = $cz;
                $indices[] = $base;
                $indices[] = $base + 1;
                $indices[] = $base + 2;
            }
        }

        return new TriangleCollisionWorld($positions, $indices, $cellSize);
    }

    /**
     * Picks a spawn point from the cell grid: the surface nearest the map
     * center (world x/z origin) where a 1x2x1 player box can stand without
     * intersecting any collider. Returns the feet position.
     *
     * @param array<string, array<AABB>> $cells the "cx,cz" => colliders grid
     */
    public static function findSpawnPoint(array $cells) : Vec3
    {
        $best = null;
        $bestDist = PHP_FLOAT_MAX;

        $adjacent = [];
        for ($dx = -1; $dx <= 1; $dx++) {
            for ($dz = -1; $dz <= 1; $dz++) {
                $adjacent[] = [$dx, $dz];
            }
        }

        foreach ($cells as $key => $colliders) {
            [$cx, $cz] = self::parseCellKey($key);

            $neighbors = [];
            foreach ($adjacent as [$dx, $dz]) {
                $neighborKey = ($cx + $dx) . ',' . ($cz + $dz);
                foreach ($cells[$neighborKey] ?? [] as $aabb) {
                    $neighbors[] = $aabb;
                }
            }

            foreach ($colliders as $candidate) {
                $top = $candidate->max->y;
                $centerX = ($candidate->min->x + $candidate->max->x) / 2;
                $centerZ = ($candidate->min->z + $candidate->max->z) / 2;

                $dist = $centerX * $centerX + $centerZ * $centerZ;
                if ($dist >= $bestDist) {
                    continue;
                }

                $blocked = false;
                $r = PlayerMovement::PLAYER_RADIUS;
                $h = PlayerMovement::PLAYER_HEIGHT;
                foreach ($neighbors as $aabb) {
                    if ($centerX - $r >= $aabb->max->x || $centerX + $r <= $aabb->min->x
                        || $centerZ - $r >= $aabb->max->z || $centerZ + $r <= $aabb->min->z) {
                        continue;
                    }
                    if ($top < $aabb->max->y && $top + $h > $aabb->min->y) {
                        $blocked = true;
                        break;
                    }
                }

                if (!$blocked) {
                    $bestDist = $dist;
                    $best = new Vec3($centerX, $top, $centerZ);
                }
            }
        }

        return $best ?? new Vec3(0, 1, 0);
    }

    /**
     * Splits a "cx,cz" cell key back into grid coordinates.
     *
     * @return array{0: int, 1: int}
     */
    private static function parseCellKey(string $key) : array
    {
        $parts = explode(',', $key);
        return [(int) $parts[0], (int) $parts[1]];
    }
}
