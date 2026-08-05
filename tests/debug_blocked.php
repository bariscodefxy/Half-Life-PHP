<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);
$result = GLTFMapLoader::buildColliders($data);

// For every floor slab, check if a column collider spans across the slab top
// (column.min < slab.top <= column.max). Those slabs are "blocked" - a player
// standing there would be inside the column.
$blocked = 0;
$total = 0;
$blockedExamples = [];
foreach ($result['cells'] as $key => $colliders) {
    $columns = array_values(array_filter($colliders, fn ($a) => ($a->max->y - $a->min->y) >= 2.0));
    $slabs = array_values(array_filter($colliders, fn ($a) => ($a->max->y - $a->min->y) < 2.0));
    foreach ($slabs as $slab) {
        $total++;
        $top = $slab->max->y;
        foreach ($columns as $col) {
            if ($col->min->y < $top && $col->max->y > $top) {
                $blocked++;
                if (count($blockedExamples) < 8) {
                    $blockedExamples[] = "$key slabTop=" . round($top, 2)
                        . " col[" . round($col->min->y, 2) . "," . round($col->max->y, 2) . "]";
                }
                break;
            }
        }
    }
}

echo "floor slabs: $total\n";
echo "blocked by a spanning column: $blocked (" . round(100 * $blocked / max($total, 1)) . "%)\n";
echo implode("\n", $blockedExamples) . "\n";
