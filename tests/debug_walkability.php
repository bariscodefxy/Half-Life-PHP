<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;
use App\Game\PlayerMovement;
use GL\Math\Vec3;
use VISU\Geo\AABB;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);
$result = GLTFMapLoader::buildColliders($data);
$cells = $result['cells'];

$r = PlayerMovement::PLAYER_RADIUS;
$h = PlayerMovement::PLAYER_HEIGHT;

// For each cell, find a standable height: the highest collider top <= 5m that
// gives the player box headroom without intersecting any collider.
$standable = 0;
$completelySolid = 0;
$total = 0;

$adjacentKeys = [];
for ($dx = -1; $dx <= 1; $dx++) {
    for ($dz = -1; $dz <= 1; $dz++) {
        $adjacentKeys[] = [$dx, $dz];
    }
}

foreach ($cells as $key => $colliders) {
    $total++;
    [$cx, $cz] = array_map('intval', explode(',', $key));
    $centerX = $cx + 0.5;
    $centerZ = $cz + 0.5;

    $neighborColliders = [];
    foreach ($adjacentKeys as [$dx, $dz]) {
        $nk = ($cx + $dx) . ',' . ($cz + $dz);
        if (isset($cells[$nk])) {
            foreach ($cells[$nk] as $aabb) {
                $neighborColliders[] = $aabb;
            }
        }
    }

    $found = false;
    $tops = [];
    foreach ($colliders as $aabb) {
        $tops[] = $aabb->max->y;
    }
    rsort($tops);
    foreach ($tops as $top) {
        $feet = $top;
        $blocked = false;
        foreach ($neighborColliders as $aabb) {
            if ($centerX - $r >= $aabb->max->x || $centerX + $r <= $aabb->min->x
                || $centerZ - $r >= $aabb->max->z || $centerZ + $r <= $aabb->min->z) {
                continue;
            }
            if ($feet < $aabb->max->y && $feet + $h > $aabb->min->y) {
                $blocked = true;
                break;
            }
        }
        if (!$blocked) {
            $found = true;
            break;
        }
    }
    if ($found) {
        $standable++;
    } else {
        $completelySolid++;
    }
}

echo "total cells: $total\n";
echo "standable: $standable (" . round(100 * $standable / $total) . "%)\n";
echo "solid/blocked: $completelySolid\n";
echo "colliders: " . count($result['colliders']) . "\n";

// verify the spawn search logic: pick cell nearest center that is standable
$best = null;
$bestDist = PHP_FLOAT_MAX;
foreach ($cells as $key => $colliders) {
    [$cx, $cz] = array_map('intval', explode(',', $key));
    $dist = $cx * $cx + $cz * $cz;
    if ($dist >= $bestDist) continue;
    $centerX = $cx + 0.5;
    $centerZ = $cz + 0.5;
    $neighborColliders = [];
    foreach ($adjacentKeys as [$dx, $dz]) {
        $nk = ($cx + $dx) . ',' . ($cz + $dz);
        if (isset($cells[$nk])) foreach ($cells[$nk] as $aabb) $neighborColliders[] = $aabb;
    }
    $tops = [];
    foreach ($colliders as $aabb) $tops[] = $aabb->max->y;
    rsort($tops);
    foreach ($tops as $top) {
        $blocked = false;
        foreach ($neighborColliders as $aabb) {
            if ($centerX - $r >= $aabb->max->x || $centerX + $r <= $aabb->min->x
                || $centerZ - $r >= $aabb->max->z || $centerZ + $r <= $aabb->min->z) continue;
            if ($top < $aabb->max->y && $top + $h > $aabb->min->y) { $blocked = true; break; }
        }
        if (!$blocked) {
            $bestDist = $dist;
            $best = [$cx, $cz, $top];
            break;
        }
    }
}
echo "spawn candidate: " . ($best ? "cell($best[0],$best[1]) top=" . round($best[2], 2) : "NONE") . "\n";

