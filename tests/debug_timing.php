<?php
require __DIR__ . '/../vendor/autoload.php';

$t = microtime(true);
$d = App\Game\GLTFMapLoader::parse(__DIR__ . '/../resources/maps/crossfire/scene.gltf', 0.02);
echo 'parse: ' . round(microtime(true) - $t, 2) . "s\n";
$t = microtime(true);
$r = App\Game\GLTFMapLoader::buildColliders($d);
echo 'colliders: ' . round(microtime(true) - $t, 2) . 's (' . count($r['colliders']) . " colliders)\n";
$t = microtime(true);
$g = count($d->meshes);
echo 'meshes: ' . $g . "\n";
