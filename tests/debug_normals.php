<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);

$buckets = [];
$minYOfFloor = PHP_FLOAT_MAX;
$sampleFloor = [];

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

        $bucket = (int) floor($nyN * 10) / 10;
        $buckets[$bucket] = ($buckets[$bucket] ?? 0) + 1;

        if ($nyN >= 0.866) {
            $minY = min($ay, $by, $cy);
            $maxY = max($ay, $by, $cy);
            $minYOfFloor = min($minYOfFloor, $minY);
            if (count($sampleFloor) < 10 && $minY < 0.5) {
                $sampleFloor[] = "top=" . round($maxY, 3) . " span=" . round($maxY - $minY, 3) . " ny=" . round($ny / $len, 3);
            }
        }
    }
}

krsort($buckets);
foreach ($buckets as $k => $count) {
    echo "ny bucket " . number_format($k, 1) . ".." . number_format($k + 0.1, 1) . ": $count\n";
}
echo "\nlowest horizontal-triangle y: " . round($minYOfFloor, 3) . "\n";
echo "sample horizontal tris near ground:\n" . implode("\n", $sampleFloor) . "\n";
