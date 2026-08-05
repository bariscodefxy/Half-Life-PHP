<?php

$g = json_decode((string) file_get_contents(__DIR__ . '/../resources/maps/crossfire/scene.gltf'), true);

echo 'images: ' . count($g['images']) . "\n";
$byUri = 0;
$byBufferView = 0;
foreach ($g['images'] as $i => $img) {
    if (isset($img['uri'])) {
        $byUri++;
        echo "  image $i uri=" . $img['uri'] . "\n";
    } else {
        $byBufferView++;
        echo "  image $i bufferView=" . ($img['bufferView'] ?? '?') . "\n";
    }
}
echo "byUri=$byUri byBufferView=$byBufferView\n";
echo 'textures: ' . count($g['textures']) . "\n";
foreach ($g['textures'] as $i => $t) {
    echo "  texture $i source=" . ($t['source'] ?? '?') . " sampler=" . ($t['sampler'] ?? '?') . "\n";
}
echo 'materials: ' . count($g['materials']) . "\n";
$withTex = 0;
$withFactor = 0;
foreach ($g['materials'] as $i => $m) {
    $pbr = $m['pbrMetallicRoughness'] ?? [];
    if (isset($pbr['baseColorTexture'])) {
        $withTex++;
        echo "  material $i baseColorTexture=" . $pbr['baseColorTexture']['index'] . " factor=" . json_encode($pbr['baseColorFactor'] ?? null) . " metallic=" . ($pbr['metallicFactor'] ?? 1) . " rough=" . ($pbr['roughnessFactor'] ?? 1) . "\n";
    } elseif (isset($pbr['baseColorFactor'])) {
        $withFactor++;
    }
}
echo "materials with baseColorTexture=$withTex withFactorOnly=$withFactor\n";

// image file sizes
foreach ($g['images'] as $i => $img) {
    if (isset($img['uri'])) {
        $p = __DIR__ . '/../resources/maps/crossfire/' . $img['uri'];
        if (is_file($p)) {
            $size = filesize($p);
            $first = strtoupper(bin2hex(substr((string) file_get_contents($p), 0, 8)));
            printf("  %s %8d bytes pngsig=%s\n", $img['uri'], $size, substr($first, 0, 16));
        }
    }
}
