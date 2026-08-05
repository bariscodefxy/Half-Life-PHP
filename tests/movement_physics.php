<?php

declare(strict_types=1);

/**
 * Standalone verification of the GoldSrc style PlayerMovement physics.
 *
 * Run with: php tests/movement_physics.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../src/Game/PlayerMovement.php';

use App\Game\PlayerMovement;
use GL\Math\Vec3;
use VISU\Geo\AABB;

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
$floor = new AABB(new Vec3(-100, -10, -100), new Vec3(100, 0, 100));

function newPlayer(AABB $floor) : PlayerMovement
{
    $m = new PlayerMovement;
    $m->setColliders([$floor]);
    $m->teleport(0, 0, 0);
    return $m;
}

// 1. accelerates to run speed quickly from standstill on the ground
$m = newPlayer($floor);
$time = 0.0;
while ($time < 0.3) {
    $m->update(1.0, 0.0, PlayerMovement::RUN_SPEED, $dt);
    $time += $dt;
}
check(
    'accelerates to run speed',
    abs($m->velX - PlayerMovement::RUN_SPEED) < 0.5,
    'velX=' . round($m->velX, 3),
);

// 2. ground friction stops the player when no keys are pressed
$m = newPlayer($floor);
$m->velX = PlayerMovement::RUN_SPEED;
$time = 0.0;
while ($time < 1.5) {
    $m->update(0.0, 0.0, 0.0, $dt);
    $time += $dt;
}
check('friction stops the player', $m->velX < 0.1, 'velX=' . round($m->velX, 4));

// 3. jump apex is roughly the Half-Life 45u jump height scaled (~1.25m)
$m = newPlayer($floor);
$m->update(0.0, 0.0, 0.0, $dt);
$m->update(0.0, 0.0, 0.0, $dt);
check('player is grounded before jumping', $m->onGround);
$m->jump();
$maxY = 0.0;
for ($i = 0; $i < 120; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
    $maxY = max($maxY, $m->y);
}
check('jump apex ~1.25', abs($maxY - 1.25) < 0.15, 'apex=' . round($maxY, 3));

// 4. jumping from a standstill comes back down to the floor
$m = newPlayer($floor);
$m->update(0.0, 0.0, 0.0, $dt);
$m->update(0.0, 0.0, 0.0, $dt);
$m->jump();
for ($i = 0; $i < 120; $i++) {
    $m->update(0.0, 0.0, 0.0, $dt);
}
check('lands on the ground again', $m->onGround, 'y=' . round($m->y, 3));

// 6. walls stop the player on the near side, never let them through
$h = 20.0;
$height = 8.0;
$t = 0.2;
$wallColliders = [
    $floor,
    new AABB(new Vec3(-$h - $t, 0, -$h - $t), new Vec3($h + $t, $height, -$h)),
    new AABB(new Vec3(-$h - $t, 0, $h), new Vec3($h + $t, $height, $h + $t)),
    new AABB(new Vec3(-$h - $t, 0, -$h - $t), new Vec3(-$h, $height, $h + $t)),
    new AABB(new Vec3($h, 0, -$h - $t), new Vec3($h + $t, $height, $h + $t)),
];

$mw = new PlayerMovement;
$mw->setColliders($wallColliders);
$mw->teleport(0, 0, 0);
for ($i = 0; $i < 900; $i++) {
    $mw->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check('south wall blocks +Z', abs($mw->z - 19.5) < 0.5, 'z=' . round($mw->z, 3));

$mw = new PlayerMovement;
$mw->setColliders($wallColliders);
$mw->teleport(0, 0, 0);
for ($i = 0; $i < 900; $i++) {
    $mw->update(1.0, 0.0, PlayerMovement::RUN_SPEED, $dt);
}
check('east wall blocks +X', abs($mw->x - 19.5) < 0.5, 'x=' . round($mw->x, 3));

$mw = new PlayerMovement;
$mw->setColliders($wallColliders);
$mw->teleport(0, 0, 0);
for ($i = 0; $i < 900; $i++) {
    $mw->update(0.0, -1.0, PlayerMovement::RUN_SPEED, $dt);
}
check('north wall blocks -Z', abs($mw->z + 19.5) < 0.5, 'z=' . round($mw->z, 3));

$mw = new PlayerMovement;
$mw->setColliders($wallColliders);
$mw->teleport(0, 0, 0);
for ($i = 0; $i < 900; $i++) {
    $mw->update(-1.0, 0.0, PlayerMovement::RUN_SPEED, $dt);
}
check('west wall blocks -X', abs($mw->x + 19.5) < 0.5, 'x=' . round($mw->x, 3));

// 7. stairs: a low staircase is walked up automatically, step by step
$stairs = [$floor];
$rise = 0.3;
for ($i = 1; $i <= 5; $i++) {
    $top = $rise * $i;
    $stairs[] = new AABB(new Vec3(-5, $top - 0.5, $i - 1), new Vec3(5, $top, $i));
}
$stairs[] = new AABB(new Vec3(-5, 1.0, 5), new Vec3(5, 1.5, 8));
$stairs[] = new AABB(new Vec3(-5, 0, 8), new Vec3(5, 8, 9));

$ms = new PlayerMovement;
$ms->setColliders($stairs);
$ms->teleport(0, 0, -2);
for ($i = 0; $i < 900; $i++) {
    $ms->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check(
    'climbs the staircase to the landing',
    abs($ms->y - 1.5) < 0.05 && abs($ms->z - 7.75) < 0.3 && $ms->onGround,
    'y=' . round($ms->y, 3) . ' z=' . round($ms->z, 3),
);

// 8. a step taller than STEP_HEIGHT still blocks the player
$highStep = [$floor, new AABB(new Vec3(-5, 0.3, 0), new Vec3(5, 0.8, 1))];
$mh = new PlayerMovement;
$mh->setColliders($highStep);
$mh->teleport(0, 0, -2);
for ($i = 0; $i < 900; $i++) {
    $mh->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check(
    'a step taller than STEP_HEIGHT still blocks',
    abs($mh->z + 0.25) < 0.1 && $mh->y < 0.01,
    'y=' . round($mh->y, 3) . ' z=' . round($mh->z, 3),
);

// 5. bunnyhopping: holding jump + turning while strafing builds speed above
// the run baseline. The wish direction rotates with the view, so its
// projection onto the velocity periodically goes negative and the air
// accelerator adds net speed.
$m = newPlayer($floor);
for ($i = 0; $i < 30; $i++) {
    $m->update(1.0, 0.0, PlayerMovement::RUN_SPEED, $dt);
}
$baseline = sqrt($m->velX * $m->velX + $m->velZ * $m->velZ);
check('ran up to baseline speed', $baseline > PlayerMovement::RUN_SPEED * 0.9, 'speed=' . round($baseline, 3));

$yaw = 0.0;
$omega = 3.0; // ~172 deg/s view turn
$phase = M_PI / 4; // forward + strafe combined wish
$maxSpeed = $baseline;
for ($i = 0; $i < 240; $i++) {
    if ($m->onGround) {
        $m->jump();
    }
    $yaw += $omega * $dt;
    $m->update(cos($yaw + $phase), sin($yaw + $phase), PlayerMovement::RUN_SPEED, $dt);
    $speed = sqrt($m->velX * $m->velX + $m->velZ * $m->velZ);
    $maxSpeed = max($maxSpeed, $speed);
}
check(
    'bunnyhop builds speed above baseline',
    $maxSpeed > $baseline * 1.5,
    'baseline=' . round($baseline, 3) . ' maxSpeed=' . round($maxSpeed, 3),
);

// 9. crouch: the player's box shrinks and the state is tracked
$m = newPlayer($floor);
$m->setCrouching(true);
check(
    'crouch shrinks the player height',
    $m->isCrouching() && abs($m->getHeight() - PlayerMovement::CROUCH_HEIGHT) < 1e-9,
    'height=' . $m->getHeight(),
);
$m->setCrouching(false);
check(
    'uncrouch restores standing height',
    !$m->isCrouching() && abs($m->getHeight() - PlayerMovement::PLAYER_HEIGHT) < 1e-9,
    'height=' . $m->getHeight(),
);

// 10. a low doorway: standing player is blocked, crouched player passes
$lintel = new AABB(new Vec3(-5, 1.0, 0), new Vec3(5, 1.2, 1));
$doorway = [$floor, $lintel];

$ms = new PlayerMovement;
$ms->setColliders($doorway);
$ms->teleport(0, 0, -2);
for ($i = 0; $i < 900; $i++) {
    $ms->update(0.0, 1.0, PlayerMovement::RUN_SPEED, $dt);
}
check(
    'low doorway blocks a standing player',
    abs($ms->z + 0.25) < 0.1 && $ms->y < 0.01,
    'y=' . round($ms->y, 3) . ' z=' . round($ms->z, 3),
);

$mc = new PlayerMovement;
$mc->setColliders($doorway);
$mc->teleport(0, 0, -2);
$mc->setCrouching(true);
for ($i = 0; $i < 900; $i++) {
    $mc->update(0.0, 1.0, PlayerMovement::WALK_SPEED, $dt);
}
check(
    'crouched player passes under the doorway',
    $mc->z > 1.75 && $mc->y < 0.01 && $mc->onGround,
    'y=' . round($mc->y, 3) . ' z=' . round($mc->z, 3),
);

// 11. standing up under the lintel is refused, allowed once past it
$mc->setCrouching(true);
$mc->teleport(0, 0, 0.5); // dead center under the lintel
$mc->setCrouching(false);
check(
    'cannot stand up under the lintel',
    $mc->isCrouching(),
    'crouching=' . var_export($mc->isCrouching(), true),
);
$mc->teleport(0, 0, 2.0); // clear of the lintel
$mc->setCrouching(false);
check(
    'stands up once past the lintel',
    !$mc->isCrouching() && abs($mc->getHeight() - PlayerMovement::PLAYER_HEIGHT) < 1e-9,
    'height=' . $mc->getHeight(),
);

// 12. crouch caps acceleration at walk speed, not run speed
$m = newPlayer($floor);
$m->setCrouching(true);
$time = 0.0;
while ($time < 0.6) {
    $m->update(1.0, 0.0, PlayerMovement::RUN_SPEED, $dt);
    $time += $dt;
}
check(
    'crouch keeps speed at walk speed',
    abs($m->velX - PlayerMovement::WALK_SPEED) < 0.5 && $m->velX < PlayerMovement::RUN_SPEED * 0.9,
    'velX=' . round($m->velX, 3),
);

echo "\n";
if ($failures > 0) {
    echo "$failures check(s) failed\n";
    exit(1);
}
echo "All checks passed\n";
