<?php
declare(strict_types=1);

/** Read the root-owned, allowlisted deployment configuration. */
function everlompInstallerConfig(): array
{
    $path = '/run/everlomp/installer-config.json';
    if (!is_file($path) || !is_readable($path)) {
        throw new RuntimeException('Installer deployment configuration is unavailable.');
    }
    $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
    if (!is_array($data)) {
        throw new RuntimeException('Invalid installer deployment configuration.');
    }
    $result = [];
    foreach ($data as $name => $value) {
        if ($name === 'APP' && in_array($value, ['wordpress', 'drupal', 'phpbb', 'none'], true)) {
            $result[$name] = $value;
        } elseif (in_array($name, ['ENCRYPTION', 'HOTPOCKET', 'SSH', 'FILEGATOR', 'KOPIA',
            'SQLBACKUP', 'REPLICATION', 'MIGRATION', 'EXTERNAL', 'LOCAL', 'CLEANUP'], true)
            && in_array($value, [0, 1], true)) {
            $result[$name] = $value;
        } else {
            throw new RuntimeException('Invalid installer deployment option.');
        }
    }
    return $result;
}

/** null = customer choice; 0 = disabled; 1 = required. */
function everlompInstallerChoice(array $config, string $name): ?int
{
    return isset($config[$name]) && is_int($config[$name]) ? $config[$name] : null;
}

/** Only controls new installation; do not use to remove an installed app. */
function everlompInstallerAllowsApp(array $config, string $app): bool
{
    if (str_starts_with($app, 'external:') && everlompInstallerChoice($config, 'EXTERNAL') === 0) {
        return false;
    }
    if ($app === 'hotpocket-folder' && everlompInstallerChoice($config, 'HOTPOCKET') === 0) {
        return false;
    }
    return !isset($config['APP']) || $config['APP'] === $app;
}
