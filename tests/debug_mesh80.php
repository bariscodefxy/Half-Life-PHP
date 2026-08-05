<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);

$mesh = null;
$meshIndex = null;
foreach ($data->meshes as $mi => $m) {
    if ($m['name'] === 'mesh_80' || str_contains($m['name'], '80')) {
        $mesh = $m;
        $meshIndex = $mi;
        break;
    }
}
if ($mesh === null) {
    echo "mesh 80 not found; names sample: ";
    foreach ($data->meshes as $mi => $m) { echo "{$mi}:{$m['name']} "; }
    echo "\n";
    exit(1);
}
echo "mesh index: $meshIndex name: {$mesh['name']} triangles: " . (int)(count($mesh['indices']) / 3) . "\n";

$positions = $mesh['positions'];
$normals = $mesh['normals'];
$indices = $mesh['indices'];

$minY = 1e9; $maxY = -1e9;
$samples = [];
for ($t = 0; $t < count($indices) && count($samples) < 8; $t += 3) {
    $i0 = $indices[$t] * 3;
    $i1 = $indices[$t + 1] * 3;
    $i2 = $indices[$t + 2] * 3;
    $ys = [$positions[$i0 + 1], $positions[$i1 + 1], $positions[$i2 + 1]];
    $span = max($ys) - min($ys);
    if ($span > 0.5) {
        $samples[] = sprintf(
            "  tri%d span=%.2f pos=(%.2f,%.2f,%.2f)(%.2f,%.2f,%.2f)(%.2f,%.2f,%.2f) normal=(%.2f,%.2f,%.2f)(%.2f,%.2f,%.2f)(%.2f,%.2f,%.2f)",
            $t / 3, $span,
            $positions[$i0], $positions[$i0 + 1], $positions[$i0 + 2],
            $positions[$i1], $positions[$i1 + 1], $positions[$i1 + 2],
            $positions[$i2], $positions[$i2 + 1], $positions[$i2 + 2],
            $normals[$i0], $normals[$i0 + 1], $normals[$i0 + 2],
            $normals[$i1], $normals[$i1 + 1], $normals[$i1 + 2],
            $normals[$i2], $normals[$i2 + 1], $normals[$i2 + 2]
        );
    }
    foreach ($ys as $y) { $minY = min($minY, $y); $maxY = max($maxY, $y); }
}
echo "mesh Y range: " . round($minY, 2) . ".." . round($maxY, 2) . "\n";
echo "triangles with vertical span > 0.5 (sample):\n" . implode("\n", $samples) . "\n";
