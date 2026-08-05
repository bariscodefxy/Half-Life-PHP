<?php

declare(strict_types=1);

/**
 * Regression suite against the real Crossfire map with triangle-accurate
 * collision: the player must stay grounded at the spawn (no void fall), walk
 * in every cardinal direction without passing through floors, and climb a real
 * ramp instead of being blocked by hidden AABB walls.
 *
 * Run with: php tests/real_map_collision.php
 */

require __DIR__ . '/../vendor/autoload.php';

use App\Game\GLTFMapLoader;
use App\Game\PlayerMovement;

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

$dt = 1.0 / 60.0;

$data = GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);
$result = GLTFMapLoader::buildColliders($data);
$spawn = GLTFMapLoader::findSpawnPoint($result['cells']);
$world = GLTFMapLoader::buildTriangleWorld($data);

// 1. spawning on the real floor must not fall through it (void-fall guard:
// the spawn feet height is float-rounded a hair below the floor plane)
$m = new PlayerMovement;
$m->setTriangleWorld($world);
$m->teleport($spawn->x, $spawn->y, $spawn->z);
$groundedTicks = 0;
$minY = $m->y;
for ($i = 0; $i < 240; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
    $minY = min($minY, $m->y);
    if ($m->onGround) {
        $groundedTicks++;
    }
}
check(
    'player stays grounded at the spawn',
    $groundedTicks > 230 && $minY >= $spawn->y - 0.001,
    'groundedTicks=' . $groundedTicks . ' minY=' . round($minY, 4),
);

// 2. walking in each cardinal direction: no void fall, mostly grounded, no
// steps taller than STEP_HEIGHT (no hidden AABB staircases)
$dirs = [
    '+X' => [1.0, 0.0],
    '-X' => [-1.0, 0.0],
    '+Z' => [0.0, 1.0],
    '-Z' => [0.0, -1.0],
];
foreach ($dirs as $name => [$wx, $wz]) {
    $m = new PlayerMovement;
    $m->setTriangleWorld($world);
    $m->teleport($spawn->x, $spawn->y, $spawn->z);
    $minY = $m->y;
    $grounded = 0;
    $maxStep = 0.0;
    $prevY = $m->y;
    for ($i = 0; $i < 360; $i++) {
        $m->update($wx, $wz, PlayerMovement::RUN_SPEED, $dt);
        $minY = min($minY, $m->y);
        $maxStep = max($maxStep, $m->y - $prevY);
        $prevY = $m->y;
        if ($m->onGround) {
            $grounded++;
        }
    }
    check(
        "walks $name without falling through floors",
        $minY >= 0.5 && $grounded > 300 && $maxStep <= PlayerMovement::STEP_HEIGHT + 0.1,
        'minY=' . round($minY, 2) . ' grounded=' . $grounded . '/360 maxStep=' . round($maxStep, 3),
    );
}

// 3. climbing a real ramp (the 9.28 -> 10.56 slope at x 14..11, z ~22): the
// player rises smoothly instead of being stopped by voxelized AABB steps
$m = new PlayerMovement;
$m->setTriangleWorld($world);
$m->teleport(16.0, 14.0, 22.0);
for ($i = 0; $i < 120; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
}
check(
    'drops onto the lower floor of the ramp area',
    $m->onGround && abs($m->y - 9.28) < 0.05,
    'y=' . round($m->y, 3),
);
$maxY = $m->y;
$maxStep = 0.0;
$prevY = $m->y;
for ($i = 0; $i < 900; $i++) {
    $m->update(-1.0, 0.0, PlayerMovement::RUN_SPEED, $dt);
    $maxY = max($maxY, $m->y);
    $maxStep = max($maxStep, $m->y - $prevY);
    $prevY = $m->y;
}
check(
    'climbs the ramp to the upper floor',
    $m->y > 10.3 && $m->onGround && $maxStep <= PlayerMovement::STEP_HEIGHT + 0.1,
    'y=' . round($m->y, 3) . ' maxY=' . round($maxY, 3) . ' maxStep=' . round($maxStep, 3),
);

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) failed\n";
    exit(1);
}
echo "All checks passed\n";
