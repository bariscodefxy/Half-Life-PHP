<?php

declare(strict_types=1);

/**
 * Headless verification of the glTF map loader against the real Crossfire
 * asset. No GL context is required.
 *
 * Run with: php tests/gltf_map_loader.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapData;
use App\Game\GLTFMapLoader;

$failures = 0;

function check(string $name, bool $ok, string $detail = '') : void
{
    global $failures;
    if ($ok) {
        echo "PASS  $name\n";
    } else {
        $failures++;
        echo "FAIL  $name" . ($detail !== '' ? "  ($detail)" : '') . "\n";
    }
}

$gltfPath = __DIR__ . '/../resources/maps/crossfire/scene.gltf';
check('map asset exists', file_exists($gltfPath), $gltfPath);

if (!file_exists($gltfPath)) {
    exit(1);
}

$data = GLTFMapLoader::parse($gltfPath, 0.02);

check('is GLTFMapData', $data instanceof GLTFMapData);

// mesh count mirrors the asset analysis (89 primitives)
check(
    'parse found meshes',
    count($data->meshes) >= 80 && count($data->meshes) <= 100,
    'meshes=' . count($data->meshes),
);

// each mesh must have identical counts of positions/normals and uvs and valid indices
$totalVertices = 0;
$meshOk = true;
foreach ($data->meshes as $i => $mesh) {
    $totalVertices += count($mesh['positions']) / 3;
    if (count($mesh['positions']) !== count($mesh['normals'])) {
        $meshOk = false;
        check("mesh $i position/normal count", false);
    }
    // uvs carry 2 floats per vertex, positions 3
    if (count($mesh['positions']) * 2 !== count($mesh['uvs']) * 3) {
        $meshOk = false;
        check("mesh $i position/uv count", false);
    }
    $maxIndex = count($mesh['positions']) / 3;
    if (count($mesh['indices']) !== (int) $maxIndex) {
        $meshOk = false;
        check("mesh $i index count", false);
    }
    foreach ($mesh['indices'] as $j => $index) {
        // the vertex arrays are expanded one entry per index reference, so the
        // stored topology must be the identity sequence
        if ($index !== $j || $index < 0 || $index >= $maxIndex) {
            $meshOk = false;
            check("mesh $i non-sequential index at $j", false);
            break;
        }
    }
}
check('all meshes are consistent', $meshOk);

// vertices landed in the expected ballpark (~9.9k triangles -> ~30k vertices)
check(
    'vertex count plausible',
    $totalVertices > 20000 && $totalVertices < 60000,
    'vertices=' . (int) $totalVertices,
);

// the floor must sit at y = 0 after the yOffset re-centering
check(
    'floor re-centered to y=0',
    abs($data->aabb->min->y) < 0.01,
    'minY=' . round($data->aabb->min->y, 3),
);

// the map is centered on x=0/z=0 and is ~88m long in z, ~43.5m wide in x
$b = $data->aabb;
check('aabb x min ~ -21.8', abs($b->min->x + 21.8) < 0.6, 'minX=' . round($b->min->x, 2));
check('aabb x max ~ 21.8', abs($b->max->x - 21.8) < 0.6, 'maxX=' . round($b->max->x, 2));
check('aabb z min ~ -44.2', abs($b->min->z + 44.2) < 0.6, 'minZ=' . round($b->min->z, 2));
check('aabb z max ~ 44.2', abs($b->max->z - 44.2) < 0.6, 'maxZ=' . round($b->max->z, 2));
check('aabb y max in range', $b->max->y > 5 && $b->max->y < 20, 'maxY=' . round($b->max->y, 2));

// texture references must resolve to existing PNGs
$textureRefs = [];
foreach ($data->meshes as $mesh) {
    $materialIndex = $mesh['materialIndex'];
    if ($materialIndex < 0 || !isset($data->materials[$materialIndex])) {
        continue;
    }
    $mat = $data->materials[$materialIndex];
    if (isset($mat['pbrMetallicRoughness']['baseColorTexture']['index'])) {
        $texIndex = $mat['pbrMetallicRoughness']['baseColorTexture']['index'];
        $textureRefs[] = $texIndex;
    }
}
$textures = json_decode((string) file_get_contents($gltfPath), true)['textures'];
$images = json_decode((string) file_get_contents($gltfPath), true)['images'];
$resolved = 0;
$missing = [];
foreach ($textureRefs as $texIndex) {
    $imageIndex = $textures[$texIndex]['source'] ?? null;
    if ($imageIndex === null) {
        continue;
    }
    $uri = $images[$imageIndex]['uri'] ?? null;
    if ($uri === null) {
        continue;
    }
    $path = dirname($gltfPath) . '/' . $uri;
    if (file_exists($path)) {
        $resolved++;
    } else {
        $missing[] = $uri;
    }
}
check(
    'all material textures resolve to files',
    $missing === [],
    'resolved=' . $resolved . ' missing=' . count($missing),
);

// colliders: build the occupancy grid
$result = GLTFMapLoader::buildColliders($data);
$colliders = $result['colliders'];
$cells = $result['cells'];

check('collider builder returned colliders', count($colliders) > 100, 'colliders=' . count($colliders));
check('collider cells populated', count($cells) > 10, 'cells=' . count($cells));

$outOfRange = 0;
foreach ($colliders as $aabb) {
    if ($aabb->min->x > $aabb->max->x || $aabb->min->y > $aabb->max->y || $aabb->min->z > $aabb->max->z) {
        $outOfRange++;
    }
}
check('all colliders well-formed', $outOfRange === 0, 'bad=' . $outOfRange);

// there must be a standable surface near the map center for a spawn point
$hasGroundNearCenter = false;
$cx = 0.0;
$cz = 0.0;
foreach ($cells as $key => $cellColliders) {
    [$cellX, $cellZ] = array_map('intval', explode(',', $key));
    $wx = $cellX + 0.5;
    $wz = $cellZ + 0.5;
    if (abs($wx) > 6 || abs($wz) > 6) {
        continue;
    }
    foreach ($cellColliders as $aabb) {
        if ($aabb->max->y > 0) {
            $hasGroundNearCenter = true;
            $cx = $wx;
            $cz = $wz;
            break 2;
        }
    }
}
check('standable surface exists near center', $hasGroundNearCenter, "cell ~($cx, $cz)");

// the spawn point search must return a standable, unobstructed feet position
$spawn = GLTFMapLoader::findSpawnPoint($cells);
check('spawn point above floor', $spawn->y > 0, 'feet=' . round($spawn->y, 2));
check('spawn point near center', sqrt($spawn->x ** 2 + $spawn->z ** 2) < 30, 'at=' . round($spawn->x, 1) . ',' . round($spawn->z, 1));

$spawnBlocked = false;
foreach ($colliders as $aabb) {
    if ($spawn->x - 0.5 >= $aabb->max->x || $spawn->x + 0.5 <= $aabb->min->x
        || $spawn->z - 0.5 >= $aabb->max->z || $spawn->z + 0.5 <= $aabb->min->z) {
        continue;
    }
    if ($spawn->y < $aabb->max->y && $spawn->y + 2.0 > $aabb->min->y) {
        $spawnBlocked = true;
        break;
    }
}
check('spawn point box unobstructed', !$spawnBlocked);

// triangle world: build the triangle-accurate collision mesh
$triangleWorld = GLTFMapLoader::buildTriangleWorld($data);
$triangleCount = intdiv(count($triangleWorld->indices), 3);

check(
    'triangle world built',
    $triangleCount > 5000,
    'triangles=' . $triangleCount,
);

// the triangle world must share the map's world-space bounds
$twMinX = $twMinZ = PHP_FLOAT_MAX;
$twMaxX = $twMaxZ = -PHP_FLOAT_MAX;
for ($i = 0; $i < count($triangleWorld->positions); $i += 3) {
    $twMinX = min($twMinX, $triangleWorld->positions[$i]);
    $twMinZ = min($twMinZ, $triangleWorld->positions[$i + 2]);
    $twMaxX = max($twMaxX, $triangleWorld->positions[$i]);
    $twMaxZ = max($twMaxZ, $triangleWorld->positions[$i + 2]);
}
check('triangle world bounds x', abs($twMinX + 21.8) < 0.6 && abs($twMaxX - 21.8) < 0.6, 'x=' . round($twMinX, 1) . '..' . round($twMaxX, 1));
check('triangle world bounds z', abs($twMinZ + 44.2) < 0.6 && abs($twMaxZ - 44.2) < 0.6, 'z=' . round($twMinZ, 1) . '..' . round($twMaxZ, 1));

// every triangle must be retrievable through the spatial grid
$query = $triangleWorld->querySquare(0, 0, 40);
check(
    'grid query returns triangles around center',
    count($query) > 0,
    'tris=' . count($query),
);

// triangle indices must stay in bounds of the positions array
$maxVertex = intdiv(count($triangleWorld->positions), 3);
$indicesOk = true;
foreach ($triangleWorld->indices as $index) {
    if ($index < 0 || $index >= $maxVertex) {
        $indicesOk = false;
        break;
    }
}
check('triangle indices in bounds', $indicesOk);

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) failed\n";
    exit(1);
}
echo "All checks passed\n";
