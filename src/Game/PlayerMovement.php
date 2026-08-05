<?php

namespace App\Game;

use VISU\Geo\AABB;

/**
 * GoldSrc (Half-Life) style movement physics.
 *
 * Owns the player's feet position, velocity and ground state. Movement follows
 * the pm_shared model: ground friction, wishspeed based acceleration with a
 * separate air accelerator, a jump impulse of sqrt(2 * g * 45u) and constant
 * gravity. Units are scaled from Half-Life's 36-unit-per-meter convention, so
 * sv_maxspeed 320 becomes RUN_SPEED, sv_gravity 800 becomes GRAVITY and a 45u
 * jump becomes a ~1.25m apex.
 *
 * Positions are the player's *feet*. Up is the +Y axis.
 */
class PlayerMovement
{
    /** Run speed (sv_maxspeed, 320u/s scaled). */
    public const RUN_SPEED = 8.9;
    /** Walk speed (sv_walkspeed, 150u/s scaled). */
    public const WALK_SPEED = 4.2;
    /** Crouch speed cap (GoldSrc SPEED_CROUCH, 150u/s scaled, equals walk). */
    public const CROUCH_SPEED = 4.2;
    /** Velocity below which ground friction simply stops the player (sv_stopspeed). */
    public const STOP_SPEED = 2.8;
    /** Ground friction (sv_friction). */
    public const FRICTION = 4.0;
    /** Ground acceleration (sv_accelerate). */
    public const ACCELERATE = 10.0;
    /** Air acceleration (sv_airaccelerate). */
    public const AIR_ACCELERATE = 10.0;
    /**
     * Air control speed cap (30u/s). Forward speed projections above this cap
     * get no air acceleration; sideways strafing still gains speed, which is
     * what makes bunnyhopping and air strafing work.
     */
    public const AIR_CAP_SPEED = 0.833;
    /** Gravity (sv_gravity, 800u/s² scaled). */
    public const GRAVITY = 22.0;
    /** Jump impulse = sqrt(2 * g * 45u) scaled (sv_jump_impulse). */
    public const JUMP_SPEED = 7.45;

    public const PLAYER_RADIUS = 0.25;
    public const PLAYER_HEIGHT = 1.7;
    /**
     * Player box height while crouched (exactly half of PLAYER_HEIGHT, the
     * GoldSrc 36u ducked height at our scale).
     */
    public const CROUCH_HEIGHT = 0.85;
    /**
     * Eye height above the feet while crouched (GoldSrc 28u ducked eye scaled
     * to our height, rounded to 0.6m).
     */
    public const CROUCH_EYE_HEIGHT = 0.6;
    /**
     * Maximum rise a horizontal blocker can have (relative to the player's
     * feet) and still be stepped onto instead of blocking. Matches Half-Life's
     * 18-unit sv_stepsize scaled at 36 units per meter.
     */
    public const STEP_HEIGHT = 0.5;
    /**
     * Minimum normalized up-component of a surface normal for the player to be
     * able to stand on and walk across it. Surfaces steeper than this (walls,
     * stair risers, ceilings) are solid blockers. 0.5 allows ramps up to ~60
     * degrees from horizontal.
     */
    public const WALKABLE_SLOPE = 0.5;
    /**
     * Vertical window around the feet in which a walkable surface supports the
     * player: feet snap onto a floor up to this far below (or slightly above)
     * and a walkable surface this far above the feet does not count as a
     * horizontal blocker yet (the player rides up ramps and small steps).
     */
    public const FLOOR_SNAP = 0.35;

    public float $x = 0.0;
    public float $y = 0.0;
    public float $z = 0.0;

    public float $velX = 0.0;
    public float $velY = 0.0;
    public float $velZ = 0.0;

    public bool $onGround = false;

    public bool $crouching = false;

    /**
     * @var array<AABB>
     */
    private array $colliders = [];

    private ?TriangleCollisionWorld $triWorld = null;

    /**
     * Triangle offsets (into TriangleCollisionWorld::$indices) gathered at the
     * start of the current tick.
     *
     * @var array<int>
     */
    private array $triCandidates = [];

    /**
     * Triangle offsets that blocked the horizontal move this tick (used for
     * step-up decisions).
     *
     * @var array<int>
     */
    private array $triBlockers = [];

    private bool $triBlocked = false;

    /**
     * Horizontal position before this tick's move, used to redo the move at a
     * raised height during step-up.
     */
    private float $triMoveOriginX = 0.0;
    private float $triMoveOriginZ = 0.0;

    /**
     * True while the player is traversing onto a surface stepped up onto this
     * tick: the feet are held at $triStepY (above the previous floor) until a
     * surface at that height is actually under the player's center, otherwise
     * floor-follow would immediately snap the feet back down and undo the climb.
     */
    private bool $triStepping = false;

    /**
     * Feet height the player was raised to for the current step-up.
     */
    private float $triStepY = 0.0;

    /**
     * Sets the AABB colliders the player resolves against.
     *
     * @param array<AABB> $colliders
     */
    public function setColliders(array $colliders) : void
    {
        $this->colliders = $colliders;
        $this->triWorld = null;
    }

    /**
     * Switches the player to triangle-accurate collision against the given
     * world. Passing null reverts to the AABB collider path.
     */
    public function setTriangleWorld(?TriangleCollisionWorld $world) : void
    {
        $this->triWorld = $world;
        if ($world !== null) {
            $this->colliders = [];
        }
    }

    /**
     * Teleports the player, zeroing velocity.
     */
    public function teleport(float $x, float $y, float $z) : void
    {
        $this->x = $x;
        $this->y = $y;
        $this->z = $z;
        $this->velX = 0.0;
        $this->velY = 0.0;
        $this->velZ = 0.0;
        $this->onGround = false;
        $this->triStepping = false;
    }

    /**
     * Steps the physics one tick.
     *
     * @param float $wishX horizontal wish direction X (normalized internally)
     * @param float $wishZ horizontal wish direction Z (normalized internally)
     * @param float $wishSpeed desired horizontal speed (RUN_SPEED or WALK_SPEED)
     */
    public function update(float $wishX, float $wishZ, float $wishSpeed, float $dt) : void
    {
        if ($this->crouching && $wishSpeed > self::CROUCH_SPEED) {
            $wishSpeed = self::CROUCH_SPEED;
        }

        $length = sqrt($wishX * $wishX + $wishZ * $wishZ);
        if ($length > 0.0) {
            $wishX /= $length;
            $wishZ /= $length;
        }

        if ($this->triWorld !== null) {
            $this->updateTriangle($wishX, $wishZ, $wishSpeed, $dt);
            return;
        }

        if ($this->onGround) {
            $this->friction($dt);
            $this->accelerate($wishX, $wishZ, $wishSpeed, $wishSpeed, self::ACCELERATE, $dt);
        } else {
            $this->accelerate($wishX, $wishZ, $wishSpeed, self::AIR_CAP_SPEED, self::AIR_ACCELERATE, $dt);
        }

        $this->velY -= self::GRAVITY * $dt;

        $this->moveAxisX($dt);
        $this->moveAxisZ($dt);
        $this->moveAxisY($dt);
    }

    /**
     * Applies the jump impulse when grounded. Does nothing in the air.
     */
    public function jump() : void
    {
        if (!$this->onGround) {
            return;
        }

        $this->velY = self::JUMP_SPEED;
        $this->onGround = false;
    }

    /**
     * The player's current box height: crouch height while ducked, standing
     * height otherwise. All collision checks resolve against this, so a
     * crouched player fits under low ceilings.
     */
    public function getHeight() : float
    {
        return $this->crouching ? self::CROUCH_HEIGHT : self::PLAYER_HEIGHT;
    }

    /**
     * Current box height for collision checks.
     */
    private function height() : float
    {
        return $this->crouching ? self::CROUCH_HEIGHT : self::PLAYER_HEIGHT;
    }

    public function isCrouching() : bool
    {
        return $this->crouching;
    }

    /**
     * Requests a crouch/stand transition. Crouching always succeeds; standing
     * is refused when the standing box would collide with a ceiling above the
     * player, so the player stays ducked until there is headroom. Returns the
     * effective crouch state after the attempt.
     */
    public function setCrouching(bool $crouching) : bool
    {
        if ($crouching === $this->crouching) {
            return $this->crouching;
        }

        if (!$crouching && !$this->canStandUp()) {
            return true;
        }

        $this->crouching = $crouching;
        return $this->crouching;
    }

    /**
     * True if the standing-height box fits where the player currently is:
     * nothing (wall or ceiling) occupies the space above the crouched head.
     */
    private function canStandUp() : bool
    {
        if ($this->triWorld !== null) {
            $cands = $this->triWorld->querySquare($this->x, $this->z, self::PLAYER_RADIUS + 1.0);
            $rise = self::PLAYER_HEIGHT - self::CROUCH_HEIGHT;
            foreach ($cands as $offset) {
                $tri = $this->triWorld->vertexTriple($offset);
                if ($this->triNormalY($tri) >= -0.2) {
                    continue;
                }
                $hit = $this->rayTriDistance(
                    $tri,
                    $this->x,
                    $this->y + self::CROUCH_HEIGHT,
                    $this->z,
                    0.0,
                    1.0,
                    0.0,
                    $rise + 0.05,
                );
                if ($hit !== null) {
                    return false;
                }
            }
            return true;
        }

        $r = self::PLAYER_RADIUS;
        foreach ($this->colliders as $aabb) {
            if ($this->x - $r < $aabb->max->x
                && $this->x + $r > $aabb->min->x
                && $this->z - $r < $aabb->max->z
                && $this->z + $r > $aabb->min->z
                && $aabb->min->y < $this->y + self::PLAYER_HEIGHT
                && $aabb->max->y > $this->y + self::CROUCH_HEIGHT) {
                return false;
            }
        }
        return true;
    }

    /**
     * Ground friction: decays horizontal velocity toward zero.
     */
    private function friction(float $dt) : void
    {
        $speed = sqrt($this->velX * $this->velX + $this->velZ * $this->velZ);
        if ($speed < 0.1) {
            $this->velX = 0.0;
            $this->velZ = 0.0;
            return;
        }

        $control = $speed < self::STOP_SPEED ? self::STOP_SPEED : $speed;
        $newSpeed = max($speed - $control * self::FRICTION * $dt, 0.0);

        if ($newSpeed !== $speed) {
            $scale = $newSpeed / $speed;
            $this->velX *= $scale;
            $this->velZ *= $scale;
        }
    }

    /**
     * GoldSrc accelerate. Adds up to $accelSpeed of the wish direction per
     * tick, capped by the remaining speed to reach $cap.
     */
    private function accelerate(float $wishX, float $wishZ, float $wishSpeed, float $cap, float $accel, float $dt) : void
    {
        $projection = min($wishSpeed, $cap);
        $currentSpeed = $this->velX * $wishX + $this->velZ * $wishZ;
        $addSpeed = $projection - $currentSpeed;

        if ($addSpeed <= 0.0) {
            return;
        }

        $accelSpeed = $accel * $wishSpeed * $dt;
        if ($accelSpeed > $addSpeed) {
            $accelSpeed = $addSpeed;
        }

        $this->velX += $accelSpeed * $wishX;
        $this->velZ += $accelSpeed * $wishZ;
    }

    /**
     * Triangle-world movement tick. Identical acceleration to the AABB path,
     * then horizontal resolve with wall slide and step-up, then vertical
     * resolve (floors, slopes, ceilings, landing).
     */
    private function updateTriangle(float $wishX, float $wishZ, float $wishSpeed, float $dt) : void
    {
        if ($this->onGround) {
            $this->friction($dt);
            $this->accelerate($wishX, $wishZ, $wishSpeed, $wishSpeed, self::ACCELERATE, $dt);
        } else {
            $this->accelerate($wishX, $wishZ, $wishSpeed, self::AIR_CAP_SPEED, self::AIR_ACCELERATE, $dt);
        }

        $this->velY -= self::GRAVITY * $dt;

        $this->triCandidates = $this->triWorld->querySquare(
            $this->x,
            $this->z,
            self::PLAYER_RADIUS + 1.0
        );

        $this->triMoveHorizontal($dt);
        $this->triTryStep($dt);
        $this->triMoveVertical($dt);
    }

    /**
     * Integrates the horizontal velocity, pushes the player out of any
     * blocking triangle silhouette, then slides velocity along the blockers.
     */
    private function triMoveHorizontal(float $dt) : void
    {
        $this->triBlocked = false;
        $this->triBlockers = [];

        $this->triMoveOriginX = $this->x;
        $this->triMoveOriginZ = $this->z;
        $this->x += $this->velX * $dt;
        $this->z += $this->velZ * $dt;

        $slideX = [];
        $slideZ = [];

        for ($pass = 0; $pass < 4; $pass++) {
            $pushed = false;
            foreach ($this->triCandidates as $offset) {
                $tri = $this->triWorld->vertexTriple($offset);
                if (!$this->triBlocksHorizontal($tri)) {
                    continue;
                }
                $outX = 0.0;
                $outZ = 0.0;
                if ($this->triPushOut2D($tri, $outX, $outZ)) {
                    $pushed = true;
                    $slideX[] = $outX;
                    $slideZ[] = $outZ;
                    if (!in_array($offset, $this->triBlockers, true)) {
                        $this->triBlockers[] = $offset;
                    }
                }
            }
            if (!$pushed) {
                break;
            }
        }

        if ($this->triBlockers !== []) {
            $this->triBlocked = true;
        }

        // wall slide: remove the velocity component headed into each blocker
        foreach ($slideX as $i => $dx) {
            $dz = $slideZ[$i];
            $dot = $this->velX * $dx + $this->velZ * $dz;
            if ($dot < 0.0) {
                $this->velX -= $dot * $dx;
                $this->velZ -= $dot * $dz;
            }
        }
    }

    /**
     * Horizontal blocker resolve shared with the step-up attempt. Only used in
     * triangle mode.
     */
    private function triResolveHorizontal() : void
    {
        $this->triBlocked = false;
        $this->triBlockers = [];

        for ($pass = 0; $pass < 4; $pass++) {
            $pushed = false;
            foreach ($this->triCandidates as $offset) {
                $tri = $this->triWorld->vertexTriple($offset);
                if (!$this->triBlocksHorizontal($tri)) {
                    continue;
                }
                $outX = 0.0;
                $outZ = 0.0;
                if ($this->triPushOut2D($tri, $outX, $outZ)) {
                    $pushed = true;
                    $this->triBlockers[] = $offset;
                }
            }
            if (!$pushed) {
                break;
            }
        }

        if ($this->triBlockers !== []) {
            $this->triBlocked = true;
        }
    }

    /**
     * GoldSrc PM_StepSlideMove: when a grounded horizontal move is blocked by
     * a surface whose top is within STEP_HEIGHT above the feet, raise the
     * player onto it and redo the move from the pre-move position. The raised
     * state is kept until the raised surface is actually under the player (see
     * $triStepping), because one tick's displacement is rarely enough to cross
     * the blocker's silhouette.
     */
    private function triTryStep(float $dt) : void
    {
        if (!$this->onGround || !$this->triBlocked || $this->triBlockers === []) {
            return;
        }

        $stepTop = null;
        foreach ($this->triBlockers as $offset) {
            $tri = $this->triWorld->vertexTriple($offset);
            $maxY = max($tri[1], $tri[4], $tri[7]);
            if ($maxY > $this->y + 0.01 && $maxY <= $this->y + self::STEP_HEIGHT) {
                $stepTop = $stepTop === null ? $maxY : max($stepTop, $maxY);
            }
        }
        if ($stepTop === null) {
            return;
        }

        $origX = $this->x;
        $origY = $this->y;
        $origZ = $this->z;

        // redo the full horizontal move from the pre-move position at the
        // raised feet, so the player clears the riser instead of re-colliding
        $this->x = $this->triMoveOriginX;
        $this->z = $this->triMoveOriginZ;
        $this->y = $stepTop;
        $this->x += $this->velX * $dt;
        $this->z += $this->velZ * $dt;

        $this->triResolveHorizontal();

        if ($this->triBlocked) {
            // still blocked at the raised height: not a step, a real wall
            $this->x = $origX;
            $this->y = $origY;
            $this->z = $origZ;
            $this->triStepping = false;
            $this->triBlocked = true;
            return;
        }

        $this->triStepping = true;
        $this->triStepY = $stepTop;
        $this->onGround = true;
    }

    /**
     * Resolves vertical movement: ceiling clamp when moving up, then floor
     * support (smooth slopes / small steps) or swept landing when falling.
     */
    private function triMoveVertical(float $dt) : void
    {
        $oldY = $this->y;

        if ($this->velY > 0.0) {
            $delta = $this->velY * $dt;
            $hit = $this->triRayUp($this->y + $this->height(), $delta);
            if ($hit !== null) {
                $this->y = $this->y + $hit - 0.01;
                $this->velY = 0.0;
            }
        }

        $this->y += $this->velY * $dt;

        // anti-tunnel: the feet must never end below a walkable surface under
        // the player's center. A rising slope can outrun a jump arc (the
        // surface gains height faster than the rising feet), putting the feet
        // inside the slope; once that happens a downward landing ray can never
        // find the surface again and the player falls through into the void.
        // Snap the feet up onto the surface and land instead.
        $floorY = $this->triFloorHeightAt($this->y - 0.02, $this->y + self::FLOOR_SNAP);
        if ($floorY !== null && $floorY > $this->y) {
            $this->y = $floorY;
            $this->velY = 0.0;
            $this->onGround = true;
        }

        if ($this->onGround) {
            if ($this->triStepping) {
                // while traversing onto the stepped-up surface, only a floor at
                // the raised height supports the player; any lower surface is
                // the floor the step left and would undo the climb. The feet
                // are held raised until the surface is under the player.
                $floorY = $this->triFloorHeightAt($this->triStepY - 0.05, $this->triStepY + self::FLOOR_SNAP);
                if ($floorY !== null) {
                    $this->y = $floorY;
                    if ($this->velY < 0.0) {
                        $this->velY = 0.0;
                    }
                    $this->onGround = true;
                    $this->triStepping = false;
                } else {
                    $this->y = $this->triStepY;
                    $this->onGround = true;
                }
            } else {
                $floorY = $this->triFloorHeightAt($this->y - self::FLOOR_SNAP, $this->y + self::FLOOR_SNAP);
                if ($floorY !== null) {
                    $this->y = $floorY;
                    if ($this->velY < 0.0) {
                        $this->velY = 0.0;
                    }
                    $this->onGround = true;
                } else {
                    $this->onGround = false;
                }
            }
        } else {
            $fallDist = $this->y - $oldY;
            if ($fallDist < 0.0) {
                $hit = $this->triRayDown($oldY, -$fallDist + 0.05);
                if ($hit !== null && $oldY - $hit >= $this->y - 0.06) {
                    $this->y = $oldY - $hit;
                    $this->velY = 0.0;
                    $this->onGround = true;
                }
            }
        }
    }

    /**
     * True if the triangle can act as a horizontal blocker right now: its
     * vertical span overlaps the player's box and it is steeper than walkable.
     */
    private function triBlocksHorizontal(array $tri) : bool
    {
        $minY = min($tri[1], $tri[4], $tri[7]);
        $maxY = max($tri[1], $tri[4], $tri[7]);

        if ($maxY <= $this->y + 0.01) {
            return false;
        }
        if ($minY > $this->y + $this->height() + 0.01) {
            return false;
        }

        [$nx, $ny, $nz] = $this->triNormal($tri);
        if ($ny > self::WALKABLE_SLOPE) {
            // walkable surface: only a blocker when its surface sits more than
            // FLOOR_SNAP above the feet (a ledge to step onto); while the
            // player rides on it (slopes) or it is below the feet it is not
            $h = $tri[1] - ($nx * ($this->x - $tri[0]) + $nz * ($this->z - $tri[2])) / $ny;
            if ($h <= $this->y + self::FLOOR_SNAP) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pushes the player's center out of the triangle's 2D (XZ) footprint to
     * exactly PLAYER_RADIUS from its boundary. Returns false when no push was
     * needed; on a push, $outX/$outZ receive the outward unit direction.
     *
     * @param array<float> $tri
     * @param float $outX
     * @param float $outZ
     */
    private function triPushOut2D(array $tri, float &$outX, float &$outZ) : bool
    {
        $r = self::PLAYER_RADIUS;
        [$bx, $bz, $dist] = $this->closestOnTriBoundary2D($tri, $this->x, $this->z);

        if ($dist >= $r) {
            return false;
        }

        if ($dist > 1e-9) {
            $outX = ($this->x - $bx) / $dist;
            $outZ = ($this->z - $bz) / $dist;
        } else {
            // center inside the footprint: push out along the boundary normal
            $gx = ($tri[0] + $tri[3] + $tri[6]) / 3.0;
            $gz = ($tri[2] + $tri[5] + $tri[8]) / 3.0;
            $outX = $bx - $gx;
            $outZ = $bz - $gz;
            $len = sqrt($outX * $outX + $outZ * $outZ);
            if ($len < 1e-9) {
                $outX = 1.0;
                $outZ = 0.0;
                $len = 1.0;
            }
            $outX /= $len;
            $outZ /= $len;
        }

        $this->x = $bx + $outX * $r;
        $this->z = $bz + $outZ * $r;

        return true;
    }

    /**
     * Closest point on the triangle's XZ boundary (the three edges) to the
     * given point, plus the squared-aware distance.
     *
     * @param array<float> $tri
     * @return array{0: float, 1: float, 2: float}
     */
    private function closestOnTriBoundary2D(array $tri, float $px, float $pz) : array
    {
        $vx = [$tri[0], $tri[3], $tri[6]];
        $vz = [$tri[2], $tri[5], $tri[8]];
        $bestX = 0.0;
        $bestZ = 0.0;
        $best = PHP_FLOAT_MAX;

        for ($e = 0; $e < 3; $e++) {
            $x1 = $vx[$e];
            $z1 = $vz[$e];
            $x2 = $vx[($e + 1) % 3];
            $z2 = $vz[($e + 1) % 3];
            $dx = $x2 - $x1;
            $dz = $z2 - $z1;
            $len2 = $dx * $dx + $dz * $dz;
            $t = $len2 > 0.0 ? (($px - $x1) * $dx + ($pz - $z1) * $dz) / $len2 : 0.0;
            $t = max(0.0, min(1.0, $t));
            $cx = $x1 + $t * $dx;
            $cz = $z1 + $t * $dz;
            $d2 = ($px - $cx) * ($px - $cx) + ($pz - $cz) * ($pz - $cz);
            if ($d2 < $best) {
                $best = $d2;
                $bestX = $cx;
                $bestZ = $cz;
            }
        }

        return [$bestX, $bestZ, sqrt($best)];
    }

    /**
     * Highest walkable surface height under the player's center within the
     * given feet window, or null when nothing supports the player.
     *
     * @param array<float> $tri
     */
    private function triFloorHeightAt(float $lo, float $hi) : ?float
    {
        $best = null;
        foreach ($this->triCandidates as $offset) {
            $tri = $this->triWorld->vertexTriple($offset);
            [$nx, $ny, $nz] = $this->triNormal($tri);
            if ($ny <= self::WALKABLE_SLOPE) {
                continue;
            }
            if (!$this->pointInTri2D($tri, $this->x, $this->z)) {
                continue;
            }
            $h = $tri[1] - ($nx * ($this->x - $tri[0]) + $nz * ($this->z - $tri[2])) / $ny;
            if ($h >= $lo && $h <= $hi) {
                if ($best === null || $h > $best) {
                    $best = $h;
                }
            }
        }
        return $best;
    }

    /**
     * Nearest intersection distance of a downward ray from (x, startY, z) with
     * a walkable surface, or null.
     */
    private function triRayDown(float $startY, float $dist) : ?float
    {
        $best = null;
        foreach ($this->triCandidates as $offset) {
            $tri = $this->triWorld->vertexTriple($offset);
            if ($this->triNormalY($tri) <= self::WALKABLE_SLOPE) {
                continue;
            }
            $hit = $this->rayTriDistance($tri, $this->x, $startY, $this->z, 0.0, -1.0, 0.0, $dist);
            if ($hit !== null && ($best === null || $hit < $best)) {
                $best = $hit;
            }
        }
        return $best;
    }

    /**
     * Nearest intersection distance of an upward ray from (x, startY, z) with a
     * downward facing surface (ceiling), or null.
     */
    private function triRayUp(float $startY, float $dist) : ?float
    {
        $best = null;
        foreach ($this->triCandidates as $offset) {
            $tri = $this->triWorld->vertexTriple($offset);
            if ($this->triNormalY($tri) >= -0.2) {
                continue;
            }
            $hit = $this->rayTriDistance($tri, $this->x, $startY, $this->z, 0.0, 1.0, 0.0, $dist);
            if ($hit !== null && ($best === null || $hit < $best)) {
                $best = $hit;
            }
        }
        return $best;
    }

    /**
     * Front-face ray vs triangle. Returns the ray parameter at the first hit
     * within $maxDist, or null.
     *
     * @param array<float> $tri
     */
    private function rayTriDistance(
        array $tri,
        float $ox,
        float $oy,
        float $oz,
        float $dx,
        float $dy,
        float $dz,
        float $maxDist
    ) : ?float {
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
        // A tiny negative t means the origin sits a hair *below* the surface
        // plane (e.g. the spawn feet height is float-rounded below the floor).
        // Treat that as on-the-surface so the player standing exactly on a
        // floor still gets supported instead of falling through it.
        if ($t < -1e-4 || $t > $maxDist) {
            return null;
        }

        $ix = $ox + $t * $dx;
        $iy = $oy + $t * $dy;
        $iz = $oz + $t * $dz;

        // barycentric point-in-triangle (the hit lies on the plane)
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

    /**
     * Normalized triangle normal.
     *
     * @param array<float> $tri
     * @return array{0: float, 1: float, 2: float}
     */
    private function triNormal(array $tri) : array
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
        if ($len < 1e-12) {
            return [0.0, 1.0, 0.0];
        }
        return [$nx / $len, $ny / $len, $nz / $len];
    }

    /**
     * The normalized up-component of the triangle normal.
     *
     * @param array<float> $tri
     */
    private function triNormalY(array $tri) : float
    {
        return $this->triNormal($tri)[1];
    }

    /**
     * Barycentric point-in-triangle test on the XZ projection.
     *
     * @param array<float> $tri
     */
    private function pointInTri2D(array $tri, float $px, float $pz) : bool
    {
        $ax = $tri[0];
        $az = $tri[2];
        $v0x = $tri[6] - $ax;
        $v0z = $tri[8] - $az;
        $v1x = $tri[3] - $ax;
        $v1z = $tri[5] - $az;
        $v2x = $px - $ax;
        $v2z = $pz - $az;

        $dot00 = $v0x * $v0x + $v0z * $v0z;
        $dot01 = $v0x * $v1x + $v0z * $v1z;
        $dot02 = $v0x * $v2x + $v0z * $v2z;
        $dot11 = $v1x * $v1x + $v1z * $v1z;
        $dot12 = $v1x * $v2x + $v1z * $v2z;
        $inv = 1.0 / ($dot00 * $dot11 - $dot01 * $dot01);
        $u = ($dot11 * $dot02 - $dot01 * $dot12) * $inv;
        $v = ($dot00 * $dot12 - $dot01 * $dot02) * $inv;

        return $u >= -1e-6 && $v >= -1e-6 && $u + $v <= 1.0 + 1e-6;
    }

    /**
     * Integrates X then resolves horizontal blockers along X,
     * zeroing velocity on contact.
     */
    private function moveAxisX(float $dt) : void
    {
        $startX = $this->x;
        $startY = $this->y;
        $this->x += $this->velX * $dt;
        if ($this->velX == 0.0) {
            return;
        }

        $dir = $this->velX > 0.0 ? 1 : -1;
        $blocked = false;
        $stepTop = null;
        $r = self::PLAYER_RADIUS;

        foreach ($this->colliders as $aabb) {
            if ($this->isCeilingOrFloor($aabb)) {
                continue;
            }
            if ($this->x - $r >= $aabb->max->x || $this->x + $r <= $aabb->min->x
                || $this->z - $r >= $aabb->max->z || $this->z + $r <= $aabb->min->z) {
                continue;
            }

            if ($aabb->max->y > $this->y && $aabb->max->y <= $this->y + self::STEP_HEIGHT) {
                $stepTop = max($stepTop ?? $aabb->max->y, $aabb->max->y);
                continue;
            }

            if ($dir > 0) {
                $this->x = $aabb->min->x - $r;
            } else {
                $this->x = $aabb->max->x + $r;
            }
            $blocked = true;
        }

        if ($blocked) {
            $this->velX = 0.0;
            return;
        }

        if ($stepTop !== null) {
            if (!$this->tryStepUp($stepTop)) {
                $this->x = $startX;
                $this->y = $startY;
                $this->velX = 0.0;
                return;
            }
            $this->onGround = true;
        }
    }

    /**
     * Integrates Z then resolves horizontal blockers along Z,
     * zeroing velocity on contact.
     */
    private function moveAxisZ(float $dt) : void
    {
        $startZ = $this->z;
        $startY = $this->y;
        $this->z += $this->velZ * $dt;
        if ($this->velZ == 0.0) {
            return;
        }

        $dir = $this->velZ > 0.0 ? 1 : -1;
        $blocked = false;
        $stepTop = null;
        $r = self::PLAYER_RADIUS;

        foreach ($this->colliders as $aabb) {
            if ($this->isCeilingOrFloor($aabb)) {
                continue;
            }
            if ($this->x - $r >= $aabb->max->x || $this->x + $r <= $aabb->min->x
                || $this->z - $r >= $aabb->max->z || $this->z + $r <= $aabb->min->z) {
                continue;
            }

            if ($aabb->max->y > $this->y && $aabb->max->y <= $this->y + self::STEP_HEIGHT) {
                $stepTop = max($stepTop ?? $aabb->max->y, $aabb->max->y);
                continue;
            }

            if ($dir > 0) {
                $this->z = $aabb->min->z - $r;
            } else {
                $this->z = $aabb->max->z + $r;
            }
            $blocked = true;
        }

        if ($blocked) {
            $this->velZ = 0.0;
            return;
        }

        if ($stepTop !== null) {
            if (!$this->tryStepUp($stepTop)) {
                $this->z = $startZ;
                $this->y = $startY;
                $this->velZ = 0.0;
                return;
            }
            $this->onGround = true;
        }
    }

    /**
     * Raises the player onto the given step top. Returns false when the raised
     * position still collides (the step is not actually clear), leaving the
     * position raised so the caller can revert it.
     */
    private function tryStepUp(float $stepTop) : bool
    {
        $this->y = $stepTop;
        return !$this->overlapsAny();
    }

    /**
     * True if the player box overlaps any collider.
     */
    private function overlapsAny() : bool
    {
        foreach ($this->colliders as $aabb) {
            if ($this->overlapsBox($aabb)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Integrates Y then resolves floor/ceiling contact. Landing sets onGround
     * and zeroes vertical velocity, a ceiling hit zeroes it.
     */
    private function moveAxisY(float $dt) : void
    {
        $this->y += $this->velY * $dt;
        if ($this->velY == 0.0) {
            return;
        }

        if ($this->velY < 0.0) {
            $landed = false;
            foreach ($this->colliders as $aabb) {
                if (!$this->overlapsBox($aabb)) {
                    continue;
                }
                $this->y = $aabb->max->y;
                $landed = true;
            }
            if ($landed) {
                $this->velY = 0.0;
                $this->onGround = true;
            } else {
                $this->onGround = false;
            }
        } else {
            $blocked = false;
            foreach ($this->colliders as $aabb) {
                if (!$this->overlapsBox($aabb)) {
                    continue;
                }
                $this->y = $aabb->min->y - $this->height();
                $blocked = true;
            }
            if ($blocked) {
                $this->velY = 0.0;
            }
        }
    }

    /**
     * True if the box is entirely below the player's feet (a floor to stand
     * on) or entirely above the player's head (a ceiling), meaning it can not
     * act as a horizontal blocker.
     */
    private function isCeilingOrFloor(AABB $aabb) : bool
    {
        return $this->y + $this->height() <= $aabb->min->y
            || $this->y >= $aabb->max->y;
    }

    /**
     * Full player-box vs AABB overlap test.
     */
    private function overlapsBox(AABB $aabb) : bool
    {
        $r = self::PLAYER_RADIUS;
        $h = $this->height();

        return $this->x - $r < $aabb->max->x
            && $this->x + $r > $aabb->min->x
            && $this->y < $aabb->max->y
            && $this->y + $h > $aabb->min->y
            && $this->z - $r < $aabb->max->z
            && $this->z + $r > $aabb->min->z;
    }
}
