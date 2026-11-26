<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifestPath = $root.'/plugin.json';

if (! is_file($manifestPath)) {
    fwrite(STDERR, "Missing plugin.json\n");
    exit(1);
}

$manifest = json_decode((string) file_get_contents($manifestPath), true);
if (! is_array($manifest)) {
    fwrite(STDERR, "plugin.json must contain valid JSON\n");
    exit(1);
}

foreach (['id', 'name', 'version', 'api_version', 'entrypoint', 'class'] as $field) {
    if (! isset($manifest[$field]) || ! is_string($manifest[$field]) || trim($manifest[$field]) === '') {
        fwrite(STDERR, "Missing required manifest field: {$field}\n");
        exit(1);
    }
}

if ($manifest['id'] !== 'sports-teams') {
    fwrite(STDERR, "Expected plugin id sports-teams, found {$manifest['id']}\n");
    exit(1);
}

$entrypointPath = $root.'/'.ltrim($manifest['entrypoint'], '/');
if (! is_file($entrypointPath)) {
    fwrite(STDERR, "Missing entrypoint file: {$manifest['entrypoint']}\n");
    exit(1);
}

exec('php -l '.escapeshellarg($entrypointPath).' 2>&1', $output, $code);
if ($code !== 0) {
    fwrite(STDERR, implode("\n", $output)."\n");
    exit(1);
}

$tag = getenv('GITHUB_REF_NAME') ?: '';
if (str_starts_with($tag, 'v') && ltrim($tag, 'v') !== $manifest['version']) {
    fwrite(STDERR, "Tag {$tag} does not match plugin.json version {$manifest['version']}\n");
    exit(1);
}

echo "Validated sports-teams plugin files (v{$manifest['version']}).\n";
