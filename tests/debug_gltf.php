<?php

$g = json_decode(file_get_contents('resources/maps/crossfire/scene.gltf'), true);

echo "scene 0 nodes: " . json_encode($g['scenes'][0]['nodes'] ?? null) . "\n";
echo "nodes count: " . count($g['nodes']) . "\n\n";

foreach ($g['nodes'] as $i => $n) {
    if (isset($n['mesh']) || $i === 0) {
        echo "[$i] name={$n['name']}";
        if (isset($n['mesh'])) echo " mesh={$n['mesh']}";
        if (isset($n['matrix'])) echo " matrix=" . json_encode($n['matrix']);
        if (isset($n['scale'])) echo " scale=" . json_encode($n['scale']);
        if (isset($n['translation'])) echo " translation=" . json_encode($n['translation']);
        if (isset($n['rotation'])) echo " rotation=" . json_encode($n['rotation']);
        if (isset($n['children'])) echo " children=" . json_encode($n['children']);
        echo "\n";
    }
}
