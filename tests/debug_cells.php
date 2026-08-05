<?php

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);

$targets = [[0, 0], [0, 5], [2, 2], [10, 10]];

foreach ($targets as [$tx, $tz]) {
    echo "=== cell ($tx, $tz) ===\n";
    $lines = [];
    foreach ($data->meshes as $mi => $meshData) {
        $positions = $meshData['positions'];
        $indices = $meshData['indices'];
        for ($t = 0; $t < count($indices); $t += 3) {
            $i0 = $indices[$t] * 3;
            $i1 = $indices[$t + 1] * 3;
            $i2 = $indices[$t + 2] * 3;
            $ax = $positions[$i0]; $ay = $positions[$i0 + 1]; $az = $positions[$i0 + 2];
            $bx = $positions[$i1]; $by = $positions[$i1 + 1]; $bz = $positions[$i1 + 2];
            $cx = $positions[$i2]; $cy = $positions[$i2 + 1]; $cz = $positions[$i2 + 2];

            $minX = min($ax, $bx, $cx); $maxX = max($ax, $bx, $cx);
            $minZ = min($az, $bz, $cz); $maxZ = max($az, $bz, $cz);
            if ($maxX < $tx || $minX >= $tx + 1 || $maxZ < $tz || $minZ >= $tz + 1) {
                continue;
            }

            $u1 = $bx - $ax; $u2 = $by - $ay; $u3 = $bz - $az;
            $v1 = $cx - $ax; $v2 = $cy - $ay; $v3 = $cz - $az;
            $nx = $u2 * $v3 - $u3 * $v2;
            $ny = $u3 * $v1 - $u1 * $v3;
            $nz = $u1 * $v2 - $u2 * $v1;
            $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
            $nyN = $len > 1e-9 ? abs($ny) / $len : 0;

            $minY = min($ay, $by, $cy); $maxY = max($ay, $by, $cy);
            $lines[] = sprintf(
                "m%d y[%.2f,%.2f] %s",
                $mi,
                $minY,
                $maxY,
                $nyN >= 0.866 ? 'FLOOR' : ($nyN < 0.5 ? 'WALL' : 'SLOPE')
            );
        }
    }
    sort($lines);
    $lines = array_values(array_unique($lines));
    foreach ($lines as $line) {
        echo "  $line\n";
    }
    echo "\n";
}
