<?php

use App\Support\Ddev\DdevProjectConfig;

function ddevProject(array $files): string
{
    $root = sys_get_temp_dir().'/ddev-config-'.bin2hex(random_bytes(6));

    mkdir($root.'/.ddev', recursive: true);

    foreach ($files as $name => $contents) {
        file_put_contents($root.'/.ddev/'.$name, $contents);
    }

    return $root;
}

it('reads the extra hosts a project answers on', function () {
    // Trimmed from mesh's own config, comments and all.
    $root = ddevProject(['config.yaml' => <<<'YAML'
    name: mesh
    type: laravel
    # One label, hyphenated: DNS wildcards match a single label.
    additional_hostnames:
      - timehub-mesh
    additional_fqdns: []
    YAML]);

    $config = DdevProjectConfig::read($root);

    expect($config->additionalHostnames)->toBe(['timehub-mesh'])
        ->and($config->additionalFqdns)->toBe([]);
});

it('reports nothing for a project that declares no extra host', function () {
    $config = DdevProjectConfig::read(ddevProject(['config.yaml' => "name: plain\ntype: laravel\n"]));

    expect($config->additionalHostnames)->toBe([])
        ->and($config->additionalFqdns)->toBe([]);
});

it('merges the config.*.yaml files ddev also reads', function () {
    $root = ddevProject([
        'config.yaml' => "name: mesh\nadditional_hostnames:\n  - timehub-mesh\n",
        'config.local.yaml' => "additional_hostnames:\n  - admin-mesh\nadditional_fqdns:\n  - mesh.test\n",
    ]);

    $config = DdevProjectConfig::read($root);

    expect($config->additionalHostnames)->toBe(['timehub-mesh', 'admin-mesh'])
        ->and($config->additionalFqdns)->toBe(['mesh.test']);
});

it('lets override_config replace an inherited list, the way ddev documents it', function () {
    $root = ddevProject([
        'config.yaml' => "name: mesh\nadditional_hostnames:\n  - timehub-mesh\n",
        'config.local.yaml' => "override_config: true\nadditional_hostnames: []\n",
    ]);

    expect(DdevProjectConfig::read($root)->additionalHostnames)->toBe([]);
});

it('survives a project whose config is missing or unparseable', function () {
    // A broken config costs the project its extra hosts, never its row.
    expect(DdevProjectConfig::read('/nowhere/at/all')->additionalHostnames)->toBe([]);

    $root = ddevProject(['config.yaml' => "additional_hostnames:\n  - one\n\t- tabs are not valid yaml\n"]);

    expect(DdevProjectConfig::read($root)->additionalHostnames)->toBe([]);
});
