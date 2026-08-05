<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);

$heightCounts = [];
$groundTris = 0;
foreach ($data->meshes as $meshData) {
    $positions = $meshData['positions'];
    $indices = $meshData['indices'];
    for ($t = 0; $t < count($indices); $t += 3) {
        $i0 = $indices[$t] * 3;
        $i1 = $indices[$t + 1] * 3;
        $i2 = $indices[$t + 2] * 3;

        $ax = $positions[$i0]; $ay = $positions[$i0 + 1]; $az = $positions[$i0 + 2];
        $bx = $positions[$i1]; $by = $positions[$i1 + 1]; $bz = $positions[$i1 + 2];
        $cx = $positions[$i2]; $cy = $positions[$i2 + 1]; $cz = $positions[$i2 + 2];

        $u1 = $bx - $ax; $u2 = $by - $ay; $u3 = $bz - $az;
        $v1 = $cx - $ax; $v2 = $cy - $ay; $v3 = $cz - $az;
        $nx = $u2 * $v3 - $u3 * $v2;
        $ny = $u3 * $v1 - $u1 * $v3;
        $nz = $u1 * $v2 - $u2 * $v1;
        $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
        if ($len < 1e-9) continue;
        $nyN = abs($ny) / $len;
        if ($nyN < 0.866) continue;

        $minY = min($ay, $by, $cy);
        if ($minY < 0.5) $groundTris++;
        $h = (int) floor($minY);
        $heightCounts[$h] = ($heightCounts[$h] ?? 0) + 1;
    }
}
ksort($heightCounts);
$total = array_sum($heightCounts);
echo "horizontal tris below 0.5m: $groundTris of $total\n\n";
echo "minY -> triangle count (top 25 heights):\n";
$i = 0;
foreach ($heightCounts as $h => $count) {
    echo "  y=$h.." . ($h + 1) . ": $count\n";
    if (++$i >= 25) break;
}
