<?php

declare(strict_types=1);

/**
 * Standalone verification of triangle-accurate PlayerMovement collision.
 *
 * The world is built from quads (2 triangles each) with the correct winding:
 * floor quads face up (+Y normal, walkable), vertical quads face sideways
 * (blockers) and ceiling quads face down (block jumping).
 *
 * Run with: php tests/triangle_collision.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Game/PlayerMovement.php';
require __DIR__ . '/../src/Game/TriangleCollisionWorld.php';

use App\Game\PlayerMovement;
use App\Game\TriangleCollisionWorld;

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

/**
 * Two upward-facing triangles forming a horizontal quad at height $y.
 * Corners (x0,z0) and (x1,z1), CCW when viewed from above.
 */
function floorQuad(float $x0, float $z0, float $x1, float $z1, float $y) : array
{
    return [
        $x0, $y, $z0, $x1, $y, $z1, $x1, $y, $z0,
        $x0, $y, $z0, $x0, $y, $z1, $x1, $y, $z1,
    ];
}

/**
 * A vertical blocker quad at constant z spanning y 0..$yTop.
 */
function wallQuad(float $x0, float $z, float $x1, float $yTop) : array
{
    return [
        $x0, 0, $z, $x1, 0, $z, $x1, $yTop, $z,
        $x0, 0, $z, $x1, $yTop, $z, $x0, $yTop, $z,
    ];
}

/**
 * An upward facing ramp quad from (x, y0, z0) to (x, y1, z1) rising in +z.
 */
function rampQuad(float $x0, float $z0, float $y0, float $x1, float $z1, float $y1) : array
{
    return [
        $x0, $y0, $z0, $x1, $y1, $z1, $x1, $y0, $z0,
        $x0, $y0, $z0, $x0, $y1, $z1, $x1, $y1, $z1,
    ];
}

/**
 * A downward facing ceiling quad (normal -Y).
 */
function ceilingQuad(float $x0, float $z0, float $x1, float $z1, float $y) : array
{
    return [
        $x0, $y, $z0, $x1, $y, $z0, $x1, $y, $z1,
        $x0, $y, $z0, $x0, $y, $z1, $x1, $y, $z1,
    ];
}

/**
 * Builds a TriangleCollisionWorld from an array of quads.
 */
function makeWorld(array $quads) : TriangleCollisionWorld
{
    $positions = [];
    $indices = [];
    foreach ($quads as $verts) {
        $base = intdiv(count($positions), 3);
        foreach ($verts as $v) {
            $positions[] = $v;
        }
        $indices[] = $base;
        $indices[] = $base + 1;
        $indices[] = $base + 2;
        $indices[] = $base + 3;
        $indices[] = $base + 4;
        $indices[] = $base + 5;
    }
    return new TriangleCollisionWorld($positions, $indices);
}

$dt = 1.0 / 60.0;

// 1. flat floor + wall: the player runs +Z and is stopped by the wall
$floorWorld = makeWorld([
    floorQuad(-5, -5, 5, 5, 0),
    wallQuad(-5, 3, 5, 3.0),
]);
$m = new PlayerMovement;
$m->setTriangleWorld($floorWorld);
$m->teleport(0, 0, -1);
for ($i = 0; $i < 900; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check(
    'wall blocks the player on flat ground',
    abs($m->z - 2.75) < 0.3 && abs($m->y) < 0.01 && $m->onGround,
    'y=' . round($m->y, 3) . ' z=' . round($m->z, 3),
);

// 2. ramp: rising 2m over 4m, the player walks up it smoothly
$rampWorld = makeWorld([
    floorQuad(-5, -6, 5, -2, 0),
    rampQuad(-5, -2, 0.0, 5, 2, 2.0),
    floorQuad(-5, 2, 5, 6, 2),
    wallQuad(-5, 6, 5, 4.0),
]);
$m = new PlayerMovement;
$m->setTriangleWorld($rampWorld);
$m->teleport(0, 0, -4);
$maxY = 0.0;
for ($i = 0; $i < 900; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
    $maxY = max($maxY, $m->y);
}
check(
    'walks up the ramp to the landing',
    abs($m->y - 2.0) < 0.05 && abs($m->z - 5.75) < 0.3 && $m->onGround,
    'y=' . round($m->y, 3) . ' z=' . round($m->z, 3),
);

// 3. stairs: two 0.3m risers are stepped up automatically
$stairWorld = makeWorld([
    floorQuad(-2, -3, 2, 0, 0),
    wallQuad(-2, 0, 2, 0.3),
    floorQuad(-2, 0, 2, 1, 0.3),
    wallQuad(-2, 1, 2, 0.6),
    floorQuad(-2, 1, 2, 6, 0.6),
    wallQuad(-2, 6, 2, 3.0),
]);
$m = new PlayerMovement;
$m->setTriangleWorld($stairWorld);
$m->teleport(0, 0, -2);
for ($i = 0; $i < 900; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check(
    'climbs the stairs to the top landing',
    abs($m->y - 0.6) < 0.05 && abs($m->z - 5.75) < 0.3 && $m->onGround,
    'y=' . round($m->y, 3) . ' z=' . round($m->z, 3),
);

// 4. a single step taller than STEP_HEIGHT still blocks
$highStepWorld = makeWorld([
    floorQuad(-2, -3, 2, 0, 0),
    floorQuad(-2, 0, 2, 1, 0.8),
]);
$m = new PlayerMovement;
$m->setTriangleWorld($highStepWorld);
$m->teleport(0, 0, -2);
for ($i = 0; $i < 900; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check(
    'a step taller than STEP_HEIGHT still blocks',
    abs($m->z + 0.25) < 0.1 && $m->y < 0.01,
    'y=' . round($m->y, 3) . ' z=' . round($m->z, 3),
);

// 5. jump apex and landing on a triangle floor
$m = new PlayerMovement;
$m->setTriangleWorld($floorWorld);
$m->teleport(0, 0, 0);
for ($i = 0; $i < 5; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
}
check('triangle floor keeps the player grounded', $m->onGround, 'y=' . round($m->y, 3));
$m->jump();
$maxY = 0.0;
for ($i = 0; $i < 120; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
    $maxY = max($maxY, $m->y);
}
check('jump apex ~1.25 on triangles', abs($maxY - 1.25) < 0.15, 'apex=' . round($maxY, 3));
for ($i = 0; $i < 120; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
}
check('lands back on the triangle floor', $m->onGround && abs($m->y) < 0.01, 'y=' . round($m->y, 3));

// 6. low ceiling stops the jump at head height (y ~ 0.3 with a 2.0 ceiling)
$ceilingWorld = makeWorld([
    floorQuad(-5, -5, 5, 5, 0),
    ceilingQuad(-5, -5, 5, 5, 2.0),
]);
$m = new PlayerMovement;
$m->setTriangleWorld($ceilingWorld);
$m->teleport(0, 0, 0);
for ($i = 0; $i < 5; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
}
$m->jump();
$maxY = 0.0;
for ($i = 0; $i < 120; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
    $maxY = max($maxY, $m->y);
}
check(
    'ceiling blocks the jump at head height',
    abs($maxY - 0.3) < 0.05,
    'apex=' . round($maxY, 3),
);

// 7. low doorway: a standing player is blocked by the lintel, a crouched
// player passes under it, and standing up under the lintel is refused
$lintelWorld = makeWorld([
    floorQuad(-5, -3, 5, 4, 0),
    ceilingQuad(-5, 0, 5, 1, 1.0),
    wallQuad(-5, 4, 5, 3.0),
]);
$m = new PlayerMovement;
$m->setTriangleWorld($lintelWorld);
$m->teleport(0, 0, -2);
for ($i = 0; $i < 900; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check(
    'low lintel blocks a standing player',
    abs($m->z + 0.25) < 0.1 && abs($m->y) < 0.01,
    'y=' . round($m->y, 3) . ' z=' . round($m->z, 3),
);

$m = new PlayerMovement;
$m->setTriangleWorld($lintelWorld);
$m->teleport(0, 0, -2);
$m->setCrouching(true);
for ($i = 0; $i < 900; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::WALK_SPEED, $dt);
}
check(
    'crouched player passes under the lintel',
    $m->z > 3.5 && abs($m->y) < 0.01 && $m->onGround,
    'y=' . round($m->y, 3) . ' z=' . round($m->z, 3),
);

$m->setCrouching(true);
$m->teleport(0, 0, 0.5);
$m->setCrouching(false);
check(
    'cannot stand up under the lintel',
    $m->isCrouching(),
    'crouching=' . var_export($m->isCrouching(), true),
);

// 8. jumping into a rising ramp must not tunnel the feet through it: the ramp
// surface outruns the jump arc, so the feet would end up inside the slope and
// fall through into the void on the way down
$jumpRampWorld = makeWorld([
    floorQuad(-4, -4, 4, 0, 0),
    rampQuad(-4, 0, 0.0, 4, 2, 1.5),
    floorQuad(-4, 2, 4, 6, 1.5),
]);
$m = new PlayerMovement;
$m->setTriangleWorld($jumpRampWorld);
$m->teleport(0, 0, -2);
for ($i = 0; $i < 16; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
$m->jump();
$rampTunneled = false;
$landedOnRamp = false;
for ($i = 0; $i < 120; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
    if ($m->z > 0.0 && $m->z < 2.0) {
        if ($m->y < 0.75 * $m->z - 0.05) {
            $rampTunneled = true;
        }
        if ($m->onGround) {
            $landedOnRamp = true;
        }
    }
}
check(
    'jumping into the ramp lands on it instead of tunneling',
    $landedOnRamp && !$rampTunneled,
    'landedOnRamp=' . var_export($landedOnRamp, true) . ' tunneled=' . var_export($rampTunneled, true),
);

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) failed\n";
    exit(1);
}
echo "All checks passed\n";
