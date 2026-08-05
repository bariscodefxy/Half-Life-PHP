<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Game\PlayerMovement;
use App\Game\TriangleCollisionWorld;

function rampQuad(float $x0, float $z0, float $y0, float $x1, float $z1, float $y1) : array
{
    return [
        $x0, $y0, $z0, $x1, $y1, $z1, $x1, $y0, $z0,
        $x0, $y0, $z0, $x0, $y1, $z1, $x1, $y1, $z1,
    ];
}

function floorQuad(float $x0, float $z0, float $x1, float $z1, float $y) : array
{
    return [
        $x0, $y, $z0, $x1, $y, $z1, $x1, $y, $z0,
        $x0, $y, $z0, $x0, $y, $z1, $x1, $y, $z1,
    ];
}

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

$world = makeWorld([
    floorQuad(-4, -4, 4, 0, 0),
    rampQuad(-4, 0, 0.0, 4, 2, 1.5),
    floorQuad(-4, 2, 4, 6, 1.5),
]);

$dt = 1.0 / 60.0;
$m = new PlayerMovement;
$m->setTriangleWorld($world);
$m->teleport(0, 0, -2);
for ($i = 0; $i < 16; $i++) {
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
echo "approach: z={$m->z} y={$m->y}\n";
$m->jump();

$rc = new ReflectionClass(PlayerMovement::class);
$cands = $rc->getProperty('triCandidates');
$cands->setAccessible(true);

for ($i = 0; $i < 60; $i++) {
    $prevY = $m->y;
    $m->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);

    // replicate triRayDown from the previous feet height
    $dist = $prevY - $m->y + 0.05;
    $hit = null;
    $nyAbove = 0;
    $rayFound = null;
    $pts = $cands->getValue($m);
    foreach ($pts as $off) {
        $tri = $world->vertexTriple($off);
        $ny = triNormalY($tri);
        if ($ny <= 0.5) {
            continue;
        }
        $nyAbove++;
        $t = rayTri($tri, $m->x, $prevY, $m->z, 0.0, -1.0, 0.0, $dist);
        if ($t !== null) {
            $rayFound = $t;
            $hit = $t;
            break;
        }
    }

    if ($m->velY < 0 && $m->y < 2.5) {
        printf(
            "tick %d z=%.3f y=%.3f velY=%+.3f cand=%d walk=%d rayDist=%s\n",
            $i,
            $m->z,
            $m->y,
            $m->velY,
            count($pts),
            $nyAbove,
            $rayFound === null ? 'MISS' : number_format($rayFound, 4),
        );
    }
    if ($m->onGround || $m->y < -0.05) {
        printf("END tick %d z=%.3f y=%.3f onGround=%s\n", $i, $m->z, $m->y, var_export($m->onGround, true));
        break;
    }
}

function triNormalY(array $tri) : float
{
    $u1 = $tri[3] - $tri[0];
    $u2 = $tri[4] - $tri[1];
    $u3 = $tri[5] - $tri[2];
    $v1 = $tri[6] - $tri[0];
    $v2 = $tri[7] - $tri[1];
    $v3 = $tri[8] - $tri[2];
    $nx = $u2 * $v3 - $u3 * $v2;
    $ny = $u3 * $v1 - $u1 * $v3;
    $nz = $u1 * $v2 - $u2 * $v1;
    $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
    return $len < 1e-12 ? 0.0 : $ny / $len;
}

function rayTri(array $tri, float $ox, float $oy, float $oz, float $dx, float $dy, float $dz, float $maxDist) : ?float
{
    [$ax, $ay, $az, $bx, $by, $bz, $cx, $cy, $cz] = $tri;
    $u1 = $bx - $ax;
    $u2 = $by - $ay;
    $u3 = $bz - $az;
    $v1 = $cx - $ax;
    $v2 = $cy - $ay;
    $v3 = $cz - $az;
    $nx = $u2 * $v3 - $u3 * $v2;
    $ny = $u3 * $v1 - $u1 * $v3;
    $nz = $u1 * $v2 - $u2 * $v1;
    $len = sqrt($nx * $nx + $ny * $ny + $nz * $nz);
    if ($len < 1e-12) {
        return null;
    }
    $nx /= $len;
    $ny /= $len;
    $nz /= $len;
    $dot = $nx * $dx + $ny * $dy + $nz * $dz;
    if ($dot >= -1e-9) {
        return null;
    }
    $t = -($nx * ($ox - $ax) + $ny * ($oy - $ay) + $nz * ($oz - $az)) / $dot;
    if ($t < -1e-4 || $t > $maxDist) {
        return null;
    }
    $ix = $ox + $t * $dx;
    $iy = $oy + $t * $dy;
    $iz = $oz + $t * $dz;
    $wx = $ix - $ax;
    $wy = $iy - $ay;
    $wz = $iz - $az;
    $uuv = $u1 * $u1 + $u2 * $u2 + $u3 * $u3;
    $vvw = $v1 * $v1 + $v2 * $v2 + $v3 * $v3;
    $uvw = $u1 * $v1 + $u2 * $v2 + $u3 * $v3;
    $duw = $u1 * $wx + $u2 * $wy + $u3 * $wz;
    $dvw = $v1 * $wx + $v2 * $wy + $v3 * $wz;
    $denom = $uuv * $vvw - $uvw * $uvw;
    if ($denom < 1e-12) {
        return null;
    }
    $beta = ($vvw * $duw - $uvw * $dvw) / $denom;
    $gamma = ($uuv * $dvw - $uvw * $duw) / $denom;
    if ($beta < -1e-6 || $gamma < -1e-6 || $beta + $gamma > 1.0 + 1e-6) {
        return null;
    }
    return $t;
}
