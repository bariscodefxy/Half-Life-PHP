<?php

namespace App\Game;

use GL\Math\Vec3;
use VISU\Graphics\Rendering\Renderer\Debug3DRenderer;
use VISU\Quickstart\Render\QuickstartDebugMetricsOverlay;

/**
 * F1 debug view of the map collision geometry.
 *
 * Maps that expose a TriangleCollisionWorld draw every triangle within a
 * radius of the player as a wireframe, colored by orientation: flat floors
 * green, walkable slopes yellow, walls red and downward facing ceilings blue.
 * Maps without a triangle world fall back to drawing their AABB colliders.
 *
 * The wireframe lines are queued per frame through the global Debug3DRenderer
 * and rendered as an extra pass by the Application while this view is enabled.
 */
class MapCollisionDebugView
{
    /**
     * Draw radius around the player for triangle maps, in world units.
     */
    private const TRIANGLE_RADIUS = 12.0;

    public bool $enabled = false;

    private ?GameMap $map = null;

    /**
     * Feet position provider (the FPS camera) used to center the triangle
     * draw radius around the player.
     *
     * @var (callable(): Vec3)|null
     */
    private $positionProvider = null;

    public function setMap(?GameMap $map) : void
    {
        $this->map = $map;
    }

    /**
     * @param callable(): Vec3 $provider
     */
    public function setPositionProvider(callable $provider) : void
    {
        $this->positionProvider = $provider;
    }

    /**
     * Toggles the collision view. Kept in sync with the F1 debug overlay by
     * the Application.
     */
    public function toggle() : void
    {
        $this->enabled = !$this->enabled;
    }

    /**
     * Queues the collision wireframe lines (and a stats row for the debug
     * overlay) for this frame.
     */
    public function queue() : void
    {
        if (!$this->enabled) {
            return;
        }

        $world = $this->map?->getTriangleWorld();
        if ($world !== null) {
            $this->queueTriangles($world);
            QuickstartDebugMetricsOverlay::debugString(
                'Map collisions: ' . intdiv(count($world->indices), 3) . ' triangles'
            );
            return;
        }

        foreach ($this->map?->getColliders() ?? [] as $aabb) {
            Debug3DRenderer::aabb(new Vec3(0, 0, 0), $aabb->min, $aabb->max, Debug3DRenderer::$colorGreen);
        }
        QuickstartDebugMetricsOverlay::debugString(
            'Map collisions: ' . count($this->map?->getColliders() ?? []) . ' AABB colliders'
        );
    }

    /**
     * Draws all triangles of the world within a radius of the player, colored
     * by their orientation.
     */
    private function queueTriangles(TriangleCollisionWorld $world) : void
    {
        $px = 0.0;
        $py = 0.0;
        $pz = 0.0;
        if ($this->positionProvider !== null) {
            $pos = ($this->positionProvider)();
            $px = $pos->x;
            $py = $pos->y;
            $pz = $pos->z;
        }

        $r = self::TRIANGLE_RADIUS;

        foreach ($world->querySquare($px, $pz, $r) as $offset) {
            $tri = $world->vertexTriple($offset);

            // reject triangles too far above or below the player before
            // spending a normal computation on them
            $minY = min($tri[1], $tri[4], $tri[7]);
            $maxY = max($tri[1], $tri[4], $tri[7]);
            if ($py < $minY - $r || $py > $maxY + $r) {
                continue;
            }

            $ny = $this->normalY($tri);
            if ($ny > 0.95) {
                $color = Debug3DRenderer::$colorGreen;
            } elseif ($ny > 0.5) {
                $color = Debug3DRenderer::$colorYellow;
            } elseif ($ny < -0.2) {
                $color = Debug3DRenderer::$colorBlue;
            } else {
                $color = Debug3DRenderer::$colorRed;
            }

            $this->line($tri[0], $tri[1], $tri[2], $tri[3], $tri[4], $tri[5], $color);
            $this->line($tri[3], $tri[4], $tri[5], $tri[6], $tri[7], $tri[8], $color);
            $this->line($tri[6], $tri[7], $tri[8], $tri[0], $tri[1], $tri[2], $color);
        }
    }

    /**
     * @param array<float> $tri
     */
    private function normalY(array $tri) : float
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
            return 0.0;
        }

        return $ny / $len;
    }

    private function line(float $x1, float $y1, float $z1, float $x2, float $y2, float $z2, Vec3 $color) : void
    {
        Debug3DRenderer::getGlobalInstance()->addLine(
            new Vec3($x1, $y1, $z1),
            new Vec3($x2, $y2, $z2),
            $color
        );
    }
}
