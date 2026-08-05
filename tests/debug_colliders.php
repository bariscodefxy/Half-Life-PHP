<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);
$result = GLTFMapLoader::buildColliders($data);

$slabByBand = [];
$cellsWithSlab = 0;
$totalCells = 0;
foreach ($result['cells'] as $key => $colliders) {
    $totalCells++;
    [$cx, $cz] = array_map('intval', explode(',', $key));
    foreach ($colliders as $aabb) {
        if (($aabb->max->y - $aabb->min->y) >= 2.0) {
            continue;
        }
        $cellsWithSlab++;
        $band = (int) floor($aabb->max->y);
        $slabByBand[$band] = ($slabByBand[$band] ?? 0) + 1;
        if ($cx === 0 && abs($cz) < 40) {
            echo "x=0 column: cell(0,$cz) slab top=" . round($aabb->max->y, 2) . "\n";
        }
    }
}

echo "\ntotal cells: $totalCells, cells with slab colliders: $cellsWithSlab\n";
ksort($slabByBand);
echo "slab top height band -> cell-slab count:\n";
foreach ($slabByBand as $band => $count) {
    echo "  y=$band.." . ($band + 1) . ": $count\n";
}

// count column colliders (should be the walls)
$cols = 0;
foreach ($result['colliders'] as $aabb) {
    if (($aabb->max->y - $aabb->min->y) >= 2.0) $cols++;
}
echo "column colliders: $cols\n";
