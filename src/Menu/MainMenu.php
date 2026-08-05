<?php

namespace App\Menu;

use GL\Math\Vec4;
use GL\VectorGraphics\VGAlign;
use GL\VectorGraphics\VGColor;
use GL\VectorGraphics\VGContext;
use VISU\Graphics\RenderTarget;
use VISU\Graphics\Rendering\RenderContext;
use VISU\OS\Input;
use VISU\OS\Key;
use VISU\OS\MouseButton;
use VISU\OS\Window;
use VISU\Quickstart\QuickstartApp;

class MainMenu
{
    private const FONT = 'inconsolata-regular';

    private const TITLE = 'HALF-LIFE-PHP';
    private const COPYRIGHT = 'Copyright 2026 bariscodefx, Half-Life-PHP licensed as GNUv3';

    private const TITLE_FONT_SIZE = 56.0;
    private const ITEM_FONT_SIZE = 20.0;
    private const ITEM_LINE_HEIGHT = 34.0;
    private const ITEM_HIT_PADDING = 8.0;

    private VGContext $vg;
    private Input $input;
    private Window $window;

    private VGColor $textColor;
    private VGColor $hoverColor;
    private VGColor $hoverBackgroundColor;
    private VGColor $mutedColor;
    private VGColor $titleColor;

    /**
     * The menu items as action key => label pairs.
     *
     * @var array<string, string>
     */
    private array $items = [
        'new_game' => 'NEW GAME',
        'toggle_map' => 'MAP: CROSSFIRE',
        'load_game' => 'LOAD GAME',
        'configuration' => 'CONFIGURATION',
        'multiplayer' => 'MULTIPLAYER',
        'quit' => 'QUIT',
    ];

    /**
     * The action keys of the menu items in display order.
     *
     * @var array<int, string>
     */
    private array $itemKeys;

    private int $selectedIndex = 0;

    private ?int $hoveredIndex = null;

    /**
     * Callback invoked when NEW GAME is activated.
     *
     * @var null|callable(): void
     */
    public $onNewGame = null;

    public function __construct(QuickstartApp $app)
    {
        $this->vg = $app->vg;
        $this->input = $app->input;
        $this->window = $app->window;

        $this->itemKeys = array_keys($this->items);

        $this->textColor = new VGColor(0.92, 0.92, 0.90, 1.0);
        $this->hoverColor = new VGColor(1.0, 0.62, 0.20, 1.0);
        $this->hoverBackgroundColor = new VGColor(1.0, 0.62, 0.20, 0.16);
        $this->mutedColor = new VGColor(0.45, 0.45, 0.45, 1.0);
        $this->titleColor = new VGColor(0.95, 0.95, 0.92, 1.0);
    }

    public function ready() : void
    {
        // the 'inconsolata-regular' font is loaded by the FlyUI initializer.
    }

    /**
     * Updates the label of the MAP toggle item.
     */
    public function setMapLabel(string $label) : void
    {
        $this->items['toggle_map'] = 'MAP: ' . $label;
    }

    public function draw(RenderContext $context, RenderTarget $renderTarget) : void
    {
        $this->vg->resetTransform();

        $w = $renderTarget->effectiveWidth();
        $h = $renderTarget->effectiveHeight();

        $renderTarget->framebuffer()->clear(GL_COLOR_BUFFER_BIT | GL_STENCIL_BUFFER_BIT);

        $this->drawBackground($w, $h);
        $this->drawTitle($w, $h);

        $itemRects = $this->computeItemRects($w, $h);
        $this->handleInput($renderTarget, $itemRects);
        $this->drawItems($itemRects);

        $this->drawFooter($w, $h);
    }

    /**
     * Draws the dark gradient backdrop.
     */
    private function drawBackground(float $w, float $h) : void
    {
        $paint = $this->vg->linearGradient(
            0, 0, 0, $h,
            new VGColor(0.10, 0.12, 0.18, 1.0),
            new VGColor(0.01, 0.01, 0.03, 1.0)
        );

        $this->vg->beginPath();
        $this->vg->rect(0, 0, $w, $h);
        $this->vg->fillPaint($paint);
        $this->vg->fill();
    }

    /**
     * Draws the game title.
     */
    private function drawTitle(float $w, float $h) : void
    {
        $this->vg->fontFace(self::FONT);
        $this->vg->fontSize(self::TITLE_FONT_SIZE);
        $this->vg->textAlign(VGAlign::LEFT | VGAlign::CENTER);
        $this->vg->fillColor($this->titleColor);
        $this->vg->text($w * 0.02, $h * 0.55, self::TITLE);
    }

    /**
     * Draws the copyright footer.
     */
    private function drawFooter(float $w, float $h) : void
    {
        $this->vg->fontFace(self::FONT);
        $this->vg->fontSize(12.0);
        $this->vg->textAlign(VGAlign::LEFT | VGAlign::BOTTOM);
        $this->vg->fillColor($this->mutedColor);
        $this->vg->text(8, $h - 8, self::COPYRIGHT);
    }

    /**
     * Computes the hit rect for every menu item.
     *
     * @return array<int, array{x: float, y: float, w: float, h: float}>
     */
    private function computeItemRects(float $w, float $h) : array
    {
        $x = $w * 0.02;
        $y = $h * 0.6;

        $this->vg->fontFace(self::FONT);
        $this->vg->fontSize(self::ITEM_FONT_SIZE);
        $this->vg->textAlign(VGAlign::LEFT | VGAlign::TOP);

        $rects = [];
        foreach ($this->itemKeys as $i => $key) {
            $bounds = new Vec4();
            $this->vg->textBounds(0, 0, $this->items[$key], $bounds);

            $rects[] = [
                'x' => $x,
                'y' => $y + $i * self::ITEM_LINE_HEIGHT,
                'w' => ($bounds->z - $bounds->x) + self::ITEM_HIT_PADDING * 2,
                'h' => self::ITEM_LINE_HEIGHT,
            ];
        }

        return $rects;
    }

    /**
     * Handles mouse and keyboard input for the menu.
     *
     * @param array<int, array{x: float, y: float, w: float, h: float}> $itemRects
     */
    private function handleInput(RenderTarget $renderTarget, array $itemRects) : void
    {
        $itemCount = count($this->itemKeys);

        // convert the cursor to VG point space
        $cursor = $this->input->getCursorPosition();
        $scale = $renderTarget->contentScaleX;
        $cursorX = $cursor->x / $scale;
        $cursorY = $cursor->y / $scale;

        $this->hoveredIndex = null;
        foreach ($itemRects as $i => $rect) {
            if ($cursorX >= $rect['x'] && $cursorX <= $rect['x'] + $rect['w']
                && $cursorY >= $rect['y'] && $cursorY <= $rect['y'] + $rect['h']) {
                $this->hoveredIndex = $i;
                break;
            }
        }

        if ($this->hoveredIndex !== null) {
            $this->selectedIndex = $this->hoveredIndex;
        }

        if ($this->input->hasKeyBeenPressedThisFrame(Key::DOWN) || $this->input->hasKeyBeenPressedThisFrame(Key::S)) {
            $this->selectedIndex = ($this->selectedIndex + 1) % $itemCount;
        }

        if ($this->input->hasKeyBeenPressedThisFrame(Key::UP) || $this->input->hasKeyBeenPressedThisFrame(Key::W)) {
            $this->selectedIndex = ($this->selectedIndex - 1 + $itemCount) % $itemCount;
        }

        $mouseClicked = $this->hoveredIndex !== null
            && $this->input->hasMouseButtonBeenPressedThisFrame(MouseButton::LEFT);
        $keyPressed = $this->input->hasKeyBeenPressedThisFrame(Key::ENTER)
            || $this->input->hasKeyBeenPressedThisFrame(Key::SPACE);

        if ($mouseClicked || $keyPressed) {
            $this->activate($this->itemKeys[$this->selectedIndex]);
        }
    }

    /**
     * Draws the menu items, highlighting the hovered/selected one.
     *
     * @param array<int, array{x: float, y: float, w: float, h: float}> $itemRects
     */
    private function drawItems(array $itemRects) : void
    {
        $this->vg->fontFace(self::FONT);
        $this->vg->fontSize(self::ITEM_FONT_SIZE);
        //$this->vg->textAlign(VGAlign::LEFT | VGAlign::TOP);

        foreach ($this->itemKeys as $i => $key) {
            $label = $this->items[$key];
            $rect = $itemRects[$i];
            $isActive = $i === $this->hoveredIndex || $i === $this->selectedIndex;

            if ($isActive) {
                $this->vg->beginPath();
                $this->vg->fillColor($this->hoverBackgroundColor);
                $this->vg->roundedRect($rect['x'] - 6, $rect['y'] - 2, $rect['w'] + 12, self::ITEM_LINE_HEIGHT - 6, 2.0);
                $this->vg->fill();

                $this->vg->fillColor($this->hoverColor);
            } else {
                switch ($label) {
                    case "LOAD GAME":
                    case "CONFIGURATION":
                    case "MULTIPLAYER":
                        $this->vg->fillColor($this->mutedColor);
                        break;
                    default:
                        $this->vg->fillColor($this->textColor);
                }
            }

            $this->vg->text($rect['x'], $rect['y'], $label);
        }
    }

    /**
     * Executes the action bound to the given menu item.
     */
    private function activate(string $key) : void
    {
        if ($key === 'quit') {
            $this->window->setShouldClose(true);
            return;
        }

        if ($key === 'new_game' && $this->onNewGame !== null) {
            ($this->onNewGame)();
            return;
        }

        //fwrite(STDOUT, 'TODO: ' . $key . ' not implemented yet' . PHP_EOL);
    }
}
