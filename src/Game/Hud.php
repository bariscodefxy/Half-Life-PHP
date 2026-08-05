<?php

namespace App\Game;

use GL\VectorGraphics\VGAlign;
use GL\VectorGraphics\VGColor;
use GL\VectorGraphics\VGContext;
use VISU\Graphics\RenderTarget;
use VISU\Graphics\Rendering\RenderContext;
use VISU\Quickstart\QuickstartApp;

class Hud
{
    private const FONT = 'inconsolata-regular';

    private const HEALTH = 100;
    private const AMMO = 0;

    private VGContext $vg;

    public function __construct(QuickstartApp $app)
    {
        $this->vg = $app->vg;
    }

    public function draw(RenderContext $context, RenderTarget $renderTarget) : void
    {
        $this->vg->resetTransform();

        $w = $renderTarget->effectiveWidth();
        $h = $renderTarget->effectiveHeight();

        $this->drawCrosshair($w, $h);
        $this->drawHealth($w, $h);
        $this->drawAmmo($w, $h);
    }

    private function drawCrosshair(float $w, float $h) : void
    {
        $cx = $w * 0.5;
        $cy = $h * 0.5;
        $size = 8.0;
        $gap = 2.0;

        $this->vg->strokeColor(new VGColor(0.0, 1.0, 0.0, 1.0));
        $this->vg->strokeWidth(2.0);

        $this->vg->beginPath();
        $this->vg->moveTo($cx, $cy - $gap - $size);
        $this->vg->lineTo($cx, $cy - $gap);
        $this->vg->stroke();

        $this->vg->beginPath();
        $this->vg->moveTo($cx, $cy + $gap);
        $this->vg->lineTo($cx, $cy + $gap + $size);
        $this->vg->stroke();

        $this->vg->beginPath();
        $this->vg->moveTo($cx - $gap - $size, $cy);
        $this->vg->lineTo($cx - $gap, $cy);
        $this->vg->stroke();

        $this->vg->beginPath();
        $this->vg->moveTo($cx + $gap, $cy);
        $this->vg->lineTo($cx + $gap + $size, $cy);
        $this->vg->stroke();
    }

    private function drawHealth(float $w, float $h) : void
    {
        $x = 16.0;
        $y = $h - 72.0;
        $boxW = 72.0;
        $boxH = 56.0;

        $this->vg->fontFace(self::FONT);
        $this->vg->fontSize(28.0);
        $this->vg->textAlign(VGAlign::CENTER | VGAlign::MIDDLE);
        $this->vg->fillColor(new VGColor(0.0, 1.0, 0.0, 1.0));
        $this->vg->text($x + $boxW * 0.5, $y + $boxH * 0.5, (string) self::HEALTH);
    }

    private function drawAmmo(float $w, float $h) : void
    {
        // mermimiz yok ise çizdirmeye gerek yok
        if (empty($this->AMMO)) {
            return;
        }

        $boxW = 72.0;
        $boxH = 56.0;
        $x = $w - 16.0 - $boxW;
        $y = $h - 72.0;


        $this->vg->fontFace(self::FONT);
        $this->vg->fontSize(28.0);
        $this->vg->textAlign(VGAlign::CENTER | VGAlign::MIDDLE);
        $this->vg->fillColor(new VGColor(0.0, 1.0, 0.0, 1.0));
        $this->vg->text($x + $boxW * 0.5, $y + $boxH * 0.5, (string) self::AMMO);
    }
}
