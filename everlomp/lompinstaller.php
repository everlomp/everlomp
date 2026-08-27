<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$databaseConfiguredFile = '/var/www/.everlomp/database.configured';
$databaseAdminUserFile  = '/var/www/.everlomp/database-admin-user';
$lswsPasswordConfiguredFile = '/var/www/.everlomp/lsws-password.configured';
$primaryAppFile         = '/home/everlomp/primary-app';
$wordpressInfoFile      = '/home/everlomp/wordpress.json';
$drupalInfoFile         = '/home/everlomp/drupal.json';
$phpbbInfoFile          = '/home/everlomp/phpbb.json';
$wpThemeManifestFile    = '/home/everlomp/wpaddons/themes.json';
$wpPluginManifestFile   = '/home/everlomp/wpaddons/plugins.json';
$phpmyadminIndexFile    = '/usr/local/everlomp/phpmyadmin/index.php';
$realIpProxyFile         = '/home/everlomp/realip-proxy';
$realIpFailedFile        = '/home/everlomp/realip-failed';
$hotpocketEnabledFile     = '/var/www/.everlomp/hotpocket.enabled';
$filegatorInfoFile        = '/var/www/.everlomp-filegator/info.json';
$filegatorIndexFile       = '/var/www/.everlomp-filegator/app/dist/index.php';
$backupInfoFile           = '/home/everlomp/backup.json';
$kopiaInfoFile            = '/home/everlomp/kopia.json';
$kopiaConfigFile          = '/home/everlomp/secrets/kopia/repository.config';
$kopiaEnabledFile         = '/home/everlomp/kopia/enabled';
$sqlBackupDir             = '/home/everlomp/everbackups/sql';
$externalInstallerDir      = '/home/everlomp/external-installs';
$externalInstallerExampleFile = '/home/everlomp/external-installer-example.zip';
$sshConfiguredFile       = '/var/www/.everlomp/ssh.configured';
$contractEnvFile         = '/contract/env.vars';

$scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/install.php'));
$panelUrl = $scriptName !== '' && str_starts_with($scriptName, '/') ? $scriptName : '/install.php';

if (!isset($_SESSION['everlomp_key_replace_csrf']) || !is_string($_SESSION['everlomp_key_replace_csrf']) || strlen($_SESSION['everlomp_key_replace_csrf']) < 32) {
    $_SESSION['everlomp_key_replace_csrf'] = bin2hex(random_bytes(32));
}
$keyReplaceCsrf = (string) $_SESSION['everlomp_key_replace_csrf'];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function runEverlompHelper(string $helper, string $action, string $stdin = '', ?string $runAs = null): array
{
    $command = ['sudo', '-n'];
    if ($runAs !== null) {
        $command[] = '-u';
        $command[] = $runAs;
    }
    $command[] = $helper;
    $command[] = $action;

    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);

    if (!is_resource($process)) {
        return [1, '', 'Could not start the Everlomp helper.'];
    }

    $stdinLength = strlen($stdin);
    $stdinOffset = 0;
    while ($stdinOffset < $stdinLength) {
        $written = fwrite($pipes[0], substr($stdin, $stdinOffset));
        if ($written === false || $written === 0) {
            fclose($pipes[0]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_terminate($process);
            proc_close($process);
            return [1, '', 'Could not send data to the Everlomp helper.'];
        }
        $stdinOffset += $written;
    }
    fclose($pipes[0]);

    $stdout = trim((string) stream_get_contents($pipes[1]));
    fclose($pipes[1]);

    $stderr = trim((string) stream_get_contents($pipes[2]));
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0 && str_contains(strtolower($stderr), 'password is required')) {
        $uid = function_exists('posix_geteuid') ? (int) posix_geteuid() : -1;
        $user = '';
        if ($uid >= 0 && function_exists('posix_getpwuid')) {
            $pw = posix_getpwuid($uid);
            if (is_array($pw) && isset($pw['name'])) {
                $user = (string) $pw['name'];
            }
        }
        $identity = $user !== '' ? $user . ' (uid ' . $uid . ')' : 'uid ' . $uid;
        $stderr .= ' [PHP worker: ' . $identity . ']';
    }

    return [$exitCode, $stdout, $stderr];
}

function readTextFile(string $file): string
{
    if (!is_readable($file)) {
        return '';
    }

    return trim((string) file_get_contents($file));
}

function readJsonFile(string $file): array
{
    if (!is_readable($file)) {
        return [];
    }

    $decoded = json_decode((string) file_get_contents($file), true);

    return is_array($decoded) ? $decoded : [];
}

function readContractPort(string $file, string $name): string
{
    if (!is_readable($file) || !preg_match('/^[A-Z0-9_]+$/', $name)) {
        return '';
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return '';
    }

    $pattern = '/^\s*(?:export\s+)?' . preg_quote($name, '/') . '\s*=\s*[\"\']?([0-9]+)[\"\']?\s*(?:#.*)?$/';
    foreach ($lines as $line) {
        if (preg_match($pattern, $line, $match) === 1) {
            $port = (int) $match[1];
            return ($port >= 1 && $port <= 65535) ? (string) $port : '';
        }
    }

    return '';
}

function readContractValue(string $file, string $name): string
{
    if (!is_readable($file) || !preg_match('/^[A-Z0-9_]+$/', $name)) {
        return '';
    }

    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if (!is_array($lines)) {
        return '';
    }

    $pattern = '/^\s*(?:export\s+)?' . preg_quote($name, '/') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^#\s]+))\s*(?:#.*)?$/';
    foreach ($lines as $line) {
        if (preg_match($pattern, $line, $match) === 1) {
            return trim((string) ($match[1] !== '' ? $match[1] : ($match[2] !== '' ? $match[2] : ($match[3] ?? ''))));
        }
    }

    return '';
}

function readWpAddonManifest(string $file, string $key): array
{
    $data = readJsonFile($file);
    $items = $data[$key] ?? [];

    if (!is_array($items)) {
        return [];
    }

    $result = [];

    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }

        $slug = trim((string) ($item['slug'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));
        $fileName = trim((string) ($item['file'] ?? ''));

        if (
            $slug === ''
            || $name === ''
            || !preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug)
            || ($fileName !== '' && !preg_match('/^[A-Za-z0-9._-]+\.zip$/', $fileName))
        ) {
            continue;
        }

        $localPath = $fileName !== '' ? dirname($file) . '/' . $fileName : '';

        $item['slug'] = $slug;
        $item['name'] = $name;
        $item['file'] = $fileName;
        $item['local_available'] = $localPath !== ''
            && is_file($localPath)
            && filesize($localPath) > 0;
        $result[] = $item;
    }

    return $result;
}

function readExternalInstallerPackages(string $dir): array
{
    if (!is_dir($dir) || !is_readable($dir)) {
        return [];
    }

    $packages = [];
    $entries = scandir($dir);
    if (!is_array($entries)) {
        return [];
    }

    foreach ($entries as $entry) {
        if ($entry === '.' || $entry === '..' || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $entry)) {
            continue;
        }

        $packageDir = $dir . '/' . $entry;
        $manifestFile = $packageDir . '/manifest.json';
        $installFile = $packageDir . '/install.sh';
        if (!is_dir($packageDir) || is_link($packageDir) || !is_readable($manifestFile) || !is_file($installFile) || is_link($installFile)) {
            continue;
        }

        $manifest = readJsonFile($manifestFile);
        $id = trim((string) ($manifest['id'] ?? ''));
        $name = trim((string) ($manifest['name'] ?? ''));
        if (
            ($manifest['schema'] ?? null) !== 1
            || $id !== $entry
            || !preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id)
            || $name === ''
            || strlen($name) > 80
            || (string) ($manifest['type'] ?? 'primary') !== 'primary'
            || (string) ($manifest['entrypoint'] ?? 'install.sh') !== 'install.sh'
        ) {
            continue;
        }

        $fields = $manifest['fields'] ?? [];
        if (!is_array($fields)) {
            $fields = [];
        }
        $manifest['fields'] = array_values(array_filter($fields, static function (mixed $field): bool {
            if (!is_array($field)) return false;
            $name = trim((string) ($field['name'] ?? ''));
            $type = trim((string) ($field['type'] ?? 'text'));
            return preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name) === 1
                && in_array($type, ['text', 'email', 'password', 'url', 'number', 'checkbox', 'select', 'textarea'], true);
        }));
        $manifest['package_dir'] = $packageDir;
        $manifest['terms'] = is_readable($packageDir . '/terms.md')
            ? trim((string) file_get_contents($packageDir . '/terms.md'))
            : '';
        $packages[$id] = $manifest;
    }

    uasort($packages, static fn(array $a, array $b): int => strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? '')));
    return $packages;
}

function externalInstallerIdFromPrimary(string $primaryApp): string
{
    if (!str_starts_with($primaryApp, 'external:')) {
        return '';
    }

    $id = substr($primaryApp, strlen('external:'));
    return preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $id) === 1 ? $id : '';
}

function externalInstallerRequirementError(
    array $manifest,
    bool $databaseConfigured,
    bool $lswsPasswordConfigured,
    bool $realIpReady,
    bool $domainOnlyReady
): string {
    $requires = $manifest['requires'] ?? [];
    if (!is_array($requires)) {
        $requires = [];
    }

    if (($requires['database'] ?? false) === true && !$databaseConfigured) {
        return 'This external installer requires MariaDB setup first.';
    }
    if (($requires['openlitespeed_password'] ?? false) === true && !$lswsPasswordConfigured) {
        return 'This external installer requires the OpenLiteSpeed WebAdmin password to be configured first.';
    }
    if (($requires['real_ip'] ?? false) === true && !$realIpReady) {
        return 'This external installer requires working real-client-IP forwarding first.';
    }
    if (($requires['domain'] ?? false) === true && !$domainOnlyReady) {
        return 'This external installer requires opening Everlomp through an HTTPS domain without an explicit port.';
    }

    return '';
}

function validateExternalInstallerFields(array $manifest, mixed $submitted): array
{
    $submitted = is_array($submitted) ? $submitted : [];
    $values = [];
    $error = '';

    foreach (($manifest['fields'] ?? []) as $field) {
        if (!is_array($field)) continue;
        $name = trim((string) ($field['name'] ?? ''));
        $type = trim((string) ($field['type'] ?? 'text'));
        if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) continue;

        $required = (($field['required'] ?? false) === true);
        $maxLength = (int) ($field['max_length'] ?? 5000);
        if ($maxLength < 1 || $maxLength > 10000) $maxLength = 5000;

        if ($type === 'checkbox') {
            $value = array_key_exists($name, $submitted) && (string) $submitted[$name] === '1';
            if ($required && !$value) {
                $error = 'Accept the required option: ' . (string) ($field['label'] ?? $name) . '.';
                break;
            }
            $values[$name] = $value;
            continue;
        }

        $raw = $submitted[$name] ?? ($field['default'] ?? '');
        if (is_array($raw)) {
            $error = 'Invalid value for ' . (string) ($field['label'] ?? $name) . '.';
            break;
        }
        $value = (string) $raw;
        if (strlen($value) > $maxLength) {
            $error = (string) ($field['label'] ?? $name) . ' is too long.';
            break;
        }
        if ($required && trim($value) === '') {
            $error = (string) ($field['label'] ?? $name) . ' is required.';
            break;
        }
        if ($type === 'email' && $value !== '' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid email address for ' . (string) ($field['label'] ?? $name) . '.';
            break;
        }
        if ($type === 'url' && $value !== '' && !filter_var($value, FILTER_VALIDATE_URL)) {
            $error = 'Enter a valid URL for ' . (string) ($field['label'] ?? $name) . '.';
            break;
        }
        if ($type === 'number' && $value !== '' && !is_numeric($value)) {
            $error = 'Enter a valid number for ' . (string) ($field['label'] ?? $name) . '.';
            break;
        }
        if ($type === 'select') {
            $options = is_array($field['options'] ?? null) ? $field['options'] : [];
            $allowed = [];
            foreach ($options as $option) {
                if (is_string($option)) $allowed[] = $option;
                elseif (is_array($option)) $allowed[] = (string) ($option['value'] ?? '');
            }
            if (!in_array($value, $allowed, true)) {
                $error = 'Choose a valid option for ' . (string) ($field['label'] ?? $name) . '.';
                break;
            }
        }
        $values[$name] = $value;
    }

    return [$values, $error];
}

function wpAddonSlugs(array $items): array
{
    return array_values(array_map(
        static fn(array $item): string => (string) $item['slug'],
        $items
    ));
}

function normalizedStringList(mixed $value): array
{
    if (!is_array($value)) {
        return [];
    }

    $result = [];

    foreach ($value as $item) {
        if (!is_string($item)) {
            continue;
        }

        $item = trim($item);
        if ($item !== '') {
            $result[] = $item;
        }
    }

    return array_values(array_unique($result));
}

/**
 * Re-read Kopia state after root-owned helpers may have created or changed
 * files during this same PHP request. PHP caches stat()/is_file()/is_readable()
 * results, so without clearstatcache() a successful first-time setup can be
 * rendered as "not configured" until the next request.
 *
 * @return array{0: array, 1: bool, 2: bool}
 */
function readKopiaState(
    string $infoFile,
    string $configFile,
    string $enabledFile
): array {
    clearstatcache(true, $infoFile);
    clearstatcache(true, $configFile);
    clearstatcache(true, $enabledFile);

    $info = readJsonFile($infoFile);

    $enabled = is_file($enabledFile)
        || (($info['enabled'] ?? false) === true);

    // repository.config can be created well before first-time installation
    // has finished. Only persistent completion metadata / enablement counts as
    // configured, with repository_path retained for older completed installs.
    $configured = (($info['configured'] ?? false) === true)
        || trim((string) ($info['repository_path'] ?? '')) !== '';

    return [$info, $enabled, $configured];
}

function kopiaBuildToolsReady(): bool
{
    clearstatcache(true, '/usr/bin/git');
    clearstatcache(true, '/usr/bin/go');

    return is_executable('/usr/bin/git') && is_executable('/usr/bin/go');
}

if (
    (is_file($backupInfoFile) && !is_readable($backupInfoFile))
    || (is_file($kopiaInfoFile) && !is_readable($kopiaInfoFile))
) {
    runEverlompHelper(
        '/usr/local/sbin/everlomp-backup',
        'repair-state-permissions'
    );

    clearstatcache(true, $backupInfoFile);
    clearstatcache(true, $kopiaInfoFile);
}

function firstForwardedIp(): string
{
    $xff = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));

    if ($xff !== '') {
        $first = trim(explode(',', $xff)[0] ?? '');

        if ($first !== '') {
            return $first;
        }
    }

    return trim((string) ($_SERVER['HTTP_X_REAL_IP'] ?? ''));
}

function isPublicIp(string $ip): bool
{
    if ($ip === '') {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}

function isPrivateOrLoopbackIp(string $ip): bool
{
    if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    if ($ip === '127.0.0.1' || $ip === '::1') {
        return true;
    }

    return !isPublicIp($ip);
}

function validateCommonDatabaseFields(
    string $host,
    string $port,
    string $name,
    string $user,
    string $password
): string {
    if ($host === '' || strlen($host) > 255 || preg_match('/[\r\n]/', $host)) {
        return 'Enter a valid database host.';
    }

    if ($port !== '' && (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535)) {
        return 'Database port must be blank or between 1 and 65535.';
    }

    if ($name === '' || strlen($name) > 64 || preg_match('/[\r\n]/', $name)) {
        return 'Enter a valid database name.';
    }

    if ($user === '' || strlen($user) > 128 || preg_match('/[\r\n]/', $user)) {
        return 'Enter a valid database username.';
    }

    if (preg_match('/[\r\n]/', $password)) {
        return 'Database password contains unsupported control characters.';
    }

    return '';
}

function readEverlompKeyStatus(): array
{
    [$code, $stdout, $stderr] = runEverlompHelper('/usr/local/sbin/everlomp-key', 'status');
    if ($code !== 0 || $stdout === '') {
        return [
            'ok' => false,
            'mode' => 'undecided',
            'key_valid' => false,
            'key_error' => $stderr !== '' ? $stderr : 'Could not read Everlomp key status.',
            'key_fingerprint' => '',
            'instance' => '',
            'ready' => false,
            'services' => [],
        ];
    }
    $decoded = json_decode($stdout, true);
    return is_array($decoded) ? $decoded : [
        'ok' => false,
        'mode' => 'undecided',
        'key_valid' => false,
        'key_error' => 'Everlomp key helper returned invalid status data.',
        'key_fingerprint' => '',
        'instance' => '',
        'ready' => false,
        'services' => [],
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['key_status'] ?? '') === '1') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    echo json_encode(readEverlompKeyStatus(), JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'key_set_mode') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');
    $requestedMode = trim((string) ($_POST['mode'] ?? ''));
    if (!in_array($requestedMode, ['pending', 'disabled', 'enabled'], true)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'message' => 'Invalid key mode.']);
        exit;
    }
    [$code, $stdout, $stderr] = runEverlompHelper(
        '/usr/local/sbin/everlomp-key',
        'set-mode',
        $requestedMode . "\n"
    );
    if ($code !== 0) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'message' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not save key mode.'),
        ]);
        exit;
    }
    $payload = json_decode($stdout, true);

    // Remember the browser that deliberately entered the wait-for-Swarm-key
    // flow. PHP sessions are task-local and can disappear when Swarm replaces
    // the container, while this cookie survives in the browser. On the new
    // task it lets us auto-finish the already-approved encryption choice
    // instead of showing the mounted-key choice a second time.
    if ($requestedMode === 'pending') {
        setcookie('everlomp_key_wait', '1', [
            'expires' => time() + 3600,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    } else {
        setcookie('everlomp_key_wait', '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    echo json_encode(is_array($payload) ? $payload : ['ok' => true]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'key_replace_task') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, max-age=0');

    $csrf = (string) ($_POST['csrf'] ?? '');
    if ($csrf === '' || !hash_equals($keyReplaceCsrf, $csrf)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'The task-replacement request expired. Reload this page and try again.']);
        exit;
    }

    $current = readEverlompKeyStatus();
    $mode = (string) ($current['mode'] ?? 'undecided');
    $valid = (($current['key_valid'] ?? false) === true);
    if ($valid) {
        echo json_encode(['ok' => true, 'replacement_needed' => false, 'message' => 'A valid Swarm key is already mounted.']);
        exit;
    }
    if (!in_array($mode, ['pending', 'enabled'], true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'message' => 'Task replacement is only allowed while encryption key setup is waiting for a key.']);
        exit;
    }

    [$code, $stdout, $stderr] = runEverlompHelper('/usr/local/sbin/everlomp-key', 'replace-task');
    if ($code !== 0) {
        http_response_code(409);
        echo json_encode([
            'ok' => false,
            'message' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not request a Swarm task replacement.'),
        ]);
        exit;
    }
    $payload = json_decode($stdout, true);
    echo json_encode(is_array($payload) ? $payload : ['ok' => true, 'replacement_needed' => true]);
    exit;
}

$keyStatus = readEverlompKeyStatus();
$keyMode = (string) ($keyStatus['mode'] ?? 'undecided');
$keyValid = (($keyStatus['key_valid'] ?? false) === true);

// If this browser already chose encryption and waited for a Swarm task
// replacement, do not ask it to approve the same mounted key again. The
// persistent key-mode file normally carries this state across tasks; the
// browser marker is a fallback for deployments where that state directory is
// task-local. A browser that never entered the wait flow still gets the normal
// "Use mounted key" choice.
$keyWaitCompletedByBrowser = ((string) ($_COOKIE['everlomp_key_wait'] ?? '')) === '1';
if ($keyWaitCompletedByBrowser && $keyValid && in_array($keyMode, ['undecided', 'pending'], true)) {
    [$autoEnableCode] = runEverlompHelper(
        '/usr/local/sbin/everlomp-key',
        'set-mode',
        "enabled\n"
    );
    if ($autoEnableCode === 0) {
        $keyStatus = readEverlompKeyStatus();
        $keyMode = (string) ($keyStatus['mode'] ?? 'undecided');
        $keyValid = (($keyStatus['key_valid'] ?? false) === true);
    }
}
if ($keyWaitCompletedByBrowser && $keyMode === 'enabled' && $keyValid) {
    setcookie('everlomp_key_wait', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}

$keyReadyForInstaller = $keyMode === 'disabled' || ($keyMode === 'enabled' && $keyValid);
$keyGateRequired = !$keyReadyForInstaller;

if ((string) ($_GET['download'] ?? '') === 'external-installer-example') {
    if (!is_readable($externalInstallerExampleFile) || !is_file($externalInstallerExampleFile)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo "External installer example ZIP was not found.
";
        exit;
    }
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="everlomp-external-installer-example.zip"');
    header('Content-Length: ' . (string) filesize($externalInstallerExampleFile));
    header('X-Content-Type-Options: nosniff');
    readfile($externalInstallerExampleFile);
    exit;
}

$databaseConfigured = is_file($databaseConfiguredFile);
$lswsPasswordConfigured = is_file($lswsPasswordConfiguredFile);
$databaseAdminUser = readTextFile($databaseAdminUserFile);
$primaryApp = readTextFile($primaryAppFile);
$wordpressInfo = readJsonFile($wordpressInfoFile);
$drupalInfo = readJsonFile($drupalInfoFile);
$phpbbInfo = readJsonFile($phpbbInfoFile);
$externalInstallers = readExternalInstallerPackages($externalInstallerDir);
$externalInstalledId = externalInstallerIdFromPrimary($primaryApp);
$wpThemes = readWpAddonManifest($wpThemeManifestFile, 'themes');
$wpPlugins = readWpAddonManifest($wpPluginManifestFile, 'plugins');
$wpThemeSlugs = wpAddonSlugs($wpThemes);
$wpPluginSlugs = wpAddonSlugs($wpPlugins);
$wpThemeLocalAvailableSlugs = wpAddonSlugs(array_values(array_filter(
    $wpThemes,
    static fn(array $item): bool => (($item['local_available'] ?? false) === true)
)));
$wpPluginLocalAvailableSlugs = wpAddonSlugs(array_values(array_filter(
    $wpPlugins,
    static fn(array $item): bool => (($item['local_available'] ?? false) === true)
)));

$wpSelectedThemeSlugs = array_values(array_map(
    static fn(array $item): string => (string) $item['slug'],
    array_filter($wpThemes, static fn(array $item): bool => (($item['default'] ?? false) === true))
));
$wpSelectedPluginSlugs = array_values(array_map(
    static fn(array $item): string => (string) $item['slug'],
    array_filter($wpPlugins, static fn(array $item): bool => (($item['default'] ?? false) === true))
));
$wpInstallLocalThemeSlugs = [];
$wpInstallLocalPluginSlugs = [];
$wpActivateThemeSlugs = array_values(array_intersect(
    array_values(array_map(
        static fn(array $item): string => (string) $item['slug'],
        array_filter($wpThemes, static fn(array $item): bool => (($item['activate'] ?? false) === true))
    )),
    $wpSelectedThemeSlugs
));
$wpActivatePluginSlugs = array_values(array_intersect(
    array_values(array_map(
        static fn(array $item): string => (string) $item['slug'],
        array_filter($wpPlugins, static fn(array $item): bool => (($item['activate'] ?? false) === true))
    )),
    $wpSelectedPluginSlugs
));

if (
    $_SERVER['REQUEST_METHOD'] === 'POST'
    && (string) ($_POST['action'] ?? '') === 'install_wordpress'
) {
    $wpSelectedThemeSlugs = normalizedStringList($_POST['wp_themes'] ?? []);
    $wpSelectedPluginSlugs = normalizedStringList($_POST['wp_plugins'] ?? []);
    $wpInstallLocalThemeSlugs = normalizedStringList($_POST['wp_theme_install_local'] ?? []);
    $wpInstallLocalPluginSlugs = normalizedStringList($_POST['wp_plugin_install_local'] ?? []);
    $wpActivateThemeSlugs = normalizedStringList($_POST['wp_theme_activate'] ?? []);
    $wpActivatePluginSlugs = normalizedStringList($_POST['wp_plugin_activate'] ?? []);
}

$wordpressInstalled = $primaryApp === 'wordpress';
$drupalInstalled = $primaryApp === 'drupal';
$phpbbInstalled = $primaryApp === 'phpbb';
$phpmyadminInstalled = is_file($phpmyadminIndexFile);
$hotpocketEnabled = is_file($hotpocketEnabledFile);
$filegatorInstalled = is_file($filegatorIndexFile);
$filegatorInfo = readJsonFile($filegatorInfoFile);
$backupInfo = readJsonFile($backupInfoFile);
$sshDecision = readTextFile($sshConfiguredFile);
$sshDecisionMade = in_array($sshDecision, ['enabled', 'disabled'], true);
$sshEnabled = $sshDecision === 'enabled';
$sshPublicPort = readContractPort($contractEnvFile, 'EXTERNAL_GPTCP2_PORT');
$sshHostDomain = readContractValue($contractEnvFile, 'HOST_DOMAIN_ADDRESS');
$sshMappingReady = $sshPublicPort !== '' && $sshHostDomain !== '';
[$kopiaInfo, $kopiaEnabled, $kopiaConfigured] = readKopiaState(
    $kopiaInfoFile,
    $kopiaConfigFile,
    $kopiaEnabledFile
);
$kopiaBuildToolsReady = kopiaBuildToolsReady();
$kopiaLocalKopiaPath = '/usr/local/share/everlomp/kopia-local/kopia_source.tar.gz';
$kopiaLocalHtmluiPath = '/usr/local/share/everlomp/kopia-local/htmlui_source.tar.gz';
$kopiaLocalAvailable = is_file($kopiaLocalKopiaPath) && filesize($kopiaLocalKopiaPath) > 0
    && is_file($kopiaLocalHtmluiPath) && filesize($kopiaLocalHtmluiPath) > 0;

$realIpTrustedProxy = readTextFile($realIpProxyFile);
$realIpFailedProxy = readTextFile($realIpFailedFile);
$requestPeerIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$forwardedClientIp = firstForwardedIp();

$realIpAutoFailed = (
    $realIpFailedProxy !== ''
    && hash_equals($realIpFailedProxy, $requestPeerIp)
);

$realIpReady = (
    $realIpTrustedProxy !== ''
    && isPublicIp($requestPeerIp)
);

$realIpCanConfigure = (
    isPrivateOrLoopbackIp($requestPeerIp)
    && isPublicIp($forwardedClientIp)
);

$host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
$detectedSiteUrl = $host !== '' ? 'https://' . $host : '';
$domainOnlyReady = $host !== ''
    && !str_contains($host, ':')
    && filter_var($host, FILTER_VALIDATE_IP) === false
    && preg_match('/^[A-Za-z0-9.-]+$/', $host) === 1
    && str_contains($host, '.');

$publicBaseUrl = $domainOnlyReady ? 'https://' . $host : '';
$phpMyAdminDashboardUrl = $publicBaseUrl !== '' ? $publicBaseUrl . '/phpmyadmin/' : '/phpmyadmin/';
$openLiteSpeedDashboardUrl = $publicBaseUrl !== '' ? $publicBaseUrl . '/openlitespeed/' : '/openlitespeed/';
$fileGatorDashboardUrl = $publicBaseUrl !== '' ? $publicBaseUrl . '/filegator/' : '/filegator/';
$kopiaDashboardUrl = $publicBaseUrl !== '' ? $publicBaseUrl . '/kopia/' : '/kopia/';
$kopiaReplicationDashboardUrl = $publicBaseUrl !== '' ? $publicBaseUrl . '/kopia/replication' : '/kopia/replication';
$wordpressBaseUrl = rtrim((string) ($wordpressInfo['site_url'] ?? ''), '/');
if ($wordpressBaseUrl === '') $wordpressBaseUrl = $publicBaseUrl;
$wordpressAdminDashboardUrl = $wordpressBaseUrl !== '' ? $wordpressBaseUrl . '/wp-admin/' : '/wp-admin/';
$drupalBaseUrl = rtrim((string) ($drupalInfo['site_url'] ?? ''), '/');
if ($drupalBaseUrl === '') $drupalBaseUrl = $publicBaseUrl;
$drupalAdminDashboardUrl = $drupalBaseUrl !== '' ? $drupalBaseUrl . '/admin/' : '/admin/';
$phpbbBaseUrl = rtrim((string) ($phpbbInfo['site_url'] ?? ''), '/');
if ($phpbbBaseUrl === '') $phpbbBaseUrl = $publicBaseUrl;
$phpbbAdminDashboardUrl = $phpbbBaseUrl !== '' ? $phpbbBaseUrl . '/adm/' : '/adm/';
$externalInstalledManifest = ($externalInstalledId !== '' && isset($externalInstallers[$externalInstalledId]))
    ? $externalInstallers[$externalInstalledId]
    : [];
$externalSitePath = (string) ($externalInstalledManifest['site_path'] ?? '/');
if ($externalSitePath === '' || !str_starts_with($externalSitePath, '/')) $externalSitePath = '/';
$externalSiteUrl = $publicBaseUrl !== ''
    ? rtrim($publicBaseUrl, '/') . $externalSitePath
    : $externalSitePath;

$kopiaUrl = '/kopia/';

$everlompTermsFile = '/home/everlomp/terms.md';
$everlompTerms = is_readable($everlompTermsFile)
    ? trim((string) file_get_contents($everlompTermsFile))
    : '';

$termsAccepted = (($_SESSION['everlomp_terms_accepted'] ?? false) === true);
$action = '';

$message = '';
$error = '';
$lswsGeneratedUsername = '';
$lswsGeneratedPassword = '';
$filegatorGeneratedUsername = '';
$filegatorGeneratedPassword = '';
$filegatorGeneratedRoot = '';
$filegatorGeneratedVersion = '';
$kopiaGeneratedUsername = '';
$kopiaGeneratedPassword = '';
$kopiaGeneratedRepository = '';
$kopiaGeneratedVersion = '';
$sshGeneratedUsername = '';
$sshGeneratedPassword = '';

if (($_GET['database'] ?? '') === 'created') {
    $message = 'MariaDB administrator account created.';
}

if (($_GET['wordpress'] ?? '') === 'installed') {
    $message = 'WordPress installed successfully.';
}

if (($_GET['drupal'] ?? '') === 'installed') {
    $message = 'Drupal installed successfully.';
}

if (($_GET['phpbb'] ?? '') === 'installed') {
    $message = 'phpBB installed successfully.';
}

if (($_GET['external'] ?? '') === 'installed') {
    $message = 'External application installed successfully.';
}

if (($_GET['realip'] ?? '') === 'configured') {
    $message = 'Real client IP forwarding configured successfully.';
}

if (($_GET['hotpocket'] ?? '') === 'enabled') {
    $message = 'HotPocket enabled. Supervisor will now autostart it and keep it running.';
}

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $termsAccepted
    && !$realIpReady
    && !$realIpAutoFailed
    && $realIpCanConfigure
    && (($_GET['realip'] ?? '') !== 'configured')
) {
    [$code, $stdout, $stderr] = runEverlompHelper(
        '/usr/local/sbin/everlomp-realip',
        'configure',
        $requestPeerIp . "\n"
    );

    if ($code === 0) {
        header('Location: ' . $panelUrl . '?realip=configured');
        exit;
    }

    $error = $stderr !== ''
        ? $stderr
        : ($stdout !== '' ? $stdout : 'Automatic real-IP configuration failed.');

    $error .= "\nEverlomp will not automatically retry this same proxy peer. Use Retry Real IP below after fixing the problem.";
    $realIpAutoFailed = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if (!$keyReadyForInstaller) {
        $error = 'Choose whether Everlomp should use /run/secrets/key before continuing installation.';
        $action = '__blocked_until_key_choice_is_finalized__';
    }

    if ($action === 'accept_terms') {
        if ((string) ($_POST['accept_everlomp_terms'] ?? '') !== '1') {
            $error = 'You must read and accept the Everlomp installation terms before continuing.';
        } else {
            $_SESSION['everlomp_terms_accepted'] = true;
            $termsAccepted = true;
            $message = 'Installation terms accepted. You can continue with server setup.';
        }
    }

    if (!$termsAccepted && $action !== 'accept_terms') {
        $error = 'Accept the Everlomp installation terms before changing or installing anything.';
        $action = '__blocked_until_terms_are_accepted__';
    }

    if ($action === 'test_kopia_offsite_connection') {
        header('Content-Type: application/json; charset=utf-8');

        $type = (string) ($_POST['kopia_offsite_type'] ?? 'sftp');
        $payload = [
            'enabled' => true,
            'type' => $type,
            'schedule' => 'hourly',
            'time' => '00:00',
            'weekday' => 0,
            'sftp' => [
                'host' => trim((string) ($_POST['kopia_offsite_sftp_host'] ?? '')),
                'port' => (int) ($_POST['kopia_offsite_sftp_port'] ?? 22),
                'username' => trim((string) ($_POST['kopia_offsite_sftp_username'] ?? '')),
                'path' => trim((string) ($_POST['kopia_offsite_sftp_path'] ?? 'everbackups/')),
                'auth_mode' => (string) ($_POST['kopia_offsite_sftp_auth'] ?? 'key'),
                'password' => (string) ($_POST['kopia_offsite_sftp_password'] ?? ''),
                'private_key' => (string) ($_POST['kopia_offsite_sftp_private_key'] ?? ''),
                'known_hosts' => (string) ($_POST['kopia_offsite_sftp_known_hosts'] ?? ''),
            ],
            'webdav' => [
                'url' => trim((string) ($_POST['kopia_offsite_webdav_url'] ?? '')),
                'username' => trim((string) ($_POST['kopia_offsite_webdav_username'] ?? '')),
                'password' => (string) ($_POST['kopia_offsite_webdav_password'] ?? ''),
            ],
            'filesystem' => [
                'path' => trim((string) ($_POST['kopia_offsite_filesystem_path'] ?? '/remote-backup/kopia')),
            ],
            's3' => [
                'endpoint' => trim((string) ($_POST['kopia_offsite_endpoint'] ?? '')),
                'bucket' => trim((string) ($_POST['kopia_offsite_bucket'] ?? '')),
                'region' => trim((string) ($_POST['kopia_offsite_region'] ?? '')),
                'prefix' => trim((string) ($_POST['kopia_offsite_prefix'] ?? '')),
                'access_key' => trim((string) ($_POST['kopia_offsite_access_key'] ?? '')),
                'secret_access_key' => (string) ($_POST['kopia_offsite_secret_key'] ?? ''),
                'session_token' => (string) ($_POST['kopia_offsite_session_token'] ?? ''),
            ],
            'from_config' => [
                'token' => trim((string) ($_POST['kopia_offsite_kopia_token'] ?? '')),
            ],
            'rclone' => [
                'remote_path' => trim((string) ($_POST['kopia_offsite_rclone_remote_path'] ?? '')),
                'config' => (string) ($_POST['kopia_offsite_rclone_config'] ?? ''),
            ],
        ];

        try {
            if ($type === 'sftp') {
                $sftpTestPayload = [
                    'host' => $payload['sftp']['host'],
                    'port' => $payload['sftp']['port'],
                    'username' => $payload['sftp']['username'],
                    'auth_mode' => $payload['sftp']['auth_mode'],
                    'password' => $payload['sftp']['password'],
                    'private_key' => $payload['sftp']['private_key'],
                    'known_hosts' => $payload['sftp']['known_hosts'],
                ];
                [$code, $stdout, $stderr] = runEverlompHelper(
                    '/usr/local/sbin/everlomp-kopia-replication',
                    'test-sftp-auth',
                    json_encode($sftpTestPayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'everlomp'
                );
                if ($code === 0 && $stdout !== '') {
                    $decoded = json_decode($stdout, true);
                    if (is_array($decoded) && !empty($decoded['detail'])) {
                        $stdout = (string) $decoded['detail'];
                    }
                }
            } else {
                [$code, $stdout, $stderr] = runEverlompHelper(
                    '/usr/local/sbin/everlomp-backup',
                    'test-offsite-connection',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
            }

            $detail = $code === 0
                ? ($stdout !== '' ? $stdout : 'Connection test succeeded.')
                : ($stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Connection test failed.'));

            echo json_encode([
                'ok' => $code === 0,
                'message' => $detail,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode([
                'ok' => false,
                'message' => 'Could not run the connection test.',
            ]);
        }
        exit;
    }

    if ($action === 'fetch_kopia_sftp_host_key') {
        header('Content-Type: application/json; charset=utf-8');
        $host = trim((string) ($_POST['kopia_offsite_sftp_host'] ?? ''));
        $port = (int) ($_POST['kopia_offsite_sftp_port'] ?? 22);
        if ($host === '' || strlen($host) > 255 || $port < 1 || $port > 65535) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'message' => 'Enter a valid SFTP host and port first.']);
            exit;
        }
        try {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-kopia-replication',
                'fetch-host-key',
                json_encode(['host' => $host, 'port' => $port], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'everlomp'
            );
            if ($code !== 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not fetch SSH host key.')]);
                exit;
            }
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            $knownHosts = is_array($decoded) ? (string) ($decoded['known_hosts'] ?? '') : '';
            if ($knownHosts === '') {
                throw new RuntimeException('No SSH host key was returned.');
            }
            echo json_encode(['ok' => true, 'known_hosts' => $knownHosts, 'message' => 'SSH host key fetched.']);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Could not fetch the SSH host key.']);
        }
        exit;
    }

    if ($action === 'setup_kopia_sftp_access') {
        header('Content-Type: application/json; charset=utf-8');
        $payload = [
            'host' => trim((string) ($_POST['kopia_offsite_sftp_host'] ?? '')),
            'port' => (int) ($_POST['kopia_offsite_sftp_port'] ?? 22),
            'username' => trim((string) ($_POST['kopia_offsite_sftp_username'] ?? '')),
            'current_password' => (string) ($_POST['kopia_offsite_sftp_current_password'] ?? ''),
            'known_hosts' => (string) ($_POST['kopia_offsite_sftp_known_hosts'] ?? ''),
            'change_password' => (string) ($_POST['kopia_offsite_sftp_change_password'] ?? '') === '1',
            'new_password' => (string) ($_POST['kopia_offsite_sftp_new_password'] ?? ''),
            'new_password_confirm' => (string) ($_POST['kopia_offsite_sftp_new_password_confirm'] ?? ''),
            'generate_keypair' => (string) ($_POST['kopia_offsite_sftp_generate_keypair'] ?? '') === '1',
        ];
        try {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-kopia-replication',
                'bootstrap-sftp',
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                'everlomp'
            );
            if ($code !== 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'message' => $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not configure remote SSH access.')]);
                exit;
            }
            $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || ($decoded['ok'] ?? false) !== true) {
                throw new RuntimeException('Invalid SSH setup response.');
            }
            echo json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            http_response_code(500);
            echo json_encode(['ok' => false, 'message' => 'Could not configure remote SSH access.']);
        }
        exit;
    }

    if ($action === 'configure_real_ip') {
        if (!$realIpCanConfigure) {
            $error = 'Could not safely detect both an internal proxy peer and a public forwarded client IP.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-realip',
                'configure',
                $requestPeerIp . "\n"
            );

            if ($code === 0) {
                header('Location: ' . $panelUrl . '?realip=configured');
                exit;
            }

            $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Real-IP configuration failed.');
            $error .= "\nThe failed proxy peer remains paused from automatic retries.";
        }
    }

    if ($action === 'generate_lsws_password') {
        if ($lswsPasswordConfigured) {
            $error = 'OpenLiteSpeed WebAdmin password has already been generated. Everlomp will not regenerate or replace it.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-lsws-password',
                'generate'
            );

            if ($code === 0) {
                $credentials = json_decode($stdout, true);

                if (
                    is_array($credentials)
                    && is_string($credentials['username'] ?? null)
                    && is_string($credentials['password'] ?? null)
                    && ($credentials['username'] ?? '') !== ''
                    && ($credentials['password'] ?? '') !== ''
                ) {
                    $lswsGeneratedUsername = $credentials['username'];
                    $lswsGeneratedPassword = $credentials['password'];
                    $lswsPasswordConfigured = true;
                    $message = 'OpenLiteSpeed WebAdmin password generated. Save it now; Everlomp does not store the plaintext password.';
                } else {
                    $error = 'OpenLiteSpeed password changed, but the generated credential could not be displayed.';
                }
            } else {
                $error = $stderr !== ''
                    ? $stderr
                    : ($stdout !== '' ? $stdout : 'OpenLiteSpeed WebAdmin password generation failed.');
            }
        }
    }

    if ($action === 'configure_database') {
        $username = trim((string) ($_POST['db_username'] ?? ''));
        $password = (string) ($_POST['db_password'] ?? '');
        $confirm = (string) ($_POST['db_password_confirm'] ?? '');

        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{2,31}$/', $username)) {
            $error = 'Database username must be 3-32 characters, start with a letter, and contain only letters, numbers, and underscores.';
        } elseif (strlen($password) < 12) {
            $error = 'Use a MariaDB admin password with at least 12 characters.';
        } elseif ($password !== $confirm) {
            $error = 'The MariaDB passwords do not match.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-database',
                'configure',
                $username . "\n" . $password . "\n"
            );

            if ($code === 0) {
                header('Location: ' . $panelUrl . '?database=created');
                exit;
            }

            $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'MariaDB setup failed.');
        }
    }

    if ($action === 'install_filegator') {
        $filegatorRoot = trim((string) ($_POST['filegator_root'] ?? '/var/www'));
        $filegatorLocalOnly = (string) ($_POST['filegator_source'] ?? 'remote') === 'local';

        if ((string) ($_POST['accept_filegator_terms'] ?? '') !== '1') {
            $error = 'Accept the FileGator license terms before installing FileGator.';
        } elseif ($filegatorInstalled) {
            $error = 'FileGator is already installed.';
        } elseif (
            $filegatorRoot === ''
            || strlen($filegatorRoot) > 512
            || preg_match('/[\r\n\0]/', $filegatorRoot)
            || !preg_match('#^/var/www(?:/.*)?$#', $filegatorRoot)
            || preg_match('#(?:^|/)\.\.(?:/|$)#', $filegatorRoot)
        ) {
            $error = 'FileGator root must be /var/www or a directory below /var/www.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-filegator',
                'install',
                json_encode([
                    'root' => $filegatorRoot,
                    'local_file_only' => $filegatorLocalOnly,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            if ($code === 0) {
                $credentials = json_decode($stdout, true);

                if (
                    is_array($credentials)
                    && is_string($credentials['username'] ?? null)
                    && is_string($credentials['password'] ?? null)
                    && is_string($credentials['root'] ?? null)
                    && ($credentials['username'] ?? '') !== ''
                    && ($credentials['password'] ?? '') !== ''
                    && ($credentials['root'] ?? '') !== ''
                ) {
                    $filegatorGeneratedUsername = $credentials['username'];
                    $filegatorGeneratedPassword = $credentials['password'];
                    $filegatorGeneratedRoot = $credentials['root'];
                    $filegatorGeneratedVersion = is_string($credentials['version'] ?? null)
                        ? $credentials['version']
                        : '';
                    $filegatorInstalled = true;
                    $filegatorInfo = readJsonFile($filegatorInfoFile);
                    $message = 'FileGator installed. Save the generated admin password now; Everlomp does not store it.';
                } else {
                    $error = 'FileGator installed, but the generated admin credential could not be displayed.';
                }
            } else {
                $error = $stderr !== ''
                    ? $stderr
                    : ($stdout !== '' ? $stdout : 'FileGator installation failed.');
            }
        }
    }

    if ($action === 'configure_kopia') {
        $repositoryMode = (string) ($_POST['kopia_repository_mode'] ?? 'create');
        $repositoryPath = trim((string) ($_POST['kopia_repository_path'] ?? '/home/everlomp/kopiasnapshots'));
        $defaultSnapshotsEnabled = (string) ($_POST['kopia_default_snapshots_enabled'] ?? '') === '1';
        $defaultSnapshotTime = trim((string) ($_POST['kopia_default_snapshot_time'] ?? '03:00'));
        $repositoryPassword = (string) ($_POST['kopia_repository_password'] ?? '');
        $repositoryPasswordConfirm = (string) ($_POST['kopia_repository_password_confirm'] ?? '');
        $repositoryEncryption = (string) ($_POST['kopia_encryption'] ?? 'AES256-GCM-HMAC-SHA256');
        $installLocalVersion = (string) ($_POST['kopia_install_local_version'] ?? '') === '1';
        $offsiteEnabled = (string) ($_POST['kopia_offsite_enabled'] ?? '') === '1';
        $offsiteType = (string) ($_POST['kopia_offsite_type'] ?? 'sftp');
        $offsiteSchedule = (string) ($_POST['kopia_offsite_schedule'] ?? 'every6h');
        $offsiteTime = trim((string) ($_POST['kopia_offsite_time'] ?? '03:30'));
        $offsiteWeekday = (int) ($_POST['kopia_offsite_weekday'] ?? 0);

        $offsiteSftpHost = trim((string) ($_POST['kopia_offsite_sftp_host'] ?? ''));
        $offsiteSftpPort = (int) ($_POST['kopia_offsite_sftp_port'] ?? 22);
        $offsiteSftpUsername = trim((string) ($_POST['kopia_offsite_sftp_username'] ?? ''));
        $offsiteSftpPath = trim((string) ($_POST['kopia_offsite_sftp_path'] ?? 'everbackups/'));
        $offsiteSftpAuth = (string) ($_POST['kopia_offsite_sftp_auth'] ?? 'key');
        $offsiteSftpPassword = (string) ($_POST['kopia_offsite_sftp_password'] ?? '');
        $offsiteSftpPrivateKey = (string) ($_POST['kopia_offsite_sftp_private_key'] ?? '');
        $offsiteSftpKnownHosts = (string) ($_POST['kopia_offsite_sftp_known_hosts'] ?? '');

        $offsiteWebdavUrl = trim((string) ($_POST['kopia_offsite_webdav_url'] ?? ''));
        $offsiteWebdavUsername = trim((string) ($_POST['kopia_offsite_webdav_username'] ?? ''));
        $offsiteWebdavPassword = (string) ($_POST['kopia_offsite_webdav_password'] ?? '');

        $offsiteFilesystemPath = trim((string) ($_POST['kopia_offsite_filesystem_path'] ?? '/remote-backup/kopia'));

        $offsiteEndpoint = trim((string) ($_POST['kopia_offsite_endpoint'] ?? ''));
        $offsiteBucket = trim((string) ($_POST['kopia_offsite_bucket'] ?? ''));
        $offsiteRegion = trim((string) ($_POST['kopia_offsite_region'] ?? ''));
        $offsitePrefix = trim((string) ($_POST['kopia_offsite_prefix'] ?? ''));
        $offsiteAccessKey = trim((string) ($_POST['kopia_offsite_access_key'] ?? ''));
        $offsiteSecretKey = (string) ($_POST['kopia_offsite_secret_key'] ?? '');
        $offsiteSessionToken = (string) ($_POST['kopia_offsite_session_token'] ?? '');

        $offsiteKopiaToken = trim((string) ($_POST['kopia_offsite_kopia_token'] ?? ''));
        $offsiteRcloneRemotePath = trim((string) ($_POST['kopia_offsite_rclone_remote_path'] ?? ''));
        $offsiteRcloneConfig = (string) ($_POST['kopia_offsite_rclone_config'] ?? '');

        if ((string) ($_POST['accept_kopia_terms'] ?? '') !== '1') {
            $error = 'Accept the Kopia license terms before installing Kopia.';
        } elseif (!array_key_exists('sql_schedule', $backupInfo)) {
            $error = 'Save the MariaDB backup schedule/retention choice before installing Kopia.';
        } elseif ($kopiaConfigured) {
            $error = 'Kopia is already configured.';
        } elseif (!in_array($repositoryMode, ['create', 'ui'], true)) {
            $error = 'Choose a valid Kopia primary repository setup mode.';
        } elseif ($repositoryMode === 'create' && (
            $repositoryPath === ''
            || !str_starts_with($repositoryPath, '/')
            || !preg_match('#^/home/everlomp/kopiasnapshots(?:/.*)?$#', $repositoryPath)
            || str_contains($repositoryPath, '/../')
            || str_ends_with($repositoryPath, '/..')
        )) {
            $error = 'Kopia repository path must be /home/everlomp/kopiasnapshots or a directory below it.';
        } elseif ($defaultSnapshotsEnabled && $repositoryMode !== 'create') {
            $error = 'Default daily snapshot sources require creating the local primary repository during installation. If you choose a primary later, configure snapshots from Kopia after connecting it.';
        } elseif ($defaultSnapshotsEnabled && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $defaultSnapshotTime)) {
            $error = 'Choose a valid daily Kopia snapshot time.';
        } elseif ($repositoryMode === 'create' && !in_array($repositoryEncryption, ['AES256-GCM-HMAC-SHA256', 'CHACHA20-POLY1305-HMAC-SHA256'], true)) {
            $error = 'Invalid Kopia repository encryption algorithm.';
        } elseif ($repositoryMode === 'create' && strlen($repositoryPassword) < 12) {
            $error = 'Use a Kopia repository password with at least 12 characters.';
        } elseif ($repositoryMode === 'create' && !hash_equals($repositoryPassword, $repositoryPasswordConfirm)) {
            $error = 'The Kopia repository passwords do not match.';
        } elseif ($installLocalVersion && !$kopiaLocalAvailable) {
            $error = 'Install local version was selected, but both everlomp/local-kopia/kopia_source.tar.gz and everlomp/local-kopia/htmlui_source.tar.gz are required. Add both files and rebuild the image.';
        } elseif ($offsiteEnabled && !in_array($offsiteType, ['sftp', 'webdav', 'filesystem', 's3', 'from-config', 'rclone'], true)) {
            $error = 'Invalid remote backup destination type.';
        } elseif ($offsiteEnabled && !in_array($offsiteSchedule, ['hourly', 'every6h', 'every12h', 'daily', 'weekly'], true)) {
            $error = 'Invalid remote replication schedule.';
        } elseif ($offsiteEnabled && in_array($offsiteSchedule, ['daily', 'weekly'], true) && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $offsiteTime)) {
            $error = 'Remote replication time must be HH:MM for daily or weekly schedules.';
        } elseif ($offsiteEnabled && $offsiteSchedule === 'weekly' && ($offsiteWeekday < 0 || $offsiteWeekday > 6)) {
            $error = 'Invalid remote replication weekday.';
        } elseif ($offsiteEnabled && $offsiteType === 'sftp' && ($offsiteSftpHost === '' || strlen($offsiteSftpHost) > 255 || $offsiteSftpPort < 1 || $offsiteSftpPort > 65535 || $offsiteSftpUsername === '' || $offsiteSftpPath === '')) {
            $error = 'Enter the SFTP host, port, username, and remote repository path.';
        } elseif ($offsiteEnabled && $offsiteType === 'sftp' && !in_array($offsiteSftpAuth, ['key', 'password'], true)) {
            $error = 'Choose SSH private key or password authentication for SFTP.';
        } elseif ($offsiteEnabled && $offsiteType === 'sftp' && $offsiteSftpAuth === 'key' && trim($offsiteSftpPrivateKey) === '') {
            $error = 'Paste an SSH private key for the SFTP destination.';
        } elseif ($offsiteEnabled && $offsiteType === 'sftp' && $offsiteSftpAuth === 'password' && $offsiteSftpPassword === '') {
            $error = 'Enter the SFTP password.';
        } elseif ($offsiteEnabled && $offsiteType === 'webdav' && (!preg_match('#^https?://#i', $offsiteWebdavUrl) || $offsiteWebdavUsername === '' || $offsiteWebdavPassword === '')) {
            $error = 'Enter a WebDAV URL, username, and password.';
        } elseif ($offsiteEnabled && $offsiteType === 'filesystem' && ($offsiteFilesystemPath === '' || !str_starts_with($offsiteFilesystemPath, '/'))) {
            $error = 'Mounted NAS/filesystem destination must be an absolute path inside the container.';
        } elseif ($offsiteEnabled && $offsiteType === 's3' && ($offsiteEndpoint === '' || strlen($offsiteEndpoint) > 255 || preg_match('#[\s/]#', $offsiteEndpoint))) {
            $error = 'S3-compatible endpoint must be a hostname, optionally with a port, without http:// or https://.';
        } elseif ($offsiteEnabled && $offsiteType === 's3' && ($offsiteBucket === '' || $offsiteAccessKey === '' || $offsiteSecretKey === '')) {
            $error = 'Enter the S3/MinIO bucket, access key, and secret key.';
        } elseif ($offsiteEnabled && $offsiteType === 'from-config' && $offsiteKopiaToken === '') {
            $error = 'Paste the Kopia configuration token for the remote repository.';
        } elseif ($offsiteEnabled && $offsiteType === 'rclone' && ($offsiteRcloneRemotePath === '' || trim($offsiteRcloneConfig) === '')) {
            $error = 'Enter the Rclone remote path and paste the rclone.conf content.';
        } elseif (strlen($offsiteSftpPassword) > 4096 || strlen($offsiteSftpPrivateKey) > 65536 || strlen($offsiteSftpKnownHosts) > 65536 || strlen($offsiteWebdavPassword) > 4096 || strlen($offsiteAccessKey) > 1024 || strlen($offsiteSecretKey) > 2048 || strlen($offsiteSessionToken) > 4096 || strlen($offsiteKopiaToken) > 65536 || strlen($offsiteRcloneConfig) > 262144) {
            $error = 'One or more remote destination credential values are too long.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-backup',
                'configure-kopia',
                json_encode([
                    'repository_mode' => $repositoryMode,
                    'repository_path' => $repositoryPath,
                    'repository_password' => $repositoryPassword,
                    'encryption' => $repositoryEncryption,
                    'install_local_version' => $installLocalVersion,
                    'default_snapshots' => [
                        'enabled' => $defaultSnapshotsEnabled,
                        'time' => $defaultSnapshotTime,
                    ],
                    'offsite' => [
                        'enabled' => $offsiteEnabled,
                        'type' => $offsiteType,
                        'schedule' => $offsiteSchedule,
                        'time' => $offsiteTime,
                        'weekday' => $offsiteWeekday,
                        'sftp' => [
                            'host' => $offsiteSftpHost,
                            'port' => $offsiteSftpPort,
                            'username' => $offsiteSftpUsername,
                            'path' => $offsiteSftpPath,
                            'auth_mode' => $offsiteSftpAuth,
                            'password' => $offsiteSftpPassword,
                            'private_key' => $offsiteSftpPrivateKey,
                            'known_hosts' => $offsiteSftpKnownHosts,
                        ],
                        'webdav' => [
                            'url' => $offsiteWebdavUrl,
                            'username' => $offsiteWebdavUsername,
                            'password' => $offsiteWebdavPassword,
                        ],
                        'filesystem' => ['path' => $offsiteFilesystemPath],
                        's3' => [
                            'endpoint' => $offsiteEndpoint,
                            'bucket' => $offsiteBucket,
                            'region' => $offsiteRegion,
                            'prefix' => $offsitePrefix,
                            'access_key' => $offsiteAccessKey,
                            'secret_access_key' => $offsiteSecretKey,
                            'session_token' => $offsiteSessionToken,
                        ],
                        'from_config' => ['token' => $offsiteKopiaToken],
                        'rclone' => [
                            'remote_path' => $offsiteRcloneRemotePath,
                            'config' => $offsiteRcloneConfig,
                        ],
                    ],
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            if ($code === 0) {
                $credentials = json_decode($stdout, true);

                if (
                    is_array($credentials)
                    && is_string($credentials['username'] ?? null)
                    && is_string($credentials['password'] ?? null)
                    && ($credentials['username'] ?? '') !== ''
                    && ($credentials['password'] ?? '') !== ''
                ) {
                    $kopiaGeneratedUsername = $credentials['username'];
                    $kopiaGeneratedPassword = $credentials['password'];
                    $kopiaGeneratedRepository = is_string($credentials['repository_path'] ?? null)
                        ? $credentials['repository_path'] : '';
                    $kopiaGeneratedVersion = is_string($credentials['version'] ?? null)
                        ? $credentials['version'] : '';
                    $kopiaConfigured = true;
                    $kopiaEnabled = true;
                    if (!$installLocalVersion) {
                        $kopiaBuildToolsReady = true;
                    }
                    $kopiaInfo = readJsonFile($kopiaInfoFile);
                    clearstatcache(true, $backupInfoFile);
                    $backupInfo = readJsonFile($backupInfoFile);
                    $initialOffsiteStatus = (string) ($credentials['offsite_status'] ?? 'disabled');
                    if ($initialOffsiteStatus === 'success') {
                        $message = 'Kopia configured and enabled. The initial replica was seeded successfully. Save the Web UI password now.';
                    } elseif ($initialOffsiteStatus === 'failed') {
                        $message = 'Kopia configured and enabled. The initial replica is saved in Kopia → Replication, but its first sync failed. Save the Web UI password now.';
                    } elseif ($initialOffsiteStatus === 'pending-primary') {
                        $message = 'Kopia is ready. Create/connect the primary repository in Kopia → Repository; the initial replica is already waiting in Kopia → Replication. Save the Web UI password now.';
                    } elseif ($repositoryMode === 'ui') {
                        $message = 'Kopia is ready. Save the Web UI password, then open Kopia → Repository to create/connect the primary repository.';
                    } else {
                        $message = 'Kopia configured and enabled. Save the Web UI password now.';
                    }
                } else {
                    $error = 'Kopia was configured, but the generated Web UI credential could not be displayed.';
                }
            } else {
                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Kopia configuration failed.');
            }
        }
    }


    if ($action === 'prepare_kopia_build') {
        [$code, $stdout, $stderr] = runEverlompHelper(
            '/usr/local/sbin/everlomp-backup',
            'prepare-kopia-build'
        );

        if ($code === 0) {
            $kopiaBuildToolsReady = true;
            $message = $stdout !== ''
                ? $stdout
                : 'Kopia build/update tools installed and custom binary prepared.';
        } else {
            $error = $stderr !== ''
                ? $stderr
                : ($stdout !== '' ? $stdout : 'Could not prepare the Kopia build/update tools.');
        }
    }

    if ($action === 'save_kopia_offsite_settings') {
        $offsiteEnabled = (string) ($_POST['kopia_offsite_enabled'] ?? '') === '1';
        $offsiteType = (string) ($_POST['kopia_offsite_type'] ?? 'sftp');
        $offsiteSchedule = (string) ($_POST['kopia_offsite_schedule'] ?? 'every6h');
        $offsiteTime = trim((string) ($_POST['kopia_offsite_time'] ?? '03:30'));
        $offsiteWeekday = (int) ($_POST['kopia_offsite_weekday'] ?? 0);

        $offsiteSftpHost = trim((string) ($_POST['kopia_offsite_sftp_host'] ?? ''));
        $offsiteSftpPort = (int) ($_POST['kopia_offsite_sftp_port'] ?? 22);
        $offsiteSftpUsername = trim((string) ($_POST['kopia_offsite_sftp_username'] ?? ''));
        $offsiteSftpPath = trim((string) ($_POST['kopia_offsite_sftp_path'] ?? 'everbackups/'));
        $offsiteSftpAuth = (string) ($_POST['kopia_offsite_sftp_auth'] ?? 'key');
        $offsiteSftpPassword = (string) ($_POST['kopia_offsite_sftp_password'] ?? '');
        $offsiteSftpPrivateKey = (string) ($_POST['kopia_offsite_sftp_private_key'] ?? '');
        $offsiteSftpKnownHosts = (string) ($_POST['kopia_offsite_sftp_known_hosts'] ?? '');
        $offsiteWebdavUrl = trim((string) ($_POST['kopia_offsite_webdav_url'] ?? ''));
        $offsiteWebdavUsername = trim((string) ($_POST['kopia_offsite_webdav_username'] ?? ''));
        $offsiteWebdavPassword = (string) ($_POST['kopia_offsite_webdav_password'] ?? '');
        $offsiteFilesystemPath = trim((string) ($_POST['kopia_offsite_filesystem_path'] ?? '/remote-backup/kopia'));
        $offsiteEndpoint = trim((string) ($_POST['kopia_offsite_endpoint'] ?? ''));
        $offsiteBucket = trim((string) ($_POST['kopia_offsite_bucket'] ?? ''));
        $offsiteRegion = trim((string) ($_POST['kopia_offsite_region'] ?? ''));
        $offsitePrefix = trim((string) ($_POST['kopia_offsite_prefix'] ?? ''));
        $offsiteAccessKey = trim((string) ($_POST['kopia_offsite_access_key'] ?? ''));
        $offsiteSecretKey = (string) ($_POST['kopia_offsite_secret_key'] ?? '');
        $offsiteSessionToken = (string) ($_POST['kopia_offsite_session_token'] ?? '');
        $offsiteKopiaToken = trim((string) ($_POST['kopia_offsite_kopia_token'] ?? ''));
        $offsiteRcloneRemotePath = trim((string) ($_POST['kopia_offsite_rclone_remote_path'] ?? ''));
        $offsiteRcloneConfig = (string) ($_POST['kopia_offsite_rclone_config'] ?? '');

        if (!$kopiaConfigured) {
            $error = 'Configure Kopia before enabling remote replication.';
        } elseif (!in_array($offsiteType, ['sftp', 'webdav', 'filesystem', 's3', 'from-config', 'rclone'], true)) {
            $error = 'Invalid remote backup destination type.';
        } elseif (!in_array($offsiteSchedule, ['hourly', 'every6h', 'every12h', 'daily', 'weekly'], true)) {
            $error = 'Invalid remote replication schedule.';
        } elseif (in_array($offsiteSchedule, ['daily', 'weekly'], true) && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $offsiteTime)) {
            $error = 'Remote replication time must be HH:MM for daily or weekly schedules.';
        } elseif ($offsiteSchedule === 'weekly' && ($offsiteWeekday < 0 || $offsiteWeekday > 6)) {
            $error = 'Invalid remote replication weekday.';
        } elseif ($offsiteEnabled && $offsiteType === 'sftp' && ($offsiteSftpHost === '' || $offsiteSftpPort < 1 || $offsiteSftpPort > 65535 || $offsiteSftpUsername === '' || $offsiteSftpPath === '')) {
            $error = 'Enter the SFTP host, port, username, and remote path.';
        } elseif ($offsiteEnabled && $offsiteType === 'webdav' && (!preg_match('#^https?://#i', $offsiteWebdavUrl) || $offsiteWebdavUsername === '')) {
            $error = 'Enter the WebDAV URL and username.';
        } elseif ($offsiteEnabled && $offsiteType === 'filesystem' && ($offsiteFilesystemPath === '' || !str_starts_with($offsiteFilesystemPath, '/'))) {
            $error = 'Mounted NAS/filesystem destination must be an absolute path.';
        } elseif ($offsiteEnabled && $offsiteType === 's3' && ($offsiteEndpoint === '' || $offsiteBucket === '')) {
            $error = 'Enter the S3/MinIO endpoint and bucket.';
        } elseif (($offsiteAccessKey === '') xor ($offsiteSecretKey === '')) {
            $error = 'When changing S3 credentials, enter both the access key and secret key.';
        } elseif ($offsiteEnabled && $offsiteType === 'rclone' && $offsiteRcloneRemotePath === '') {
            $error = 'Enter the Rclone remote path.';
        } elseif (strlen($offsiteSftpPassword) > 4096 || strlen($offsiteSftpPrivateKey) > 65536 || strlen($offsiteSftpKnownHosts) > 65536 || strlen($offsiteWebdavPassword) > 4096 || strlen($offsiteAccessKey) > 1024 || strlen($offsiteSecretKey) > 2048 || strlen($offsiteSessionToken) > 4096 || strlen($offsiteKopiaToken) > 65536 || strlen($offsiteRcloneConfig) > 262144) {
            $error = 'One or more remote destination credential values are too long.';
        } else {
            $remotePayload = [
                'enabled' => $offsiteEnabled,
                'type' => $offsiteType,
                'schedule' => $offsiteSchedule,
                'time' => $offsiteTime,
                'weekday' => $offsiteWeekday,
                'sftp' => ['host'=>$offsiteSftpHost,'port'=>$offsiteSftpPort,'username'=>$offsiteSftpUsername,'path'=>$offsiteSftpPath,'auth_mode'=>$offsiteSftpAuth,'password'=>$offsiteSftpPassword,'private_key'=>$offsiteSftpPrivateKey,'known_hosts'=>$offsiteSftpKnownHosts],
                'webdav' => ['url'=>$offsiteWebdavUrl,'username'=>$offsiteWebdavUsername,'password'=>$offsiteWebdavPassword],
                'filesystem' => ['path'=>$offsiteFilesystemPath],
                's3' => ['endpoint'=>$offsiteEndpoint,'bucket'=>$offsiteBucket,'region'=>$offsiteRegion,'prefix'=>$offsitePrefix,'access_key'=>$offsiteAccessKey,'secret_access_key'=>$offsiteSecretKey,'session_token'=>$offsiteSessionToken],
                'from_config' => ['token'=>$offsiteKopiaToken],
                'rclone' => ['remote_path'=>$offsiteRcloneRemotePath,'config'=>$offsiteRcloneConfig],
            ];

            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-backup',
                'save-offsite-settings',
                json_encode($remotePayload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );

            if ($code === 0) {
                clearstatcache(true, $backupInfoFile);
                $backupInfo = readJsonFile($backupInfoFile);
                $message = $stdout !== '' ? $stdout : 'Remote backup destination settings saved.';
            } else {
                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not save remote backup destination settings.');
            }
        }
    }

    if ($action === 'sync_kopia_offsite_now') {
        [$code, $stdout, $stderr] = runEverlompHelper('/usr/local/sbin/everlomp-backup', 'sync-kopia-now');
        clearstatcache(true, $backupInfoFile);
        $backupInfo = readJsonFile($backupInfoFile);

        if ($code === 0) {
            $message = 'Off-site Kopia synchronization completed.';
        } else {
            $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Off-site Kopia synchronization failed.');
        }
    }

    if ($action === 'save_sql_backup_settings') {
        $sqlEnabled = (string) ($_POST['sql_enabled'] ?? '') === '1';
        $sqlSchedule = (string) ($_POST['sql_schedule'] ?? 'daily');
        $sqlTime = trim((string) ($_POST['sql_time'] ?? '02:30'));
        $sqlWeekday = (int) ($_POST['sql_weekday'] ?? 0);
        $sqlKeep = (int) ($_POST['sql_keep'] ?? 7);

        if (!in_array($sqlSchedule, ['hourly', 'every6h', 'every12h', 'daily', 'weekly'], true)) {
            $error = 'Invalid SQL backup schedule.';
        } elseif (in_array($sqlSchedule, ['daily', 'weekly'], true) && !preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $sqlTime)) {
            $error = 'SQL backup time must be HH:MM for daily or weekly schedules.';
        } elseif ($sqlSchedule === 'weekly' && ($sqlWeekday < 0 || $sqlWeekday > 6)) {
            $error = 'Invalid SQL backup weekday.';
        } elseif ($sqlKeep < 1 || $sqlKeep > 365) {
            $error = 'SQL backup retention must be between 1 and 365 dumps.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-backup',
                'save-sql-settings',
                json_encode([
                    'sql_enabled' => $sqlEnabled,
                    'sql_schedule' => $sqlSchedule,
                    'sql_time' => $sqlTime,
                    'sql_weekday' => $sqlWeekday,
                    'sql_keep' => $sqlKeep,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
            if ($code === 0) {
                $backupInfo = readJsonFile($backupInfoFile);
                $message = $stdout !== '' ? $stdout : 'SQL backup settings saved.';
            } else {
                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not save SQL backup settings.');
            }
        }
    }

    if ($action === 'run_sql_backup') {
        [$code, $stdout, $stderr] = runEverlompHelper('/usr/local/sbin/everlomp-backup', 'sql-backup-now');
        $backupInfo = readJsonFile($backupInfoFile);
        if ($code === 0) {
            $message = 'MariaDB logical backup completed.';
        } else {
            $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'MariaDB logical backup failed.');
        }
    }

    if ($action === 'save_kopia_update_settings') {
        $updateEnabled = (string) ($_POST['kopia_auto_update'] ?? '') === '1';
        $updateSchedule = (string) ($_POST['kopia_update_schedule'] ?? 'weekly');
        $updateTime = trim((string) ($_POST['kopia_update_time'] ?? '04:30'));
        $updateWeekday = (int) ($_POST['kopia_update_weekday'] ?? 0);

        if (!$kopiaConfigured) {
            $error = 'Install and verify Kopia before configuring Kopia maintenance.';
        } elseif (!in_array($updateSchedule, ['daily', 'weekly'], true)) {
            $error = 'Invalid Kopia update schedule.';
        } elseif (!preg_match('/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $updateTime)) {
            $error = 'Kopia update time must be HH:MM.';
        } elseif ($updateSchedule === 'weekly' && ($updateWeekday < 0 || $updateWeekday > 6)) {
            $error = 'Invalid Kopia update weekday.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-backup',
                'save-update-settings',
                json_encode([
                    'kopia_auto_update' => $updateEnabled,
                    'kopia_update_schedule' => $updateSchedule,
                    'kopia_update_time' => $updateTime,
                    'kopia_update_weekday' => $updateWeekday,
                ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
            if ($code === 0) {
                $backupInfo = readJsonFile($backupInfoFile);
                $message = $stdout !== '' ? $stdout : 'Kopia update settings saved.';
            } else {
                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not save Kopia update settings.');
            }
        }
    }

    if ($action === 'import_external_installer') {
        $upload = $_FILES['external_installer_zip'] ?? null;
        if (!is_array($upload)) {
            $error = 'Choose an external installer ZIP to upload.';
        } elseif ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'External installer ZIP upload failed with code ' . (string) ($upload['error'] ?? 'unknown') . '.';
        } else {
            $originalName = (string) ($upload['name'] ?? '');
            $tmpName = (string) ($upload['tmp_name'] ?? '');
            $size = (int) ($upload['size'] ?? 0);
            if (!preg_match('/\.zip$/i', $originalName)) {
                $error = 'External installer packages must be uploaded as .zip files.';
            } elseif ($size < 1 || $size > 100 * 1024 * 1024) {
                $error = 'External installer ZIP must be between 1 byte and 100 MiB.';
            } elseif ($tmpName === '' || !is_uploaded_file($tmpName) || !is_readable($tmpName)) {
                $error = 'The uploaded ZIP could not be read safely.';
            } else {
                $zipData = file_get_contents($tmpName);
                if (!is_string($zipData) || strlen($zipData) !== $size) {
                    $error = 'The uploaded ZIP could not be read completely.';
                } else {
                    [$code, $stdout, $stderr] = runEverlompHelper(
                        '/usr/local/sbin/everlomp-external-installer',
                        'import',
                        $zipData
                    );
                    if ($code === 0) {
                        $externalInstallers = readExternalInstallerPackages($externalInstallerDir);
                        $message = $stdout !== '' ? $stdout : 'External installer package imported.';
                    } else {
                        $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not import the external installer package.');
                    }
                }
            }
        }
    }

    if ($action === 'remove_external_installer') {
        $packageId = trim((string) ($_POST['external_package_id'] ?? ''));
        if (!isset($externalInstallers[$packageId])) {
            $error = 'External installer package was not found.';
        } elseif ($primaryApp === 'external:' . $packageId) {
            $error = 'The package for the installed primary application cannot be removed.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-external-installer',
                'remove',
                json_encode(['package_id' => $packageId], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
            );
            if ($code === 0) {
                $externalInstallers = readExternalInstallerPackages($externalInstallerDir);
                $message = $stdout !== '' ? $stdout : 'External installer package removed.';
            } else {
                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not remove the external installer package.');
            }
        }
    }

    if ($action === 'install_external') {
        $packageId = trim((string) ($_POST['external_package_id'] ?? ''));
        $manifest = $externalInstallers[$packageId] ?? null;
        if (!is_array($manifest)) {
            $error = 'External installer package was not found.';
        } elseif ($primaryApp !== '') {
            $error = 'A primary application is already installed.';
        } elseif ((string) ($_POST['accept_external_root_trust'] ?? '') !== '1') {
            $error = 'Confirm that you trust this external installer before running it as root.';
        } elseif (($manifest['terms'] ?? '') !== '' && (string) ($_POST['accept_external_package_terms'] ?? '') !== '1') {
            $error = 'Accept the external installer package terms before installing it.';
        } else {
            $requirementError = externalInstallerRequirementError(
                $manifest,
                $databaseConfigured,
                $lswsPasswordConfigured,
                $realIpReady,
                $domainOnlyReady
            );
            if ($requirementError !== '') {
                $error = $requirementError;
            } else {
                [$fields, $fieldError] = validateExternalInstallerFields($manifest, $_POST['external_fields'] ?? []);
                if ($fieldError !== '') {
                    $error = $fieldError;
                } else {
                    @set_time_limit(1800);
                    [$code, $stdout, $stderr] = runEverlompHelper(
                        '/usr/local/sbin/everlomp-external-installer',
                        'install',
                        json_encode([
                            'package_id' => $packageId,
                            'fields' => $fields,
                        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                    );
                    if ($code === 0) {
                        header('Location: ' . $panelUrl . '?external=installed&package=' . rawurlencode($packageId));
                        exit;
                    }
                    $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'External application installation failed.');
                }
            }
        }
    }

    if ($action === 'enable_ssh') {
        if ($primaryApp === '') {
            $error = 'Install a primary application before enabling SSH.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-ssh',
                'enable'
            );

            $payload = json_decode($stdout, true);
            if ($code === 0 && is_array($payload) && ($payload['enabled'] ?? false) === true) {
                $sshGeneratedUsername = trim((string) ($payload['username'] ?? 'everlomp'));
                $sshGeneratedPassword = (string) ($payload['password'] ?? '');
                $sshHostDomain = trim((string) ($payload['host_domain'] ?? $sshHostDomain));
                $sshPublicPort = (string) ($payload['port'] ?? $sshPublicPort);
                $sshMappingReady = $sshPublicPort !== '' && $sshHostDomain !== '';
                $message = 'SSH enabled and verified on EXTERNAL_GPTCP2_PORT ' . $sshPublicPort . '.';
            } else {
                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not enable SSH.');
            }
        }
    }

    if ($action === 'disable_ssh') {
        if ($primaryApp === '') {
            $error = 'Install a primary application before completing the SSH choice.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-ssh',
                'disable'
            );

            $payload = json_decode($stdout, true);
            if ($code === 0 && is_array($payload) && ($payload['enabled'] ?? true) === false) {
                $sshPublicPort = (string) ($payload['port'] ?? $sshPublicPort);
                $message = 'SSH will remain disabled. The everlomp SSH account is locked and sshd will not autostart.';
            } else {
                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not disable SSH.');
            }
        }
    }

    if ($action === 'delete_everlomp_installfile') {
        if ($primaryApp === '') {
            $error = 'Install a primary application before deleting the Everlomp install page.';
        } elseif (!$sshDecisionMade) {
            $error = 'Choose whether SSH should be enabled before removing the Everlomp installer.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-delete-installfile',
                'delete'
            );

            if ($code === 0) {
                header('Location: /');
                exit;
            }

            $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Could not delete the Everlomp install page.');
        }
    }

    if ($action === 'enable_hotpocket') {
        if ((string) ($_POST['accept_hotpocket_terms'] ?? '') !== '1') {
            $error = 'Accept the HotPocket license terms before enabling HotPocket.';
        } elseif ($hotpocketEnabled) {
            $message = 'HotPocket is already enabled.';
        } else {
            [$code, $stdout, $stderr] = runEverlompHelper(
                '/usr/local/sbin/everlomp-hotpocket',
                'enable'
            );

            if ($code === 0) {
                header('Location: ' . $panelUrl . '?hotpocket=enabled');
                exit;
            }

            $error = $stderr !== ''
                ? $stderr
                : ($stdout !== '' ? $stdout : 'Could not enable HotPocket.');
        }
    }

    if ($action === 'install_wordpress') {
        $siteUrl = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
        $siteTitle = trim((string) ($_POST['site_title'] ?? ''));
        $adminUser = trim((string) ($_POST['wp_admin_user'] ?? ''));
        $adminEmail = trim((string) ($_POST['wp_admin_email'] ?? ''));
        $adminPassword = (string) ($_POST['wp_admin_password'] ?? '');
        $adminPasswordConfirm = (string) ($_POST['wp_admin_password_confirm'] ?? '');
        $dbMode = (string) ($_POST['db_mode'] ?? 'auto');
        $localFileOnly = (string) ($_POST['local_file_only'] ?? '') === '1';
        $selectedThemes = normalizedStringList($_POST['wp_themes'] ?? []);
        $selectedPlugins = normalizedStringList($_POST['wp_plugins'] ?? []);
        $installLocalThemes = array_values(array_intersect(
            normalizedStringList($_POST['wp_theme_install_local'] ?? []),
            $selectedThemes
        ));
        $installLocalPlugins = array_values(array_intersect(
            normalizedStringList($_POST['wp_plugin_install_local'] ?? []),
            $selectedPlugins
        ));
        $activateThemes = array_values(array_intersect(
            normalizedStringList($_POST['wp_theme_activate'] ?? []),
            $selectedThemes
        ));
        $activatePlugins = array_values(array_intersect(
            normalizedStringList($_POST['wp_plugin_activate'] ?? []),
            $selectedPlugins
        ));
        $invalidThemes = array_values(array_diff($selectedThemes, $wpThemeSlugs));
        $invalidPlugins = array_values(array_diff($selectedPlugins, $wpPluginSlugs));
        $invalidLocalThemes = array_values(array_diff($installLocalThemes, $wpThemeLocalAvailableSlugs));
        $invalidLocalPlugins = array_values(array_diff($installLocalPlugins, $wpPluginLocalAvailableSlugs));
        $invalidActivatedThemes = array_values(array_diff($activateThemes, $wpThemeSlugs));
        $invalidActivatedPlugins = array_values(array_diff($activatePlugins, $wpPluginSlugs));

        if ((string) ($_POST['accept_wordpress_terms'] ?? '') !== '1') {
            $error = 'Accept the WordPress terms before installing WordPress.';
        } elseif (!$databaseConfigured) {
            $error = 'Configure the MariaDB administrator account before installing WordPress.';
        } elseif (!$lswsPasswordConfigured) {
            $error = 'Generate and save the OpenLiteSpeed WebAdmin password before installing WordPress.';
        } elseif (!$realIpReady) {
            $error = 'Real client IP forwarding must work before installing WordPress; otherwise anti-bruteforce/IP-based protection cannot identify the real visitor.';
        } elseif (!$domainOnlyReady) {
            $error = 'Open Everlomp through its HTTPS domain without an explicit port before installing WordPress. Do not use domain:port or a raw IP address.';
        } elseif ($primaryApp !== '') {
            $error = 'A primary application is already installed.';
        } elseif (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !str_starts_with($siteUrl, 'https://')) {
            $error = 'Enter a valid HTTPS site URL.';
        } elseif (parse_url($siteUrl, PHP_URL_PORT) !== null || filter_var((string) parse_url($siteUrl, PHP_URL_HOST), FILTER_VALIDATE_IP) !== false) {
            $error = 'The WordPress site URL must use a domain without an explicit port. Use https://example.com, never domain:port or a raw IP.';
        } elseif ($siteTitle === '' || strlen($siteTitle) > 120) {
            $error = 'Site title is required and must be 120 characters or fewer.';
        } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,60}$/', $adminUser)) {
            $error = 'WordPress admin username must be 3-60 characters.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid WordPress admin email.';
        } elseif (strlen($adminPassword) < 12) {
            $error = 'Use a WordPress admin password with at least 12 characters.';
        } elseif ($adminPassword !== $adminPasswordConfirm) {
            $error = 'The WordPress admin passwords do not match.';
        } elseif (!in_array($dbMode, ['auto', 'existing'], true)) {
            $error = 'Invalid database mode.';
        } elseif ($invalidThemes !== []) {
            $error = 'One or more selected WordPress themes are not available in the Everlomp add-on manifest.';
        } elseif ($invalidPlugins !== []) {
            $error = 'One or more selected WordPress plugins are not available in the Everlomp add-on manifest.';
        } elseif ($invalidLocalThemes !== []) {
            $error = 'One or more WordPress themes were requested for local installation, but their local ZIP is not available.';
        } elseif ($invalidLocalPlugins !== []) {
            $error = 'One or more WordPress plugins were requested for local installation, but their local ZIP is not available.';
        } elseif ($invalidActivatedThemes !== [] || count($activateThemes) > 1) {
            $error = 'Choose at most one WordPress theme to activate.';
        } elseif ($invalidActivatedPlugins !== []) {
            $error = 'One or more WordPress plugins were requested for activation but are not available.';
        } else {
            $payload = [
                'site_url'       => $siteUrl,
                'site_title'     => $siteTitle,
                'admin_user'     => $adminUser,
                'admin_email'    => $adminEmail,
                'admin_password' => $adminPassword,
                'db_mode'        => $dbMode,
                'local_file_only'=> $localFileOnly,
                'themes'                  => $selectedThemes,
                'plugins'                 => $selectedPlugins,
                'theme_install_local'      => $installLocalThemes,
                'plugin_install_local'     => $installLocalPlugins,
                'theme_activate'           => $activateThemes,
                'plugin_activate'          => $activatePlugins,
            ];

            if ($dbMode === 'auto') {
                $dbName = trim((string) ($_POST['auto_db_name'] ?? 'wordpress'));

                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
                    $error = 'Automatic database name may contain only letters, numbers, and underscores.';
                } else {
                    $payload['database_name'] = $dbName;
                }
            } else {
                $dbHost = trim((string) ($_POST['existing_db_host'] ?? ''));
                $dbPort = trim((string) ($_POST['existing_db_port'] ?? ''));
                $dbName = trim((string) ($_POST['existing_db_name'] ?? ''));
                $dbUser = trim((string) ($_POST['existing_db_user'] ?? ''));
                $dbPass = (string) ($_POST['existing_db_password'] ?? '');

                $dbError = validateCommonDatabaseFields($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

                if ($dbError !== '') {
                    $error = $dbError;
                } else {
                    $payload['database_host'] = $dbHost;
                    $payload['database_port'] = $dbPort;
                    $payload['database_name'] = $dbName;
                    $payload['database_user'] = $dbUser;
                    $payload['database_password'] = $dbPass;
                }
            }

            if ($error === '') {
                [$code, $stdout, $stderr] = runEverlompHelper(
                    '/usr/local/sbin/everlomp-wordpress',
                    'install',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );

                if ($code === 0) {
                    header('Location: ' . $panelUrl . '?wordpress=installed');
                    exit;
                }

                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'WordPress installation failed.');
            }
        }
    }

    if ($action === 'install_drupal') {
        $siteUrl = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
        $siteName = trim((string) ($_POST['drupal_site_name'] ?? ''));
        $adminUser = trim((string) ($_POST['drupal_admin_user'] ?? ''));
        $adminEmail = trim((string) ($_POST['drupal_admin_email'] ?? ''));
        $adminPassword = (string) ($_POST['drupal_admin_password'] ?? '');
        $adminPasswordConfirm = (string) ($_POST['drupal_admin_password_confirm'] ?? '');
        $dbMode = (string) ($_POST['db_mode'] ?? 'auto');
        $installerSource = (string) ($_POST['drupal_installer_source'] ?? 'standard');
        $localFileOnly = $installerSource === 'local';

        $gitRepository = trim((string) ($_POST['drupal_git_repository'] ?? ''));
        $gitRef = trim((string) ($_POST['drupal_git_ref'] ?? 'main'));
        $gitDocumentRoot = trim((string) ($_POST['drupal_git_document_root'] ?? 'auto'));
        $gitAuth = (string) ($_POST['drupal_git_auth'] ?? 'public');
        $gitUsername = trim((string) ($_POST['drupal_git_username'] ?? ''));
        $gitToken = (string) ($_POST['drupal_git_token'] ?? '');

        if ((string) ($_POST['accept_drupal_terms'] ?? '') !== '1') {
            $error = 'Accept the Drupal license terms before installing Drupal.';
        } elseif (!$databaseConfigured) {
            $error = 'Configure the MariaDB administrator account before installing Drupal.';
        } elseif (!$lswsPasswordConfigured) {
            $error = 'Generate and save the OpenLiteSpeed WebAdmin password before installing Drupal.';
        } elseif (!$realIpReady) {
            $error = 'Real client IP forwarding must work before installing Drupal; otherwise IP-based protection cannot identify the real visitor.';
        } elseif (!$domainOnlyReady) {
            $error = 'Open Everlomp through its HTTPS domain without an explicit port before installing Drupal. Do not use domain:port or a raw IP address.';
        } elseif ($primaryApp !== '') {
            $error = 'A primary application is already installed.';
        } elseif (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !str_starts_with($siteUrl, 'https://')) {
            $error = 'Enter a valid HTTPS site URL.';
        } elseif (parse_url($siteUrl, PHP_URL_PORT) !== null || filter_var((string) parse_url($siteUrl, PHP_URL_HOST), FILTER_VALIDATE_IP) !== false) {
            $error = 'The Drupal site URL must use a domain without an explicit port. Use https://example.com, never domain:port or a raw IP.';
        } elseif ($siteName === '' || strlen($siteName) > 120) {
            $error = 'Drupal site name is required and must be 120 characters or fewer.';
        } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,60}$/', $adminUser)) {
            $error = 'Drupal admin username must be 3-60 characters.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid Drupal admin email.';
        } elseif (strlen($adminPassword) < 12) {
            $error = 'Use a Drupal admin password with at least 12 characters.';
        } elseif ($adminPassword !== $adminPasswordConfirm) {
            $error = 'The Drupal admin passwords do not match.';
        } elseif (!in_array($dbMode, ['auto', 'existing'], true)) {
            $error = 'Invalid database mode.';
        } elseif (!in_array($installerSource, ['standard', 'local', 'git'], true)) {
            $error = 'Choose a valid Drupal installer source.';
        } elseif ($installerSource === 'git') {
            $gitParts = filter_var($gitRepository, FILTER_VALIDATE_URL) ? parse_url($gitRepository) : false;
            if (
                !is_array($gitParts)
                || strtolower((string) ($gitParts['scheme'] ?? '')) !== 'https'
                || trim((string) ($gitParts['host'] ?? '')) === ''
                || isset($gitParts['user'])
                || isset($gitParts['pass'])
                || strlen($gitRepository) > 2048
            ) {
                $error = 'Enter a valid HTTPS Git repository URL without credentials in the URL.';
            } elseif (
                $gitRef === ''
                || strlen($gitRef) > 255
                || str_starts_with($gitRef, '-')
                || preg_match('/[\s\r\n]/', $gitRef)
            ) {
                $error = 'Enter a valid Git branch, tag, or commit.';
            } elseif (
                $gitDocumentRoot === ''
                || strlen($gitDocumentRoot) > 255
                || str_starts_with($gitDocumentRoot, '/')
                || preg_match('/[\r\n\0]/', $gitDocumentRoot)
                || preg_match('#(?:^|/)\.\.(?:/|$)#', $gitDocumentRoot)
            ) {
                $error = 'Drupal document root must be auto or a relative path such as web or . and may not contain ..';
            } elseif (!in_array($gitAuth, ['public', 'token'], true)) {
                $error = 'Choose a valid Git repository access mode.';
            } elseif ($gitAuth === 'token' && ($gitToken === '' || strlen($gitToken) > 4096 || preg_match('/[\r\n\0]/', $gitToken))) {
                $error = 'Enter a valid access token for the private Git repository.';
            } elseif ($gitAuth === 'token' && (strlen($gitUsername) > 255 || preg_match('/[\r\n\0]/', $gitUsername))) {
                $error = 'Enter a valid Git username.';
            }
        }

        if ($error === '') {
            $payload = [
                'site_url'        => $siteUrl,
                'site_name'       => $siteName,
                'admin_user'      => $adminUser,
                'admin_email'     => $adminEmail,
                'admin_password'  => $adminPassword,
                'db_mode'         => $dbMode,
                'local_file_only' => $localFileOnly,
            ];

            if ($installerSource === 'git') {
                $payload['git_repository'] = $gitRepository;
                $payload['git_ref'] = $gitRef;
                $payload['git_document_root'] = $gitDocumentRoot;
                $payload['git_auth'] = $gitAuth;
                $payload['git_username'] = $gitUsername;
                $payload['git_token'] = $gitToken;
            }

            if ($dbMode === 'auto') {
                $dbName = trim((string) ($_POST['auto_db_name'] ?? 'drupal'));
                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
                    $error = 'Automatic database name may contain only letters, numbers, and underscores.';
                } else {
                    $payload['database_name'] = $dbName;
                }
            } else {
                $dbHost = trim((string) ($_POST['existing_db_host'] ?? ''));
                $dbPort = trim((string) ($_POST['existing_db_port'] ?? ''));
                $dbName = trim((string) ($_POST['existing_db_name'] ?? ''));
                $dbUser = trim((string) ($_POST['existing_db_user'] ?? ''));
                $dbPass = (string) ($_POST['existing_db_password'] ?? '');
                $dbError = validateCommonDatabaseFields($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

                if ($dbError !== '') {
                    $error = $dbError;
                } else {
                    $payload['database_host'] = $dbHost;
                    $payload['database_port'] = $dbPort;
                    $payload['database_name'] = $dbName;
                    $payload['database_user'] = $dbUser;
                    $payload['database_password'] = $dbPass;
                }
            }

            if ($error === '') {
                $drupalHelper = $installerSource === 'git'
                    ? '/usr/local/sbin/everlomp-drupal-git'
                    : '/usr/local/sbin/everlomp-drupal';

                [$code, $stdout, $stderr] = runEverlompHelper(
                    $drupalHelper,
                    'install',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );

                if ($code === 0) {
                    header('Location: ' . $panelUrl . '?drupal=installed');
                    exit;
                }

                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'Drupal installation failed.');
            }
        }
    }

    if ($action === 'install_phpbb') {
        $siteUrl = rtrim(trim((string) ($_POST['site_url'] ?? '')), '/');
        $boardName = trim((string) ($_POST['phpbb_board_name'] ?? ''));
        $boardDescription = trim((string) ($_POST['phpbb_board_description'] ?? ''));
        $adminUser = trim((string) ($_POST['phpbb_admin_user'] ?? ''));
        $adminEmail = trim((string) ($_POST['phpbb_admin_email'] ?? ''));
        $adminPassword = (string) ($_POST['phpbb_admin_password'] ?? '');
        $adminPasswordConfirm = (string) ($_POST['phpbb_admin_password_confirm'] ?? '');
        $dbMode = (string) ($_POST['db_mode'] ?? 'auto');
        $localFileOnly = (string) ($_POST['local_file_only'] ?? '') === '1';
        $tablePrefix = trim((string) ($_POST['phpbb_table_prefix'] ?? 'phpbb_'));
        $smtpEnabled = (string) ($_POST['phpbb_smtp_enabled'] ?? '') === '1';
        $smtpHost = trim((string) ($_POST['phpbb_smtp_host'] ?? ''));
        $smtpPort = trim((string) ($_POST['phpbb_smtp_port'] ?? ''));
        $smtpAuth = trim((string) ($_POST['phpbb_smtp_auth'] ?? 'LOGIN'));
        $smtpUser = trim((string) ($_POST['phpbb_smtp_user'] ?? ''));
        $smtpPassword = (string) ($_POST['phpbb_smtp_password'] ?? '');
        $smtpAuthMethods = ['', 'PLAIN', 'LOGIN', 'CRAM-MD5', 'DIGEST-MD5', 'POP-BEFORE-SMTP'];

        if ((string) ($_POST['accept_phpbb_terms'] ?? '') !== '1') {
            $error = 'Accept the phpBB terms before installing phpBB.';
        } elseif (!$databaseConfigured) {
            $error = 'Configure the MariaDB administrator account before installing phpBB.';
        } elseif (!$lswsPasswordConfigured) {
            $error = 'Generate and save the OpenLiteSpeed WebAdmin password before installing phpBB.';
        } elseif (!$realIpReady) {
            $error = 'Real client IP forwarding must work before installing phpBB; otherwise anti-bruteforce/IP-based protection cannot identify the real visitor.';
        } elseif (!$domainOnlyReady) {
            $error = 'Open Everlomp through its HTTPS domain without an explicit port before installing phpBB. Do not use domain:port or a raw IP address.';
        } elseif ($primaryApp !== '') {
            $error = 'A primary application is already installed.';
        } elseif (!filter_var($siteUrl, FILTER_VALIDATE_URL) || !str_starts_with($siteUrl, 'https://')) {
            $error = 'Enter a valid HTTPS site URL.';
        } elseif (parse_url($siteUrl, PHP_URL_PORT) !== null || filter_var((string) parse_url($siteUrl, PHP_URL_HOST), FILTER_VALIDATE_IP) !== false) {
            $error = 'The phpBB forum URL must use a domain without an explicit port. Use https://example.com, never domain:port or a raw IP.';
        } elseif ($boardName === '' || strlen($boardName) > 120) {
            $error = 'Board name is required and must be 120 characters or fewer.';
        } elseif (strlen($boardDescription) > 255) {
            $error = 'Board description must be 255 characters or fewer.';
        } elseif (!preg_match('/^[A-Za-z0-9_.@-]{3,60}$/', $adminUser)) {
            $error = 'phpBB admin username must be 3-60 characters.';
        } elseif (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
            $error = 'Enter a valid phpBB admin email.';
        } elseif (strlen($adminPassword) < 12 || strlen($adminPassword) > 30) {
            $error = 'Use a phpBB admin password between 12 and 30 characters.';
        } elseif ($adminPassword !== $adminPasswordConfirm) {
            $error = 'The phpBB admin passwords do not match.';
        } elseif (!preg_match('/^[A-Za-z0-9_]{1,20}$/', $tablePrefix)) {
            $error = 'phpBB table prefix may contain only letters, numbers, and underscores, up to 20 characters.';
        } elseif ($smtpEnabled && ($smtpHost === '' || strlen($smtpHost) > 255 || preg_match('/[\r\n]/', $smtpHost))) {
            $error = 'Enter a valid phpBB SMTP host.';
        } elseif ($smtpEnabled && $smtpPort !== '' && (!ctype_digit($smtpPort) || (int) $smtpPort < 1 || (int) $smtpPort > 65535)) {
            $error = 'phpBB SMTP port must be blank or between 1 and 65535.';
        } elseif ($smtpEnabled && !in_array($smtpAuth, $smtpAuthMethods, true)) {
            $error = 'Select a supported phpBB SMTP authentication method.';
        } elseif ($smtpEnabled && (preg_match('/[\r\n]/', $smtpUser) || preg_match('/[\r\n]/', $smtpPassword))) {
            $error = 'phpBB SMTP credentials contain unsupported control characters.';
        } elseif ($smtpEnabled && (($smtpUser === '') !== ($smtpPassword === ''))) {
            $error = 'Provide both phpBB SMTP username and password, or leave both blank for an unauthenticated relay.';
        } elseif (!in_array($dbMode, ['auto', 'existing'], true)) {
            $error = 'Invalid database mode.';
        } else {
            $payload = [
                'site_url'          => $siteUrl,
                'board_name'        => $boardName,
                'board_description' => $boardDescription,
                'admin_user'        => $adminUser,
                'admin_email'       => $adminEmail,
                'admin_password'    => $adminPassword,
                'table_prefix'      => $tablePrefix,
                'db_mode'           => $dbMode,
                'local_file_only'   => $localFileOnly,
                'smtp_enabled'      => $smtpEnabled,
                'smtp_host'         => $smtpEnabled ? $smtpHost : '',
                'smtp_port'         => $smtpEnabled ? $smtpPort : '',
                'smtp_auth'         => $smtpEnabled ? $smtpAuth : '',
                'smtp_user'         => $smtpEnabled ? $smtpUser : '',
                'smtp_password'     => $smtpEnabled ? $smtpPassword : '',
            ];

            if ($dbMode === 'auto') {
                $dbName = trim((string) ($_POST['auto_db_name'] ?? 'phpbb'));

                if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $dbName)) {
                    $error = 'Automatic database name may contain only letters, numbers, and underscores.';
                } else {
                    $payload['database_name'] = $dbName;
                }
            } else {
                $dbHost = trim((string) ($_POST['existing_db_host'] ?? ''));
                $dbPort = trim((string) ($_POST['existing_db_port'] ?? ''));
                $dbName = trim((string) ($_POST['existing_db_name'] ?? ''));
                $dbUser = trim((string) ($_POST['existing_db_user'] ?? ''));
                $dbPass = (string) ($_POST['existing_db_password'] ?? '');

                $dbError = validateCommonDatabaseFields($dbHost, $dbPort, $dbName, $dbUser, $dbPass);

                if ($dbError !== '') {
                    $error = $dbError;
                } else {
                    $payload['database_host'] = $dbHost;
                    $payload['database_port'] = $dbPort;
                    $payload['database_name'] = $dbName;
                    $payload['database_user'] = $dbUser;
                    $payload['database_password'] = $dbPass;
                }
            }

            if ($error === '') {
                [$code, $stdout, $stderr] = runEverlompHelper(
                    '/usr/local/sbin/everlomp-phpbb',
                    'install',
                    json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );

                if ($code === 0) {
                    header('Location: ' . $panelUrl . '?phpbb=installed');
                    exit;
                }

                $error = $stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'phpBB installation failed.');
            }
        }
    }
}

$databaseConfigured = is_file($databaseConfiguredFile);
$lswsPasswordConfigured = is_file($lswsPasswordConfiguredFile);
$databaseAdminUser = readTextFile($databaseAdminUserFile);
$primaryApp = readTextFile($primaryAppFile);
$externalInstallers = readExternalInstallerPackages($externalInstallerDir);
$externalInstalledId = externalInstallerIdFromPrimary($primaryApp);
$wordpressInstalled = $primaryApp === 'wordpress';
$drupalInstalled = $primaryApp === 'drupal';
$phpbbInstalled = $primaryApp === 'phpbb';
$phpmyadminInstalled = is_file($phpmyadminIndexFile);
$hotpocketEnabled = is_file($hotpocketEnabledFile);
$filegatorInstalled = is_file($filegatorIndexFile);
$filegatorInfo = readJsonFile($filegatorInfoFile);
$backupInfo = readJsonFile($backupInfoFile);
$sshDecision = readTextFile($sshConfiguredFile);
$sshDecisionMade = in_array($sshDecision, ['enabled', 'disabled'], true);
$sshEnabled = $sshDecision === 'enabled';
$sshPublicPort = readContractPort($contractEnvFile, 'EXTERNAL_GPTCP2_PORT');
$sshHostDomain = readContractValue($contractEnvFile, 'HOST_DOMAIN_ADDRESS');
$sshMappingReady = $sshPublicPort !== '' && $sshHostDomain !== '';
[$kopiaInfo, $kopiaEnabled, $kopiaConfigured] = readKopiaState(
    $kopiaInfoFile,
    $kopiaConfigFile,
    $kopiaEnabledFile
);
$kopiaBuildToolsReady = kopiaBuildToolsReady();
$kopiaUrl = '/kopia/';

$realIpTrustedProxy = readTextFile($realIpProxyFile);
$realIpFailedProxy = readTextFile($realIpFailedFile);
$requestPeerIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
$forwardedClientIp = firstForwardedIp();

$realIpAutoFailed = (
    $realIpFailedProxy !== ''
    && hash_equals($realIpFailedProxy, $requestPeerIp)
);

$realIpReady = (
    $realIpTrustedProxy !== ''
    && isPublicIp($requestPeerIp)
);

$realIpCanConfigure = (
    isPrivateOrLoopbackIp($requestPeerIp)
    && isPublicIp($forwardedClientIp)
);

$view = (string) ($_GET['install'] ?? '');
$showBackupManager = (string) ($_GET['view'] ?? '') === 'backup';
$showWordPressInstaller = (
    $view === 'wordpress'
    && $primaryApp === ''
    && $databaseConfigured
    && $lswsPasswordConfigured
);

$showDrupalInstaller = (
    $view === 'drupal'
    && $primaryApp === ''
    && $databaseConfigured
    && $lswsPasswordConfigured
);

$showPhpbbInstaller = (
    $view === 'phpbb'
    && $primaryApp === ''
    && $databaseConfigured
    && $lswsPasswordConfigured
);

$externalViewId = str_starts_with($view, 'external:')
    ? substr($view, strlen('external:'))
    : '';
$externalInstallerView = ($externalViewId !== '' && isset($externalInstallers[$externalViewId]))
    ? $externalInstallers[$externalViewId]
    : [];
$showExternalInstaller = (
    $externalInstallerView !== []
    && $primaryApp === ''
);

$wizardSuggestedStep = 1;
if ($termsAccepted) $wizardSuggestedStep = 2;
if ($termsAccepted && $databaseConfigured) $wizardSuggestedStep = 3;
if ($termsAccepted && $databaseConfigured && $lswsPasswordConfigured && $realIpReady && $domainOnlyReady) $wizardSuggestedStep = 4;
if ($filegatorInstalled) $wizardSuggestedStep = max($wizardSuggestedStep, 5);
if ($primaryApp !== '') $wizardSuggestedStep = max($wizardSuggestedStep, 6);
if ($hotpocketEnabled) $wizardSuggestedStep = max($wizardSuggestedStep, 7);
if ($sshDecisionMade) $wizardSuggestedStep = max($wizardSuggestedStep, 9);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Everlomp</title>
<style>
:root{color-scheme:dark;--bg:#0b0d12;--panel:#12161f;--panel2:#171c27;--border:#282f3d;--text:#f4f6fb;--muted:#949eaf;--good:#63d297;--warn:#f2c66d}
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Inter,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:radial-gradient(circle at 20% 0%,rgba(93,116,210,.15),transparent 32rem),var(--bg);color:var(--text)}
.wrap{width:min(1320px,calc(100% - 32px));margin:auto;padding:50px 0 80px}.brand{display:flex;align-items:center;gap:12px;margin-bottom:40px}.logo{width:42px;height:42px;border-radius:12px;display:grid;place-items:center;background:linear-gradient(145deg,#7892f9,#9d7bf5);font-weight:900}.brand h1{margin:0;font-size:20px}.brand p{margin:2px 0 0;color:var(--muted);font-size:13px}
.hero h2{margin:0 0 10px;font-size:clamp(30px,5vw,50px);letter-spacing:-.04em}.hero p{max-width:800px;margin:0;color:var(--muted);font-size:17px;line-height:1.6}.notice{margin:20px 0;padding:14px 16px;border:1px solid var(--border);border-radius:12px;background:var(--panel)}.notice.success{border-color:rgba(99,210,151,.35)}.notice.error{border-color:rgba(255,125,142,.4);color:#ffd5dc}
.grid{display:grid;grid-template-columns:repeat(12,1fr);gap:18px;margin-top:28px}.card{grid-column:span 6;padding:24px;border:1px solid var(--border);border-radius:18px;background:rgba(18,22,31,.94)}.card.full{grid-column:1/-1}.card.app{display:flex;flex-direction:column;min-height:220px}.card h3{margin:0 0 8px}.card p{color:var(--muted);line-height:1.55}.status{display:inline-flex;width:fit-content;margin-bottom:16px;padding:6px 10px;border-radius:999px;background:var(--panel2);color:var(--muted);font-size:12px;font-weight:700}.status.good{color:var(--good)}.status.warn{color:var(--warn)}
form{display:grid;gap:14px;margin-top:20px}label{display:grid;gap:7px;font-size:13px;font-weight:700}input,textarea,select{width:100%;padding:13px 14px;border:1px solid var(--border);border-radius:11px;background:#0d1118;color:var(--text);font:inherit}textarea{min-height:90px;resize:vertical}.warning{padding:14px;border:1px solid rgba(242,198,109,.25);border-radius:12px;background:rgba(242,198,109,.07);color:#e8d5ac;font-size:13px;line-height:1.55}.actions{display:block;margin-bottom:6px;flex-wrap:wrap;gap:10px;margin-top:auto}button,.button{display:inline-block;padding:12px 16px;border:0;border-radius:11px;background:#e9edff;color:#111522;font:inherit;font-weight:800;text-align:center;text-decoration:none;cursor:pointer}button.secondary,.button.secondary{border:1px solid var(--border);background:var(--panel2);color:var(--text)}button:disabled,.button.disabled{opacity:.38;cursor:not-allowed}.meta{display:grid;gap:8px;margin:18px 0;color:var(--muted);font-size:13px}.meta strong{color:var(--text)}.split{display:grid;grid-template-columns:1fr 1fr;gap:14px}.back{display:inline-block;margin-bottom:18px;color:var(--muted);text-decoration:none}
.db-choice{display:grid;grid-template-columns:1fr 1fr;gap:12px}.db-option.addon-option{display:block;cursor:default}.addon-main{display:flex;gap:10px;cursor:pointer}.addon-main input{width:auto;margin:2px 0 0}.addon-actions{display:flex;flex-wrap:wrap;gap:10px 18px;margin:12px 0 0 26px;padding-top:10px;border-top:1px solid var(--border)}.addon-actions .inline-check{font-size:13px;font-weight:600;cursor:pointer}.connection-test-result{display:inline-block;margin-left:10px;vertical-align:middle;font-size:13px;font-weight:700;color:var(--muted)}.connection-test-result.success{color:#8ee3b5}.connection-test-result.error{color:#ff9cab}.db-option{display:flex;gap:10px;padding:14px;border:1px solid var(--border);border-radius:12px;background:#0d1118;cursor:pointer}.db-option input{width:auto;margin:2px 0 0}.db-option strong{display:block}.db-option span span{display:block;margin-top:4px;color:var(--muted);font-size:13px;font-weight:400}.terms-agreements{display:grid;gap:10px;padding:14px;border:1px solid var(--border);border-radius:12px;background:#0d1118}.terms-check{display:flex;align-items:flex-start;gap:10px;font-weight:500;line-height:1.5}.terms-check input{width:auto;flex:0 0 auto;margin:3px 0 0}.terms-check a,.terms-card a{color:#b8c4ff}.terms-card{margin-top:18px}.backup-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.backup-grid .full-row{grid-column:1/-1}.inline-check{display:flex;align-items:flex-start;gap:10px}.inline-check input{width:auto;margin-top:3px}.terms-scroll{max-height:280px;overflow:auto;padding:16px;border:1px solid var(--border);border-radius:12px;background:#0d1118;color:#d7dceb;white-space:pre-wrap;overflow-wrap:anywhere;font:13px/1.6 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.hidden{display:none}.button-spinner{display:inline-block;width:16px;height:16px;margin-right:8px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;vertical-align:-3px;animation:button-spin .7s linear infinite}@keyframes button-spin{to{transform:rotate(360deg)}}
@media(max-width:760px){.card{grid-column:1/-1}.split,.db-choice,.backup-grid{grid-template-columns:1fr}.backup-grid .full-row{grid-column:auto}.wrap{padding-top:30px}}

.wizard-shell{display:grid;grid-template-columns:240px minmax(0,1fr) 300px;grid-template-areas:"nav content summary";gap:24px;margin-top:28px;align-items:start}.wizard-sidebar{grid-area:nav;position:sticky;top:24px;padding:18px;border:1px solid var(--border);border-radius:20px;background:rgba(18,22,31,.9);backdrop-filter:blur(14px)}.wizard-progress{height:5px;border-radius:999px;background:#0d1118;overflow:hidden;margin:4px 0 18px}.wizard-progress>span{display:block;height:100%;width:11.111%;border-radius:inherit;background:linear-gradient(90deg,#7892f9,#9d7bf5);transition:width .25s ease}.wizard-nav{display:grid;gap:7px}.wizard-nav button{display:grid;grid-template-columns:32px 1fr 18px;align-items:center;gap:9px;width:100%;padding:10px;border:1px solid transparent;border-radius:12px;background:transparent;color:var(--muted);text-align:left;font-weight:700}.wizard-nav button:hover:not(:disabled){background:var(--panel2);color:var(--text)}.wizard-nav button.active{border-color:rgba(128,147,249,.36);background:rgba(120,146,249,.10);color:var(--text)}.wizard-nav button.done{color:#bfe9d1}.wizard-nav button:disabled{opacity:.38}.wizard-step-no{display:grid;place-items:center;width:30px;height:30px;border-radius:10px;background:#0d1118;border:1px solid var(--border);font-size:12px}.wizard-nav .active .wizard-step-no{background:linear-gradient(145deg,#7892f9,#9d7bf5);color:#fff;border-color:transparent}.wizard-check{font-size:13px;color:var(--good)}.wizard-content{grid-area:content;min-width:0}.wizard-panel{display:none}.wizard-panel.active{display:block;animation:wizard-in .22s ease}@keyframes wizard-in{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}.wizard-kicker{display:flex;align-items:center;gap:8px;color:#aeb8ca;font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.12em;margin-bottom:10px}.wizard-title{font-size:clamp(30px,4.5vw,48px);letter-spacing:-.045em;margin:0 0 10px}.wizard-lead{max-width:820px;color:var(--muted);font-size:16px;line-height:1.65;margin:0 0 24px}.wizard-card{padding:24px;border:1px solid var(--border);border-radius:20px;background:linear-gradient(180deg,rgba(23,28,39,.96),rgba(18,22,31,.96));box-shadow:0 18px 70px rgba(0,0,0,.18)}.wizard-card+.wizard-card{margin-top:16px}.wizard-card h3{margin:0 0 8px}.wizard-card p{color:var(--muted);line-height:1.6}.wizard-actions{display:flex;flex-wrap:wrap;gap:10px;align-items:center;margin-top:20px}.wizard-actions.end{justify-content:space-between}.wizard-callout{display:grid;grid-template-columns:42px 1fr;gap:12px;padding:16px;border:1px solid rgba(242,198,109,.28);border-radius:15px;background:rgba(242,198,109,.07);color:#e8d5ac;line-height:1.55}.wizard-callout.danger{border-color:rgba(255,108,128,.35);background:rgba(255,108,128,.08);color:#ffd1d8}.wizard-callout.good{border-color:rgba(99,210,151,.3);background:rgba(99,210,151,.07);color:#ccefdc}.wizard-callout-icon{display:grid;place-items:center;width:38px;height:38px;border-radius:12px;background:rgba(255,255,255,.06);font-size:18px}.wizard-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.choice-card{padding:18px;border:1px solid var(--border);border-radius:16px;background:#0d1118}.choice-card.recommended{border-color:rgba(120,146,249,.42);box-shadow:inset 0 0 0 1px rgba(120,146,249,.12)}.choice-card h3{margin:8px 0}.choice-card p{margin:0;color:var(--muted);line-height:1.55}.choice-badge{display:inline-flex;padding:5px 9px;border-radius:999px;background:rgba(120,146,249,.12);color:#cbd4ff;font-size:11px;font-weight:800}.wizard-divider{height:1px;background:var(--border);margin:22px 0}.wizard-status-row{display:flex;flex-wrap:wrap;gap:8px;margin:14px 0}.wizard-pill{display:inline-flex;align-items:center;gap:6px;padding:7px 10px;border-radius:999px;background:#0d1118;border:1px solid var(--border);color:var(--muted);font-size:12px;font-weight:700}.wizard-pill.good{color:var(--good);border-color:rgba(99,210,151,.24)}.wizard-pill.warn{color:var(--warn);border-color:rgba(242,198,109,.24)}.wizard-lock{padding:18px;text-align:center;border:1px dashed var(--border);border-radius:15px;color:var(--muted)}.access-box{margin-top:16px;padding:16px;border:1px solid var(--border);border-radius:15px;background:#0d1118}.access-box-title{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:10px}.access-box-title strong{font-size:13px}.copy-field{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center}.copy-field input{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px}.copy-field button{white-space:nowrap;padding:11px 13px}.wizard-notice{display:none;margin:0 0 18px}.wizard-notice.show{display:block}.app-installer-drawer{margin-top:16px}.app-installer-drawer:empty{display:none}.app-installer-drawer .card{grid-column:1/-1;width:100%}.backup-fragment>.grid{margin-top:0}.backup-phase{margin:22px 0 10px;padding:10px 2px}.backup-phase strong{display:block;font-size:14px}.backup-phase span{color:var(--muted);font-size:13px}.finish-warning{border:1px solid rgba(255,108,128,.45);background:linear-gradient(180deg,rgba(255,108,128,.12),rgba(255,108,128,.05));padding:24px;border-radius:20px}.finish-warning h3{font-size:24px;margin:0 0 8px}.review-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.review-item{padding:13px;border:1px solid var(--border);border-radius:12px;background:#0d1118}.review-item small{display:block;color:var(--muted);margin-bottom:4px}.review-item strong{font-size:14px}.host-rule{font-size:15px}.host-rule code{font-size:14px}.spinner-inline{display:inline-block;width:15px;height:15px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:button-spin .7s linear infinite;vertical-align:-2px;margin-right:7px}
.copy-feedback-check{display:inline-grid;place-items:center;margin-right:6px;font-weight:1000;transform-origin:center;animation:copy-check-pop .38s cubic-bezier(.2,.9,.3,1.35)}@keyframes copy-check-pop{0%{opacity:0;transform:scale(.35) rotate(-18deg)}65%{opacity:1;transform:scale(1.25) rotate(3deg)}100%{opacity:1;transform:scale(1) rotate(0)}}
.wizard-summary-panel{grid-area:summary;position:sticky;top:24px;min-width:0;padding:18px;border:1px solid var(--border);border-radius:20px;background:rgba(18,22,31,.92);backdrop-filter:blur(14px)}.wizard-summary-panel h3{margin:0 0 6px;font-size:16px}.wizard-summary-panel>p{margin:0 0 12px;color:var(--muted);font-size:12px;line-height:1.5}.wizard-summary-warning{margin:10px 0 12px;padding:10px;border:1px solid rgba(242,198,109,.24);border-radius:11px;background:rgba(242,198,109,.06);color:#e8d5ac;font-size:11px;line-height:1.45}.wizard-summary-text{width:100%;height:min(54vh,560px);min-height:310px;resize:vertical;padding:12px;border-radius:12px;white-space:pre;overflow:auto;font:11px/1.5 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.wizard-summary-actions{display:flex;gap:8px;margin-top:10px}.wizard-summary-actions button{width:100%;padding:10px 12px;font-size:12px}.backup-fragment{width:100%;min-width:0}.backup-fragment>.grid{width:100%;min-width:0}.backup-fragment>.grid>.backup-phase,.backup-fragment>.grid>.wizard-card,.backup-fragment>.grid>.notice{grid-column:1/-1;width:100%;min-width:0}
@media(max-width:1180px) and (min-width:901px){.wizard-shell{grid-template-columns:230px minmax(0,1fr);grid-template-areas:"nav content" "summary content"}.wizard-summary-panel{position:sticky;top:430px}}
@media(max-width:900px){.wizard-shell{grid-template-columns:1fr;grid-template-areas:"nav" "summary" "content"}.wizard-sidebar,.wizard-summary-panel{position:static}.wizard-nav{grid-template-columns:repeat(4,minmax(0,1fr))}.wizard-nav button{grid-template-columns:1fr;justify-items:center;text-align:center;padding:9px 6px}.wizard-nav button span:nth-child(2),.wizard-check{display:none}.wizard-step-no{width:34px;height:34px}.wizard-choice-grid,.review-grid{grid-template-columns:1fr}}
@media(max-width:560px){.wizard-nav{grid-template-columns:repeat(4,minmax(0,1fr));gap:4px}.wizard-sidebar{padding:12px}.wizard-card{padding:18px}.wizard-actions.end{align-items:stretch}.wizard-actions.end>*{width:100%}}

.key-gate{position:fixed;inset:0;z-index:10000;display:grid;place-items:center;padding:22px;background:rgba(5,7,11,.86);backdrop-filter:blur(14px)}.key-gate.hidden{display:none}.key-gate-card{width:min(720px,100%);max-height:calc(100vh - 44px);overflow:auto;padding:28px;border:1px solid #30394a;border-radius:22px;background:linear-gradient(180deg,#171c27,#10141c);box-shadow:0 30px 120px rgba(0,0,0,.55)}.key-gate-kicker{font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.13em;color:#aeb8ca}.key-gate-card h2{margin:8px 0 10px;font-size:clamp(28px,5vw,42px);letter-spacing:-.04em}.key-gate-card p{color:var(--muted);line-height:1.65}.key-gate-choice{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:22px}.key-gate-option{padding:18px;border:1px solid var(--border);border-radius:16px;background:#0d1118}.key-gate-option.recommended{border-color:rgba(120,146,249,.5)}.key-gate-option h3{margin:6px 0}.key-field-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px;align-items:center}.key-field-row input{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}.key-wait-list{display:grid;gap:10px;margin:20px 0}.key-wait-item{display:flex;align-items:center;gap:10px;padding:11px 13px;border:1px solid var(--border);border-radius:12px;background:#0d1118;color:var(--muted)}.key-wait-item.good{color:var(--good);border-color:rgba(99,210,151,.28)}.key-wait-item.active{color:#dce3ff;border-color:rgba(120,146,249,.35)}.key-spinner{width:16px;height:16px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:button-spin .7s linear infinite}.key-gate-error{margin-top:14px;padding:12px 14px;border:1px solid rgba(255,125,142,.4);border-radius:12px;color:#ffd5dc;background:rgba(255,108,128,.07)}.key-gate-note{padding:14px;border:1px solid rgba(242,198,109,.26);border-radius:12px;background:rgba(242,198,109,.07);color:#e8d5ac;line-height:1.55}.key-fingerprint{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}@media(max-width:700px){.key-gate-choice{grid-template-columns:1fr}.key-field-row{grid-template-columns:1fr}.key-gate-card{padding:20px}}
</style>
</head>
<body>
<?php if ($keyGateRequired): ?>
<div id="everlomp-key-gate" class="key-gate" role="dialog" aria-modal="true" aria-labelledby="everlomp-key-title">
<div class="key-gate-card">
<div class="key-gate-kicker">Before installation</div>
<h2 id="everlomp-key-title">Protect stored credentials.</h2>

<?php if ($keyMode === 'enabled' && !$keyValid): ?>
<p>Everlomp was configured to use encryption, but this container does not have a valid <code>/run/secrets/key</code>. Normal installation is blocked so encrypted credentials are never silently replaced or written in plaintext.</p>
<div class="key-gate-error"><strong>Key unavailable.</strong><br><?= h((string) ($keyStatus['key_error'] ?? 'Set the correct Docker Swarm secret and replace this Swarm task.')) ?></div>
<div class="key-wait-list"><div id="key-wait-restart" class="key-wait-item active"><span class="key-spinner"></span><span>Waiting for a fresh Swarm task with the original key…</span></div><div id="key-wait-key" class="key-wait-item"><span>○</span><span>Waiting for <code>/run/secrets/key</code></span></div><div id="key-wait-ready" class="key-wait-item"><span>○</span><span>Waiting for Everlomp services</span></div></div>
<div class="actions"><button type="button" id="key-replace-task-button" onclick="replaceEverlompSwarmTask(this)">I restored the key — replace container now</button><button type="button" style="margin-left:6px;" class="secondary" id="key-restart-confirm-button" onclick="confirmEverlompRestart(this)">It has restarted now — check</button></div>
<div id="key-gate-runtime-error" class="key-gate-error hidden"></div>

<?php elseif ($keyMode === 'pending'): ?>
<p>This installation is waiting for you to set the Docker Swarm secret named <code>key</code> in Evernode. After saving it, Everlomp can force the current Swarm task to exit so Docker creates a fresh task and remounts the secret. Automatic detection still works if Evernode already replaced the task for you.</p>
<div class="key-gate-note">If you generated a key before the restart, use that exact value in Evernode. If you are restoring an existing installation, use the original encryption key.</div>
<div class="key-wait-list">
<div id="key-wait-submitted" class="key-wait-item good">✓ Encryption mode marked as pending</div>
<div id="key-wait-restart" class="key-wait-item active"><span class="key-spinner"></span><span>Waiting for a new container instance…</span></div>
<div class="actions"><button type="button" id="key-replace-task-button" onclick="replaceEverlompSwarmTask(this)">I saved the key — replace container now</button><button type="button" class="secondary" style="margin-left:6px;" id="key-restart-confirm-button" onclick="confirmEverlompRestart(this)">It has restarted now — check</button></div>
<div id="key-wait-key" class="key-wait-item"><span>○</span><span>Waiting for <code>/run/secrets/key</code></span></div>
<div id="key-wait-ready" class="key-wait-item"><span>○</span><span>Waiting for Everlomp services</span></div>
</div>
<div id="key-gate-runtime-error" class="key-gate-error hidden"></div>
<div class="wizard-actions"><button type="button" class="secondary" onclick="disableEverlompKeyMode()">Continue without encryption instead</button></div>

<?php else: ?>
<p>Everlomp can keep recoverable credentials encrypted on persistent storage. The encryption key itself stays in Docker Swarm at <code>/run/secrets/key</code> and is never stored by Everlomp.</p>
<p class="meta">After a Swarm task replacement, Everlomp reconstructs task-local service state such as the SSH password hash/port and runtime-built Kopia executable from persistent state.</p>
<?php if ($keyValid): ?>
<div class="wizard-callout good"><div class="wizard-callout-icon">✓</div><div><strong>A valid key is already mounted.</strong><br>Fingerprint: <span class="key-fingerprint"><?= h((string) ($keyStatus['key_fingerprint'] ?? '')) ?></span>. You can enable encryption immediately without another restart.</div></div>
<div class="wizard-actions"><button type="button" onclick="enableMountedEverlompKey()">Use mounted key</button><button type="button" class="secondary" onclick="disableEverlompKeyMode()">Continue without encryption</button></div>
<?php else: ?>
<div class="key-gate-choice">
<div class="key-gate-option recommended"><span class="choice-badge">Recommended</span><h3>Use encryption key</h3><p>Generate a fresh 256-bit key or paste the key from an existing Everlomp installation. You will copy it and set the <code>key</code> secret in Evernode yourself.</p><button type="button" onclick="prepareEverlompKeyMode()">Use encryption</button></div>
<div class="key-gate-option"><span class="choice-badge">Plaintext compatibility</span><h3>Continue without key</h3><p>Passwords that must remain recoverable may be stored as root-only plaintext files. Login passwords that can be hashed remain hash-only.</p><button type="button" class="secondary" onclick="disableEverlompKeyMode()">No encryption key</button></div>
</div>
<div id="key-entry-panel" class="hidden" style="margin-top:22px">
<div class="wizard-divider"></div>
<h3>Generate or paste the key</h3>
<p>The value below is <strong>never submitted to Everlomp</strong>. Copy it, open Evernode, replace the Docker Swarm secret named <code>key</code>, and save it. Evernode will restart the container.</p>
<div class="key-field-row"><input id="everlomp-key-value" type="password" autocomplete="off" spellcheck="false" placeholder="43-char base64url key or 64-char hex key"><button type="button" class="secondary" onclick="copyEverlompKey(this)">Copy</button></div>
<div class="wizard-actions"><button type="button" onclick="generateEverlompKey()">Generate new 256-bit key</button><button id="everlomp-key-toggle" type="button" class="secondary" onclick="toggleEverlompKeyVisibility()">Show key</button></div>
<div id="key-entry-error" class="key-gate-error hidden"></div>
<div class="key-gate-note" style="margin-top:14px"><strong>Restoring?</strong> Paste the same key used by the original installation. A different key cannot decrypt its saved credentials.</div>
<div class="wizard-actions"><button type="button" onclick="beginEverlompRestartWait()">I copied it — start waiting</button><button type="button" class="secondary" onclick="disableEverlompKeyMode()">Cancel and continue without encryption</button></div>
</div>
<?php endif; ?>
<?php endif; ?>
</div>
</div>
<?php endif; ?>
<div class="wrap">
<header class="brand"><div class="logo">E</div><div><h1>Everlomp</h1><p>Application setup and server tools</p></div></header>

<?php if ($showBackupManager): ?>

<a target="_blank" rel="noopener noreferrer" class="back" href="<?= h($panelUrl) ?>">← Back to Everlomp</a>
<section class="hero"><h2>Backup setup.</h2><p>Kopia owns repositories, snapshots, policies, and replication. This one-time Everlomp page only handles installation plus optional SQL-dump and update helpers before it is removed.</p></section>
<?php if ($message !== ''): ?><div class="notice success"><?= h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<div class="grid">
<section class="card full">
<?php if ($kopiaConfigured): ?>
<div class="status good">● Kopia configured</div>
<h3>Kopia</h3>
<p>Kopia is installed by Everlomp, not baked into the Docker image. Online setup downloads official Kopia/HTMLUI source and builds the custom <code>/kopia/</code> Web UI binary. Local setup uses the bundled <code>kopia_source.tar.gz</code> + <code>htmlui_source.tar.gz</code> archives, applies the same Everlomp patch, then compiles them during this one installation.</p>
<div class="meta">
<div>Repository: <strong><?= h((string) ($kopiaInfo['repository_path'] ?? 'configured')) ?></strong></div>
<div>Version: <strong><?= h((string) ($backupInfo['kopia_version'] ?? $kopiaInfo['version'] ?? 'installed')) ?></strong></div>
<div>Install source: <strong><?= (($kopiaInfo['install_source'] ?? '') === 'local') ? 'Bundled local source' : 'Online/custom build' ?></strong></div>
<div>Web UI: <strong><?= h($kopiaDashboardUrl) ?></strong></div>
<div>Web UI user: <strong>admin</strong></div>
<div>Web UI password: <strong><?= $keyMode === 'enabled' ? 'Encrypted at rest; decrypted only at runtime' : 'Stored root-only for service restart' ?></strong></div>
<div>Build/update tools: <strong><?= $kopiaBuildToolsReady ? 'Git + Go installed' : 'Git + Go missing' ?></strong></div>
</div>
<div class="warning">Kopia is exposed at <code>/kopia/</code>. OpenLiteSpeed forwards that path to nginx; nginx strips the prefix for Kopia and rewrites redirects, cookies, and Web UI absolute paths back under <code>/kopia/</code>.</div>
<div class="access-box"><div class="access-box-title"><strong>Kopia dashboard</strong><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($kopiaDashboardUrl) ?>">Open</a></div><div class="copy-field"><input id="url-kopia-dashboard" type="text" readonly value="<?= h($kopiaDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-kopia-dashboard',this)">Copy URL</button></div></div>
<div class="actions">
<?php if (!$kopiaBuildToolsReady): ?>
<form method="post" style="display:inline-block;margin:0"><input type="hidden" name="action" value="prepare_kopia_build"><button type="submit">Install/Repair Kopia Build Tools</button></form>
<?php endif; ?>
<?php if ($kopiaUrl !== ''): ?>
<a style="display:none;" target="_blank" rel="noopener noreferrer" class="button" href="<?= h($kopiaUrl) ?>">Open Kopia</a>
<?php endif; ?>
</div>
<?php else: ?>
<div class="status warn">● Kopia not installed/configured</div>
<h3>Enable Kopia</h3>
<p>Install Kopia once, then manage backups from Kopia itself. You can create a local primary repository now, or start Kopia disconnected and choose any supported primary destination later from <strong>Kopia → Repository</strong>.</p>
<div class="warning">Both choices compile Kopia once during this installation. By default Everlomp downloads official Kopia/HTMLUI source. If both <code>everlomp/local-kopia/kopia_source.tar.gz</code> and <code>htmlui_source.tar.gz</code> are bundled, <strong>Install local version</strong> uses those archives instead, applies the same <code>/kopia/</code> patch, and does not download Kopia/HTMLUI source.</div>
<form id="kopia-configure-form" method="post" autocomplete="off">
<input type="hidden" name="action" value="configure_kopia">
<label class="inline-check"><input id="kopia-install-local-version" type="checkbox" name="kopia_install_local_version" value="1" <?= $kopiaLocalAvailable ? '' : 'disabled' ?>><span><strong>Install local version</strong><br><small><?= $kopiaLocalAvailable ? 'Both local source archives found. Setup will patch and compile them instead of downloading Kopia/HTMLUI source.' : 'Add both <code>kopia_source.tar.gz</code> and <code>htmlui_source.tar.gz</code> to <code>everlomp/local-kopia/</code>, then rebuild the image.' ?></small></span></label>
<?php if ($kopiaLocalAvailable): ?>
<div class="status good">● Local Kopia + HTMLUI source archives available</div>
<?php else: ?>
<div class="status warn">● Local Kopia source archives incomplete or missing</div>
<?php endif; ?>
<h3>Primary repository</h3>
<label>Primary repository setup
<select id="kopia-repository-mode" name="kopia_repository_mode" onchange="updateKopiaRepositoryMode(this)">
<option value="create" selected>Create local primary repository now</option>
<option value="ui">Choose primary repository later in Kopia UI</option>
</select>
<small>The second option starts Kopia disconnected. After installation, open <strong>Kopia → Repository</strong> and choose local/NAS, SFTP, WebDAV, S3-compatible, or another Kopia-supported destination.</small>
</label>
<div id="kopia-local-primary-fields">
<label>Kopia repository path<input id="kopia-repository-path" type="text" name="kopia_repository_path" value="/home/everlomp/kopiasnapshots" maxlength="512" required><small>Stored under the persistent <code>/home</code> volume. Must be <code>/home/everlomp/kopiasnapshots</code> or below it.</small></label>
<label>Repository encryption
<select id="kopia-encryption" name="kopia_encryption" required>
<option value="AES256-GCM-HMAC-SHA256" selected>AES-256-GCM (recommended default)</option>
<option value="CHACHA20-POLY1305-HMAC-SHA256">ChaCha20-Poly1305</option>
</select>
<small>Used when Everlomp creates the local primary repository.</small>
</label>
<div class="split">
<label>Repository encryption password<input id="kopia-repository-password" type="password" name="kopia_repository_password" minlength="12" autocomplete="new-password" required></label>
<label>Confirm repository password<input id="kopia-repository-password-confirm" type="password" name="kopia_repository_password_confirm" minlength="12" autocomplete="new-password" required></label>
</div>
<div class="actions">
<button type="button" class="secondary" onclick="generateKopiaRepositoryPassword()">Generate Repository Password</button>
<button id="kopia-repository-password-toggle" type="button" class="secondary" onclick="toggleKopiaRepositoryPassword()">Show Password</button>
</div>
<div class="warning"><strong>Save the repository password somewhere outside this server.</strong> It is required to recover or reconnect to the repository. Everlomp keeps a <?= $keyMode === 'enabled' ? 'master-key-encrypted' : 'root-only' ?> local copy so the Kopia service can reconnect after restarts, but that copy is not a substitute for an external recovery record.</div>
</div>

<div id="kopia-default-snapshots-section">
<h3>Default snapshot sources</h3>
<label class="inline-check"><input id="kopia-default-snapshots-enabled" type="checkbox" name="kopia_default_snapshots_enabled" value="1" onchange="toggleKopiaDefaultSnapshots()"><span><strong>Enable native Kopia daily snapshots</strong><br><small>Registers <code>/var/www</code> and <code>/home/everlomp/everbackups/sql</code> as Kopia sources, takes their first snapshots during setup, and gives both a daily Kopia policy.</small></span></label>
<div id="kopia-default-snapshot-fields" class="hidden">
<div class="backup-grid">
<label>Website source<input type="text" value="/var/www" readonly></label>
<label>SQL dump source<input type="text" value="/home/everlomp/everbackups/sql" readonly></label>
<label>Daily snapshot time<input type="time" name="kopia_default_snapshot_time" value="03:00"></label>
</div>
<div class="warning"><strong>Kopia owns this schedule after installation.</strong> You can change or disable it later from Kopia → Policies. The first snapshots are created during setup so Kopia registers both sources. The SQL dump generator is separate; its default daily time is 02:30, so 03:00 snapshots capture the newest dump when SQL backups are enabled.</div>
</div>
</div>

<div class="warning"><strong>Using Kopia UI for the primary?</strong> Everlomp starts Kopia disconnected. Create/connect the repository from <strong>Kopia → Repository</strong>; the custom UI automatically stores the encryption password <?= $keyMode === 'enabled' ? 'encrypted at rest with /run/secrets/key' : 'root-only' ?> for service restarts and scheduled replication. Default snapshot bootstrap above is available only when the local primary repository is created during installation.</div>

<h3>Optional initial replica</h3>
<label class="inline-check"><input id="kopia-offsite-enabled" type="checkbox" name="kopia_offsite_enabled" value="1" onchange="toggleKopiaOffsite()"><span><strong>Add the first secondary repository replica during installation</strong><br><small>This is saved into the new <strong>Kopia → Replication</strong> tab. You can add, edit, test, sync, or remove replicas there later even after the Everlomp installer is deleted.</small></span></label>
<div id="kopia-offsite-fields" class="hidden">
<div class="backup-grid">
<label>Destination type
<select id="kopia-offsite-type" class="offsite-provider-select" name="kopia_offsite_type" onchange="updateOffsiteProvider(this)">
<option value="sftp" selected>SFTP / SSH server (recommended self-hosted)</option>
<option value="webdav">WebDAV server / Nextcloud</option>
<option value="filesystem">Mounted NAS / NFS / SMB / filesystem</option>
<option value="s3">S3-compatible / MinIO</option>
<option value="from-config">Kopia configuration token (advanced)</option>
<option value="rclone">Rclone remote (advanced)</option>
</select>
</label>
<label>Replication schedule<select class="schedule-mode-select" name="kopia_offsite_schedule" onchange="updateScheduleFields(this)"><option value="hourly">Hourly</option><option value="every6h" selected>Every 6 hours</option><option value="every12h">Every 12 hours</option><option value="daily">Daily (every day)</option><option value="weekly">Weekly</option></select></label>
<label class="hidden" data-schedule-for="kopia_offsite_schedule" data-schedule-role="time">Time (container local time)<input type="time" name="kopia_offsite_time" value="03:30"></label>
<label class="hidden" data-schedule-for="kopia_offsite_schedule" data-schedule-role="weekday">Day of week<select name="kopia_offsite_weekday"><?php foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $i=>$day): ?><option value="<?= $i ?>"><?= h($day) ?></option><?php endforeach; ?></select></label>
</div>

<div class="offsite-provider-fields" data-offsite-provider="sftp">
<h4>SFTP / SSH server</h4>
<p><small>Best general self-hosted option for another VPS, Linux server, TrueNAS/Synology-style NAS with SFTP, or any SSH-accessible host.</small></p>
<div class="backup-grid">
<label>Host<input type="text" name="kopia_offsite_sftp_host" maxlength="255" placeholder="backup.example.net"></label>
<label>Port<input type="number" name="kopia_offsite_sftp_port" min="1" max="65535" value="22"></label>
<label>Username<input type="text" name="kopia_offsite_sftp_username" maxlength="255" placeholder="backup"></label>
<label>Remote repository path<input type="text" name="kopia_offsite_sftp_path" maxlength="1024" value="everbackups/" placeholder="everbackups/"></label>
<div class="full-row warning sftp-bootstrap-box">
<strong>Optional one-go SSH setup</strong><br>
Use the backup account's current password once. Everlomp can change that remote account password, generate a fresh ED25519 keypair, install the public key into the remote account's <code>~/.ssh/authorized_keys</code>, and verify key login. The current password is not saved by this setup action.
<div class="backup-grid" style="margin-top:12px">
<label>Current remote SSH password<input type="password" name="kopia_offsite_sftp_current_password" maxlength="4096" autocomplete="current-password"></label>
<label class="inline-check"><input type="checkbox" name="kopia_offsite_sftp_generate_keypair" value="1" checked><span><strong>Generate & install SSH keypair</strong><br><small>Recommended. Kopia will use the generated private key after you finish installation.</small></span></label>
<label class="full-row inline-check"><input class="sftp-change-password-toggle" type="checkbox" name="kopia_offsite_sftp_change_password" value="1" onchange="toggleSftpPasswordChange(this)"><span><strong>Change remote account password too</strong><br><small>Requires the remote account to permit normal <code>passwd</code> changes.</small></span></label>
<label class="sftp-new-password-fields hidden">New remote password<input type="password" name="kopia_offsite_sftp_new_password" minlength="12" maxlength="1024" autocomplete="new-password"></label>
<label class="sftp-new-password-fields hidden">Confirm new password<input type="password" name="kopia_offsite_sftp_new_password_confirm" minlength="12" maxlength="1024" autocomplete="new-password"></label>
<div class="full-row sftp-new-password-fields hidden actions"><button type="button" onclick="generateKopiaSftpRemotePassword(this)">Generate Password</button></div>
<div class="full-row actions"><button type="button" onclick="setupKopiaSftpAccess(this)">Apply SSH Setup</button><span class="connection-test-result sftp-bootstrap-result" aria-live="polite"></span></div>
<div class="full-row sftp-generated-key-result hidden"><small>Generated public key:</small><textarea class="sftp-generated-public-key" rows="2" readonly></textarea><small class="sftp-generated-fingerprint"></small></div>
</div>
</div>
<label>Authentication used by Kopia<select class="offsite-sftp-auth" name="kopia_offsite_sftp_auth" onchange="updateSftpAuth(this)"><option value="key" selected>SSH private key</option><option value="password">Password</option></select></label>
<label class="sftp-password-fields hidden">Password used by Kopia<input type="password" name="kopia_offsite_sftp_password" maxlength="4096" autocomplete="new-password"></label>
<label class="full-row sftp-key-fields">SSH private key used by Kopia<textarea name="kopia_offsite_sftp_private_key" rows="7" maxlength="65536" autocomplete="off" placeholder="Use Apply SSH Setup to generate/install one, or paste an existing private key"></textarea><small>The generated private key is saved <?= $keyMode === 'enabled' ? 'encrypted at rest with the Everlomp key' : 'root-only' ?> with the Kopia replica. Kopia uses it through its native SFTP keyfile option.</small></label>
<label class="full-row">SSH host key / known_hosts <span class="muted">(automatic)</span><textarea id="kopia-offsite-sftp-known-hosts" name="kopia_offsite_sftp_known_hosts" rows="3" maxlength="65536" placeholder="Everlomp can fetch this automatically"></textarea><small>Everlomp pins this host key and Kopia uses it on every SFTP connection.</small></label>
<div class="full-row actions sftp-host-key-fetch-row hidden"><button type="button" onclick="fetchKopiaSftpHostKey(this)">Fetch Host Key</button><span class="connection-test-result sftp-host-key-result" aria-live="polite"></span></div>
</div>
</div>

<div class="offsite-provider-fields hidden" data-offsite-provider="webdav">
<h4>WebDAV</h4>
<p><small>Works with self-hosted WebDAV services such as Nextcloud/ownCloud WebDAV endpoints and generic WebDAV servers.</small></p>
<div class="backup-grid">
<label class="full-row">WebDAV URL<input type="url" name="kopia_offsite_webdav_url" maxlength="2048" placeholder="https://cloud.example.net/remote.php/dav/files/user/everlomp/"></label>
<label>Username<input type="text" name="kopia_offsite_webdav_username" maxlength="512"></label>
<label>Password<input type="password" name="kopia_offsite_webdav_password" maxlength="4096" autocomplete="new-password"></label>
</div>
</div>

<div class="offsite-provider-fields hidden" data-offsite-provider="filesystem">
<h4>Mounted NAS / filesystem</h4>
<p><small>For NFS, SMB/CIFS, SSHFS, USB, or another filesystem already mounted into this container. The mount should be physically separate from the local <code>/home/everlomp/kopiasnapshots</code> repository.</small></p>
<div class="backup-grid"><label class="full-row">Destination path inside container<input type="text" name="kopia_offsite_filesystem_path" maxlength="2048" value="/remote-backup/kopia"><small>Example Docker mount: host/NAS storage → <code>/remote-backup</code> inside Everlomp.</small></label></div>
</div>

<div class="offsite-provider-fields hidden" data-offsite-provider="s3">
<h4>S3-compatible / MinIO</h4>
<p><small>Use a self-hosted MinIO/S3-compatible server or an S3-compatible cloud provider.</small></p>
<div class="backup-grid">
<label>Endpoint<input type="text" name="kopia_offsite_endpoint" maxlength="255" placeholder="minio.example.net:9000"><small>Hostname and optional port; no <code>https://</code>.</small></label>
<label>Bucket<input type="text" name="kopia_offsite_bucket" maxlength="255" placeholder="everlomp-backups"></label>
<label>Region<input type="text" name="kopia_offsite_region" maxlength="128" placeholder="us-east-1"><small>Optional for many self-hosted servers.</small></label>
<label>Prefix<input type="text" name="kopia_offsite_prefix" maxlength="512" placeholder="server-01/"></label>
<label>Access key<input type="password" name="kopia_offsite_access_key" maxlength="1024" autocomplete="new-password"></label>
<label>Secret key<input type="password" name="kopia_offsite_secret_key" maxlength="2048" autocomplete="new-password"></label>
<label class="full-row">Session token<input type="password" name="kopia_offsite_session_token" maxlength="4096" autocomplete="new-password"><small>Normally blank.</small></label>
</div>
</div>

<div class="offsite-provider-fields hidden" data-offsite-provider="from-config">
<h4>Kopia configuration token</h4>
<p><small>Advanced escape hatch for a destination represented by a Kopia quick-reconnect/configuration token. Treat the token like a password.</small></p>
<label>Kopia token<textarea name="kopia_offsite_kopia_token" rows="5" maxlength="65536" autocomplete="off" placeholder="03Fy..."></textarea></label>
</div>

<div class="offsite-provider-fields hidden" data-offsite-provider="rclone">
<h4>Rclone remote</h4>
<div class="warning"><strong>Advanced compatibility option.</strong> This reaches many additional storage backends, but Kopia currently marks its Rclone integration as not maintained. Prefer native SFTP, WebDAV, filesystem, or S3-compatible targets when possible.</div>
<div class="backup-grid">
<label class="full-row">Remote path<input type="text" name="kopia_offsite_rclone_remote_path" maxlength="2048" placeholder="remote:backups/everlomp"></label>
<label class="full-row">rclone.conf<textarea name="kopia_offsite_rclone_config" rows="8" maxlength="262144" autocomplete="off" placeholder="[remote]&#10;type = ..."></textarea></label>
</div>
</div>

<div class="actions"><button type="button" class="secondary offsite-test-button" onclick="testKopiaOffsiteConnection(this)">Test Connection</button><span class="connection-test-result offsite-test-result" aria-live="polite"></span></div>
<div class="warning"><strong><?= $keyMode === 'enabled' ? 'Secrets are encrypted at rest.' : 'Secrets are stored root-only.' ?></strong> Replica credentials are kept under <code>/home/everlomp/secrets/kopia/replication/</code><?= $keyMode === 'enabled' ? ' as AES-256-GCM ciphertext protected by /run/secrets/key' : ' as root-only compatibility files' ?>. After installation, manage this destination from <strong>Kopia → Replication</strong>. Synchronization intentionally does not use Kopia's <code>--delete</code> option.</div>
</div>

<div class="terms-agreements">
<label class="terms-check"><input type="checkbox" name="accept_kopia_terms" value="1" required><span>I have read and agreed to the <a target="_blank" rel="noopener noreferrer" href="https://github.com/kopia/kopia/blob/master/LICENSE">terms and conditions of Kopia</a>.</span></label>
<label class="terms-check"><input type="checkbox" name="accept_everlomp_terms" value="1" required><span>I have read and agreed to the <a target="_blank" rel="noopener noreferrer" href="#everlomp-installation-terms">terms and conditions for this app, visible on this installation page</a>.</span></label>
</div>
<div class="actions"><button id="kopia-configure-submit" type="submit">Configure &amp; Enable Kopia</button></div>
</form>
<?php endif; ?>

<?php if ($kopiaGeneratedPassword !== ''): ?>
<div class="warning">
<strong>Kopia Web UI details</strong><br>
These details are also saved in the setup notes for this browser tab.<br><br>
Dashboard: <code id="kopia-generated-url"><?= h($kopiaDashboardUrl) ?></code><br>
Username: <code id="kopia-generated-username"><?= h($kopiaGeneratedUsername) ?></code><br>
Password: <code id="kopia-generated-password"><?= h($kopiaGeneratedPassword) ?></code><br>
Primary repository: <code><?= h($kopiaGeneratedRepository !== '' ? $kopiaGeneratedRepository : 'Configure in Kopia → Repository') ?></code>
<?php if ($kopiaGeneratedVersion !== ''): ?><br>Version: <code><?= h($kopiaGeneratedVersion) ?></code><?php endif; ?>
<div class="actions"><button type="button" class="secondary" onclick="copyKopiaPassword(this)">Copy Password</button><button type="button" class="secondary" onclick="copyTextValue(<?= json_encode($kopiaDashboardUrl) ?>,this)">Copy Dashboard URL</button><a target="_blank" rel="noopener noreferrer" class="button secondary" href="<?= h($kopiaDashboardUrl) ?>">Open Kopia</a></div>
</div>
<?php endif; ?>
</section>

<?php if ($kopiaConfigured): ?>
<section class="card full">
<div class="status good">● Kopia owns repository replication</div>
<h3>Repository replication</h3>
<p>Remote replica settings are no longer managed by this one-time Everlomp page. Open <strong>Kopia → Replication</strong> to add destinations, test connections, change schedules, run a sync, or remove a replica.</p>
<div class="actions"><a target="_blank" rel="noopener noreferrer" class="button" href="<?= h($kopiaReplicationDashboardUrl) ?>">Open Kopia Replication</a></div><div class="access-box"><div class="access-box-title"><strong>Kopia replication dashboard</strong></div><div class="copy-field"><input id="url-kopia-replication" type="text" readonly value="<?= h($kopiaReplicationDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-kopia-replication',this)">Copy URL</button></div></div>
</section>
<?php endif; ?>

<section class="card full">
<div class="status <?= !empty($backupInfo['sql_enabled']) ? 'good' : 'warn' ?>">● SQL dump schedule <?= !empty($backupInfo['sql_enabled']) ? 'enabled' : 'disabled' ?></div>
<h3>MariaDB logical backups</h3>
<p>Everlomp generates compressed logical dumps of all local databases through the MariaDB socket. Dumps are stored at <code>/home/everlomp/everbackups/sql</code> for Kopia to snapshot.</p>
<div class="meta">
<div>Last run: <strong><?= h((string) ($backupInfo['sql_last_run'] ?? 'Never')) ?></strong></div>
<div>Last status: <strong><?= h((string) ($backupInfo['sql_last_status'] ?? 'Not run')) ?></strong></div>
<?php if (($backupInfo['sql_last_file'] ?? '') !== ''): ?><div>Last file: <strong><?= h(basename((string) $backupInfo['sql_last_file'])) ?></strong></div><?php endif; ?>
</div>
<form method="post">
<input type="hidden" name="action" value="save_sql_backup_settings">
<label class="inline-check"><input type="checkbox" name="sql_enabled" value="1" <?= !empty($backupInfo['sql_enabled']) ? 'checked' : '' ?>><span>Enable automatic SQL backup generation</span></label>
<div class="backup-grid">
<label>Schedule<select class="schedule-mode-select" name="sql_schedule" onchange="updateScheduleFields(this)">
<?php $sqlSchedule = (string) ($backupInfo['sql_schedule'] ?? 'daily'); foreach (['hourly'=>'Hourly','every6h'=>'Every 6 hours','every12h'=>'Every 12 hours','daily'=>'Daily (every day)','weekly'=>'Weekly'] as $value=>$label): ?>
<option value="<?= h($value) ?>" <?= $sqlSchedule === $value ? 'selected' : '' ?>><?= h($label) ?></option>
<?php endforeach; ?>
</select></label>
<label class="<?= in_array($sqlSchedule, ['daily', 'weekly'], true) ? '' : 'hidden' ?>" data-schedule-for="sql_schedule" data-schedule-role="time">Time (container local time)<input type="time" name="sql_time" value="<?= h((string) ($backupInfo['sql_time'] ?? '02:30')) ?>" required></label>
<label class="<?= $sqlSchedule === 'weekly' ? '' : 'hidden' ?>" data-schedule-for="sql_schedule" data-schedule-role="weekday">Day of week<select name="sql_weekday"><?php $sqlWeekday=(int)($backupInfo['sql_weekday'] ?? 0); foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $i=>$day): ?><option value="<?= $i ?>" <?= $sqlWeekday === $i ? 'selected' : '' ?>><?= h($day) ?></option><?php endforeach; ?></select></label>
<label>Keep newest dumps<input type="number" name="sql_keep" min="1" max="365" value="<?= h((string) ($backupInfo['sql_keep'] ?? 7)) ?>" required></label>
</div>
<div class="warning">Hourly / 6-hour / 12-hour schedules run by elapsed interval. Daily means every day and asks only for a time. Weekly asks for a day and time. Times use the container's local time (the TZ environment).</div>
<div class="actions"><button type="submit">Save SQL Backup Settings</button></div>
</form>
<form method="post"><input type="hidden" name="action" value="run_sql_backup"><div class="actions"><button type="submit" class="secondary">Run SQL Backup Now</button></div></form>
</section>

<?php if ($kopiaConfigured): ?>
<section class="card full">
<div class="status <?= !empty($backupInfo['kopia_auto_update']) ? 'good' : 'warn' ?>">● Automatic Kopia updates <?= !empty($backupInfo['kopia_auto_update']) ? 'enabled' : 'disabled' ?></div>
<h3>Kopia source updates</h3>
<p>Automatic updates are optional. When enabled, Everlomp checks the latest stable Kopia release, builds the matching <code>/kopia/</code>-aware binary from official source, verifies it, then swaps it in and restarts Kopia. Warning: Automatic updates could crash kopia as the system has customizations built in in the htmlui.</p>
<div class="meta">
<div>Last check: <strong><?= h((string) ($backupInfo['kopia_update_last_run'] ?? 'Never')) ?></strong></div>
<div>Last result: <strong><?= h((string) ($backupInfo['kopia_update_last_status'] ?? 'Not run')) ?></strong></div>
</div>
<form method="post">
<input type="hidden" name="action" value="save_kopia_update_settings">
<label class="inline-check"><input type="checkbox" name="kopia_auto_update" value="1" <?= !empty($backupInfo['kopia_auto_update']) ? 'checked' : '' ?>><span>Automatically build and install stable Kopia updates</span></label>
<div class="backup-grid">
<label>Check schedule<select class="schedule-mode-select" name="kopia_update_schedule" onchange="updateScheduleFields(this)"><?php $updateSchedule=(string)($backupInfo['kopia_update_schedule'] ?? 'weekly'); ?><option value="weekly" <?= $updateSchedule === 'weekly' ? 'selected' : '' ?>>Weekly</option><option value="daily" <?= $updateSchedule === 'daily' ? 'selected' : '' ?>>Daily (every day)</option></select></label>
<label data-schedule-for="kopia_update_schedule" data-schedule-role="time">Time (container local time)<input type="time" name="kopia_update_time" value="<?= h((string) ($backupInfo['kopia_update_time'] ?? '04:30')) ?>" required></label>
<label class="<?= $updateSchedule === 'weekly' ? '' : 'hidden' ?>" data-schedule-for="kopia_update_schedule" data-schedule-role="weekday">Day of week<select name="kopia_update_weekday"><?php $updateWeekday=(int)($backupInfo['kopia_update_weekday'] ?? 0); foreach (['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'] as $i=>$day): ?><option value="<?= $i ?>" <?= $updateWeekday === $i ? 'selected' : '' ?>><?= h($day) ?></option><?php endforeach; ?></select></label>
</div>
<div class="warning">Automatic updates replace only the Kopia executable after a successful source build and verification, then restart the Kopia Supervisor process if it is running. Repository-format upgrades are not performed automatically.</div>
<div class="actions"><button type="submit">Save Kopia Update Settings</button></div>
</form>
</section>
<?php endif; ?>

<section class="card full terms-card" id="everlomp-installation-terms">
<h3>Terms and conditions for this installation</h3>
<p>These terms are loaded from <code>/home/everlomp/terms.md</code>.</p>
<div class="terms-scroll"><?php if ($everlompTerms !== ''): ?><?= h($everlompTerms) ?><?php else: ?>No terms text was found. Expected file: /home/everlomp/terms.md<?php endif; ?></div>
</section>
</div>

<?php elseif ($showWordPressInstaller): ?>

<a target="_blank" rel="noopener noreferrer" class="back" href="<?= h($panelUrl) ?>">← Back to Everlomp</a>
<section class="hero"><h2>Install WordPress.</h2><p>Create a fresh local database automatically, or use any existing MySQL/MariaDB database you provide.</p></section>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<section class="card full">
<form method="post" autocomplete="off">
<input type="hidden" name="action" value="install_wordpress">

<label>Site URL<input type="url" name="site_url" value="<?= h($detectedSiteUrl) ?>" required></label>
<label>Site title<input type="text" name="site_title" maxlength="120" required></label>

<div class="split">
<label>WordPress admin username<input type="text" name="wp_admin_user" value="admin" maxlength="60" required></label>
<label>WordPress admin email<input type="email" name="wp_admin_email" value="admin@local.local" required></label>
</div>

<div class="split">
<label>WordPress admin password<input type="password" name="wp_admin_password" minlength="12" autocomplete="new-password" required></label>
<label>Confirm WordPress admin password<input type="password" name="wp_admin_password_confirm" minlength="12" autocomplete="new-password" required></label>
</div>

<h3>Installer source</h3>
<label class="db-option">
<input type="checkbox" name="local_file_only" value="1">
<span>
<strong>Install WordPress core with local file only</strong>
<span>Skip WordPress.org for the WordPress core archive. Themes and plugins use their own per-item source choices below. LiteSpeed Cache is skipped because no local fallback is bundled.</span>
</span>
</label>

<h3>Optional themes</h3>
<p>Choose each theme to install, whether to use its bundled ZIP immediately, and whether to activate it. If <strong>Install locally</strong> is not checked, Everlomp tries the WordPress.org slug first and uses the local ZIP only as a fallback. WordPress can have only one active theme.</p>
<?php if ($wpThemes !== []): ?>
<div class="db-choice">
<?php foreach ($wpThemes as $theme): ?>
<?php $themeSlug = (string) $theme['slug']; ?>
<div class="db-option addon-option">
<label class="addon-main">
<input type="checkbox" name="wp_themes[]" value="<?= h($themeSlug) ?>" <?= in_array($themeSlug, $wpSelectedThemeSlugs, true) ? 'checked' : '' ?>>
<span>
<strong><?= h((string) $theme['name']) ?></strong>
<span><?= h((string) ($theme['description'] ?? '')) ?></span>
</span>
</label>
<div class="addon-actions">
<?php if (($theme['local_available'] ?? false) === true): ?>
<label class="inline-check"><input type="checkbox" name="wp_theme_install_local[]" value="<?= h($themeSlug) ?>" <?= in_array($themeSlug, $wpInstallLocalThemeSlugs, true) ? 'checked' : '' ?>> Install locally</label>
<?php endif; ?>
<label class="inline-check"><input type="checkbox" name="wp_theme_activate[]" value="<?= h($themeSlug) ?>" <?= in_array($themeSlug, $wpActivateThemeSlugs, true) ? 'checked' : '' ?>> Activate</label>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="warning">No valid WordPress theme add-ons were found in <code>/home/everlomp/wpaddons/themes.json</code>.</div>
<?php endif; ?>

<h3>Optional plugins</h3>
<p>Choose each plugin to install, whether to use its bundled ZIP immediately, and whether to activate it. The <strong>Install locally</strong> checkbox is shown only when that plugin's <code>file</code> is non-empty and the ZIP actually exists. Otherwise the plugin is installed by its WP-CLI slug only.</p>
<?php if ($wpPlugins !== []): ?>
<div class="db-choice">
<?php foreach ($wpPlugins as $plugin): ?>
<?php $pluginSlug = (string) $plugin['slug']; ?>
<div class="db-option addon-option">
<label class="addon-main">
<input type="checkbox" name="wp_plugins[]" value="<?= h($pluginSlug) ?>" <?= in_array($pluginSlug, $wpSelectedPluginSlugs, true) ? 'checked' : '' ?>>
<span>
<strong><?= h((string) $plugin['name']) ?></strong>
<span><?= h((string) ($plugin['description'] ?? '')) ?></span>
</span>
</label>
<div class="addon-actions">
<?php if (($plugin['local_available'] ?? false) === true): ?>
<label class="inline-check"><input type="checkbox" name="wp_plugin_install_local[]" value="<?= h($pluginSlug) ?>" <?= in_array($pluginSlug, $wpInstallLocalPluginSlugs, true) ? 'checked' : '' ?>> Install locally</label>
<?php endif; ?>
<label class="inline-check"><input type="checkbox" name="wp_plugin_activate[]" value="<?= h($pluginSlug) ?>" <?= in_array($pluginSlug, $wpActivatePluginSlugs, true) ? 'checked' : '' ?>> Activate</label>
</div>
</div>
<?php endforeach; ?>
</div>
<?php else: ?>
<div class="warning">No valid WordPress plugin add-ons were found in <code>/home/everlomp/wpaddons/plugins.json</code>.</div>
<?php endif; ?>

<div class="warning"><strong>Email delivery:</strong> this Everlomp image does not include a local mail-transfer service. WordPress needs an external SMTP/mail provider for reliable outbound email. If you include <strong>WP Mail SMTP</strong>, configure your provider and credentials in WordPress after installation; installing the plugin alone does not provide an SMTP server.</div>

<h3>Database</h3>
<div class="db-choice">
<label class="db-option">
<input type="radio" name="db_mode" value="auto" checked onchange="toggleDb('wp')">
<span><strong>Create one automatically</strong><span>Everlomp creates a local database, restricted DB user, and random password.</span></span>
</label>

<label class="db-option">
<input type="radio" name="db_mode" value="existing" onchange="toggleDb('wp')">
<span><strong>Use an existing database</strong><span>Use any existing MySQL/MariaDB host, database, username, and password.</span></span>
</label>
</div>

<div id="wp-auto-db">
<label>New database name<input type="text" name="auto_db_name" value="wordpress" maxlength="64" pattern="[A-Za-z0-9_]+"></label>
</div>

<div id="wp-existing-db" class="hidden">
<div class="split">
<label>Database host<input type="text" name="existing_db_host" value="localhost"></label>
<label>Database port <small>(optional)</small><input type="text" name="existing_db_port" placeholder="3306"></label>
</div>
<div class="split">
<label>Database name<input type="text" name="existing_db_name"></label>
<label>Database username<input type="text" name="existing_db_user"></label>
</div>
<label>Database password<input type="password" name="existing_db_password" autocomplete="new-password"></label>
</div>


<div class="terms-agreements">
<label class="terms-check">
<input type="checkbox" name="accept_wordpress_terms" value="1" required>
<span>I have read and agreed to the <a target="_blank" rel="noopener noreferrer" href="https://wordpress.com/tos/">terms and conditions of WordPress</a>.</span>
</label>
<label class="terms-check">
<input type="checkbox" name="accept_everlomp_terms" value="1" required>
<span>I have read and agreed to the <a target="_blank" rel="noopener noreferrer" href="#everlomp-installation-terms">terms and conditions for this app, visible on this installation page</a>.</span>
</label>
</div>

<div class="actions">
<button type="submit">Install WordPress</button>
<a target="_blank" rel="noopener noreferrer" class="button secondary" href="<?= h($panelUrl) ?>">Cancel</a>
</div>
</form>
</section>

<section class="card full terms-card" id="everlomp-installation-terms">
<h3>Terms and conditions for this installation</h3>
<p>These terms are loaded from <code>/home/everlomp/terms.md</code>.</p>
<div class="terms-scroll"><?php if ($everlompTerms !== ''): ?><?= h($everlompTerms) ?><?php else: ?>No terms text was found. Expected file: /home/everlomp/terms.md<?php endif; ?></div>
</section>

<?php elseif ($showDrupalInstaller): ?>

<a target="_blank" rel="noopener noreferrer" class="back" href="<?= h($panelUrl) ?>">← Back to Everlomp</a>
<section class="hero"><h2>Install Drupal.</h2><p>Install Drupal 11.4.5 with a fresh restricted local database, or use an existing MySQL/MariaDB database.</p></section>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<section class="card full">
<form method="post" autocomplete="off">
<input type="hidden" name="action" value="install_drupal">

<label>Site URL<input type="url" name="site_url" value="<?= h($detectedSiteUrl) ?>" required></label>
<label>Site name<input type="text" name="drupal_site_name" maxlength="120" placeholder="My Drupal Site" required></label>

<div class="split">
<label>Drupal admin username<input type="text" name="drupal_admin_user" value="admin" maxlength="60" required></label>
<label>Drupal admin email<input type="email" name="drupal_admin_email" value="admin@local.local" required></label>
</div>

<div class="split">
<label>Drupal admin password<input type="password" name="drupal_admin_password" minlength="12" autocomplete="new-password" required></label>
<label>Confirm Drupal admin password<input type="password" name="drupal_admin_password_confirm" minlength="12" autocomplete="new-password" required></label>
</div>

<h3>Installer source</h3>
<div class="db-choice">
<label class="db-option">
<input type="radio" name="drupal_installer_source" value="standard" checked onchange="toggleDrupalSource()">
<span><strong>Standard Drupal installation</strong><span>Keep Everlomp's current installer path: download Drupal 11.4.5 from Drupal.org, verify its SHA-256, and fall back to the bundled archive if needed.</span></span>
</label>
<label class="db-option">
<input type="radio" name="drupal_installer_source" value="local" onchange="toggleDrupalSource()">
<span><strong>Bundled local archive only</strong><span>Skip Drupal.org and install only from <code>/home/everlomp/drupal-11.4.5.tar.gz</code>.</span></span>
</label>
<label class="db-option">
<input type="radio" name="drupal_installer_source" value="git" onchange="toggleDrupalSource()">
<span><strong>Git repository</strong><span>Fetch a Drupal 11 project from an HTTPS Git repository, run Composer when <code>composer.json</code> is present, then continue through this same Drupal installation process.</span></span>
</label>
</div>

<div id="drupal-git-source-fields" class="hidden">
<div class="split">
<label>Repository URL
<input type="url" id="drupal-git-repository" name="drupal_git_repository" maxlength="2048" placeholder="https://github.com/example/site.git">
</label>
<label>Branch / tag / commit
<input type="text" name="drupal_git_ref" value="main" maxlength="255" placeholder="main">
</label>
</div>

<label>Drupal document root
<input type="text" name="drupal_git_document_root" value="auto" maxlength="255" placeholder="auto">
<small>Leave this as <code>auto</code> to detect <code>web</code> first and then the repository root. You can still enter a custom relative path.</small>
</label>

<h4>Repository access</h4>
<div class="db-choice">
<label class="db-option">
<input type="radio" name="drupal_git_auth" value="public" checked onchange="toggleDrupalGitAuth()">
<span><strong>Public repository</strong><span>No Git credentials are stored.</span></span>
</label>
<label class="db-option">
<input type="radio" name="drupal_git_auth" value="token" onchange="toggleDrupalGitAuth()">
<span><strong>Private repository · access token</strong><span>The token uses Everlomp's existing secret storage mode: encrypted when the Everlomp key is enabled, plaintext secret storage when encryption is disabled.</span></span>
</label>
</div>

<div id="drupal-git-token-fields" class="hidden">
<div class="split">
<label>Git username <small>(optional)</small>
<input type="text" name="drupal_git_username" maxlength="255" placeholder="x-access-token">
</label>
<label>Access token
<input type="password" id="drupal-git-token" name="drupal_git_token" maxlength="4096" autocomplete="new-password">
</label>
</div>
<?php if ($keyMode === 'enabled'): ?>
<div class="notice"><strong>Credential storage:</strong> the repository token will be encrypted at rest with the configured Everlomp secret key.</div>
<?php else: ?>
<div class="warning"><strong>Credential storage:</strong> Everlomp encryption is disabled, so the repository token will use the existing unencrypted secret-storage mode.</div>
<?php endif; ?>
</div>
</div>

<div class="warning"><strong>Email delivery:</strong> this Everlomp image does not include a local mail-transfer service. Configure an external mail provider/module after installation if the site needs reliable outbound mail.</div>

<h3>Database</h3>
<div class="db-choice">
<label class="db-option">
<input type="radio" name="db_mode" value="auto" checked onchange="toggleDb('drupal')">
<span><strong>Create one automatically</strong><span>Everlomp creates a local database, restricted DB user, and random password.</span></span>
</label>
<label class="db-option">
<input type="radio" name="db_mode" value="existing" onchange="toggleDb('drupal')">
<span><strong>Use an existing database</strong><span>Use any existing MySQL/MariaDB host, database, username, and password.</span></span>
</label>
</div>

<div id="drupal-auto-db">
<label>New database name<input type="text" name="auto_db_name" value="drupal" maxlength="64" pattern="[A-Za-z0-9_]+"></label>
</div>

<div id="drupal-existing-db" class="hidden">
<div class="split">
<label>Database host<input type="text" name="existing_db_host" value="localhost"></label>
<label>Database port <small>(optional)</small><input type="text" name="existing_db_port" placeholder="3306"></label>
</div>
<div class="split">
<label>Database name<input type="text" name="existing_db_name"></label>
<label>Database username<input type="text" name="existing_db_user"></label>
</div>
<label>Database password<input type="password" name="existing_db_password" autocomplete="new-password"></label>
</div>

<div class="terms-agreements">
<label class="terms-check">
<input type="checkbox" name="accept_drupal_terms" value="1" required>
<span>I have read and agree to the <a target="_blank" rel="noopener noreferrer" href="https://www.drupal.org/about/licensing">Drupal licensing terms (GPL-2.0-or-later)</a>.</span>
</label>
<label class="terms-check">
<input type="checkbox" name="accept_everlomp_terms" value="1" required>
<span>I have read and agreed to the <a target="_blank" rel="noopener noreferrer" href="#everlomp-installation-terms">terms and conditions for this app, visible on this installation page</a>.</span>
</label>
</div>

<div class="actions">
<button type="submit">Install Drupal</button>
<a target="_blank" rel="noopener noreferrer" class="button secondary" href="<?= h($panelUrl) ?>">Cancel</a>
</div>
</form>
</section>

<section class="card full terms-card" id="everlomp-installation-terms">
<h3>Terms and conditions for this installation</h3>
<p>These terms are loaded from <code>/home/everlomp/terms.md</code>.</p>
<div class="terms-scroll"><?php if ($everlompTerms !== ''): ?><?= h($everlompTerms) ?><?php else: ?>No terms text was found. Expected file: /home/everlomp/terms.md<?php endif; ?></div>
</section>

<?php elseif ($showPhpbbInstaller): ?>

<a target="_blank" rel="noopener noreferrer" class="back" href="<?= h($panelUrl) ?>">← Back to Everlomp</a>
<section class="hero"><h2>Install phpBB.</h2><p>Create a fresh local database automatically, or point the forum at any existing MySQL/MariaDB database you provide.</p></section>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>

<section class="card full">
<form method="post" autocomplete="off">
<input type="hidden" name="action" value="install_phpbb">

<label>Forum URL<input type="url" name="site_url" value="<?= h($detectedSiteUrl) ?>" required></label>
<label>Board name<input type="text" name="phpbb_board_name" maxlength="120" placeholder="My Community" required></label>
<label>Board description<textarea name="phpbb_board_description" maxlength="255" placeholder="A place to talk about awesome things."></textarea></label>

<div class="split">
<label>phpBB admin username<input type="text" name="phpbb_admin_user" value="admin" maxlength="60" required></label>
<label>phpBB admin email<input type="email" name="phpbb_admin_email" required></label>
</div>

<div class="split">
<label>phpBB admin password<input type="password" name="phpbb_admin_password" minlength="12" maxlength="30" autocomplete="new-password" required></label>
<label>Confirm phpBB admin password<input type="password" name="phpbb_admin_password_confirm" minlength="12" maxlength="30" autocomplete="new-password" required></label>
</div>

<h3>Installer source</h3>
<label class="db-option">
<input type="checkbox" name="local_file_only" value="1">
<span>
<strong>Install with local file only</strong>
<span>Skip version lookup and phpBB downloads completely and install only from <code>/home/everlomp/phpBB-3.3.17.zip</code>.</span>
</span>
</label>

<h3>Email / SMTP <small>(optional)</small></h3>
<div class="warning"><strong>Email delivery:</strong> this Everlomp image does not include a local mail-transfer service. To send registration, notification, or password-reset email, phpBB should use an external SMTP server. You can configure it now or later in phpBB administration.</div>
<label class="db-option">
<input type="checkbox" id="phpbb-smtp-enabled" name="phpbb_smtp_enabled" value="1" onchange="togglePhpbbSmtp()" <?= ((string) ($_POST['phpbb_smtp_enabled'] ?? '') === '1') ? 'checked' : '' ?>>
<span><strong>Configure SMTP during installation</strong><span>Enables phpBB email and stores these SMTP settings in the phpBB configuration.</span></span>
</label>

<div id="phpbb-smtp-fields" class="<?= ((string) ($_POST['phpbb_smtp_enabled'] ?? '') === '1') ? '' : 'hidden' ?>">
<div class="split">
<label>SMTP host<input type="text" name="phpbb_smtp_host" maxlength="255" placeholder="smtp.example.com" value="<?= h((string) ($_POST['phpbb_smtp_host'] ?? '')) ?>"></label>
<label>SMTP port<input type="text" name="phpbb_smtp_port" inputmode="numeric" placeholder="587" value="<?= h((string) ($_POST['phpbb_smtp_port'] ?? '587')) ?>"></label>
</div>
<div class="split">
<label>Authentication method
<select name="phpbb_smtp_auth">
<?php $postedSmtpAuth = (string) ($_POST['phpbb_smtp_auth'] ?? 'LOGIN'); ?>
<option value="" <?= $postedSmtpAuth === '' ? 'selected' : '' ?>>Automatic / none</option>
<?php foreach (['LOGIN', 'PLAIN', 'CRAM-MD5', 'DIGEST-MD5', 'POP-BEFORE-SMTP'] as $smtpMethod): ?>
<option value="<?= h($smtpMethod) ?>" <?= $postedSmtpAuth === $smtpMethod ? 'selected' : '' ?>><?= h($smtpMethod) ?></option>
<?php endforeach; ?>
</select>
</label>
<label>SMTP username <small>(optional for unauthenticated relays)</small><input type="text" name="phpbb_smtp_user" autocomplete="off" value="<?= h((string) ($_POST['phpbb_smtp_user'] ?? '')) ?>"></label>
</div>
<label>SMTP password <small>(optional for unauthenticated relays)</small><input type="password" name="phpbb_smtp_password" autocomplete="new-password"></label>
<div class="warning">phpBB will use STARTTLS automatically when the server advertises it. For implicit SMTPS, phpBB also accepts a host prefixed with <code>ssl://</code> or <code>tls://</code>.</div>
</div>

<h3>Database</h3>
<div class="db-choice">
<label class="db-option">
<input type="radio" name="db_mode" value="auto" checked onchange="toggleDb('phpbb')">
<span><strong>Create one automatically</strong><span>Everlomp creates a local database, restricted DB user, and random password.</span></span>
</label>

<label class="db-option">
<input type="radio" name="db_mode" value="existing" onchange="toggleDb('phpbb')">
<span><strong>Use an existing database</strong><span>Use any existing MySQL/MariaDB host, database, username, and password.</span></span>
</label>
</div>

<div id="phpbb-auto-db">
<label>New database name<input type="text" name="auto_db_name" value="phpbb" maxlength="64" pattern="[A-Za-z0-9_]+"></label>
</div>

<div id="phpbb-existing-db" class="hidden">
<div class="split">
<label>Database host<input type="text" name="existing_db_host" value="localhost"></label>
<label>Database port <small>(optional)</small><input type="text" name="existing_db_port" placeholder="3306"></label>
</div>
<div class="split">
<label>Database name<input type="text" name="existing_db_name"></label>
<label>Database username<input type="text" name="existing_db_user"></label>
</div>
<label>Database password<input type="password" name="existing_db_password" autocomplete="new-password"></label>
</div>

<label>Table prefix<input type="text" name="phpbb_table_prefix" value="phpbb_" maxlength="20" pattern="[A-Za-z0-9_]+"></label>

<div class="warning">If you use an existing database that already contains other tables, keep a unique table prefix. phpBB will create its own tables in that database.</div>


<div class="terms-agreements">
<label class="terms-check">
<input type="checkbox" name="accept_phpbb_terms" value="1" required>
<span>I have read and agreed to the <a target="_blank" rel="noopener noreferrer" href="https://www.phpbb.com/community/ucp.php?mode=terms">terms and conditions of phpBB</a>.</span>
</label>
<label class="terms-check">
<input type="checkbox" name="accept_everlomp_terms" value="1" required>
<span>I have read and agreed to the <a target="_blank" rel="noopener noreferrer" href="#everlomp-installation-terms">terms and conditions for this app, visible on this installation page</a>.</span>
</label>
</div>

<div class="actions">
<button type="submit">Install phpBB</button>
<a target="_blank" rel="noopener noreferrer" class="button secondary" href="<?= h($panelUrl) ?>">Cancel</a>
</div>
</form>
</section>

<section class="card full terms-card" id="everlomp-installation-terms">
<h3>Terms and conditions for this installation</h3>
<p>These terms are loaded from <code>/home/everlomp/terms.md</code>.</p>
<div class="terms-scroll"><?php if ($everlompTerms !== ''): ?><?= h($everlompTerms) ?><?php else: ?>No terms text was found. Expected file: /home/everlomp/terms.md<?php endif; ?></div>
</section>

<?php elseif ($showExternalInstaller): ?>

<?php
$externalRequirementError = externalInstallerRequirementError(
    $externalInstallerView,
    $databaseConfigured,
    $lswsPasswordConfigured,
    $realIpReady,
    $domainOnlyReady
);
$externalTerms = (string) ($externalInstallerView['terms'] ?? '');
?>
<a target="_blank" rel="noopener noreferrer" class="back" href="<?= h($panelUrl) ?>">← Back to Everlomp</a>
<section class="hero"><h2>Install <?= h((string) ($externalInstallerView['name'] ?? 'external application')) ?>.</h2><p><?= h((string) ($externalInstallerView['description'] ?? 'External Everlomp installer package.')) ?></p></section>
<?php if ($error !== ''): ?><div class="notice error"><?= h($error) ?></div><?php endif; ?>
<?php if ($externalRequirementError !== ''): ?><div class="notice error"><?= h($externalRequirementError) ?></div><?php endif; ?>

<section class="card full">
<div class="status warn">External package · Runs as root</div>
<div class="meta"><div>Package ID: <strong><?= h($externalViewId) ?></strong></div><?php if (($externalInstallerView['version'] ?? '') !== ''): ?><div>Version: <strong><?= h((string) $externalInstallerView['version']) ?></strong></div><?php endif; ?><div>Entrypoint: <strong>install.sh</strong></div></div>
<div class="warning"><strong>Trust boundary:</strong> external <code>install.sh</code> files execute as root and can modify the entire container. Only run packages whose source you trust and have reviewed.</div>
<form method="post" autocomplete="off">
<input type="hidden" name="action" value="install_external">
<input type="hidden" name="external_package_id" value="<?= h($externalViewId) ?>">

<?php foreach (($externalInstallerView['fields'] ?? []) as $field): ?>
<?php
if (!is_array($field)) continue;
$fieldName = (string) ($field['name'] ?? '');
$fieldType = (string) ($field['type'] ?? 'text');
$fieldLabel = (string) ($field['label'] ?? $fieldName);
$fieldHelp = (string) ($field['help'] ?? '');
$fieldRequired = (($field['required'] ?? false) === true);
$fieldMaxLength = (int) ($field['max_length'] ?? 5000);
if ($fieldMaxLength < 1 || $fieldMaxLength > 10000) $fieldMaxLength = 5000;
$postedExternalFields = is_array($_POST['external_fields'] ?? null) ? $_POST['external_fields'] : [];
$fieldValue = $postedExternalFields[$fieldName] ?? ($field['default'] ?? '');
?>
<?php if ($fieldType === 'checkbox'): ?>
<label class="db-option"><input type="checkbox" name="external_fields[<?= h($fieldName) ?>]" value="1" <?= ((string) $fieldValue === '1' || $fieldValue === true) ? 'checked' : '' ?> <?= $fieldRequired ? 'required' : '' ?>><span><strong><?= h($fieldLabel) ?></strong><?php if ($fieldHelp !== ''): ?><span><?= h($fieldHelp) ?></span><?php endif; ?></span></label>
<?php elseif ($fieldType === 'textarea'): ?>
<label><?= h($fieldLabel) ?><textarea name="external_fields[<?= h($fieldName) ?>]" maxlength="<?= $fieldMaxLength ?>" <?= $fieldRequired ? 'required' : '' ?>><?= h(is_scalar($fieldValue) ? (string) $fieldValue : '') ?></textarea><?php if ($fieldHelp !== ''): ?><small><?= h($fieldHelp) ?></small><?php endif; ?></label>
<?php elseif ($fieldType === 'select'): ?>
<label><?= h($fieldLabel) ?><select name="external_fields[<?= h($fieldName) ?>]" <?= $fieldRequired ? 'required' : '' ?>><?php foreach (($field['options'] ?? []) as $option): ?><?php $optionValue = is_array($option) ? (string) ($option['value'] ?? '') : (string) $option; $optionLabel = is_array($option) ? (string) ($option['label'] ?? $optionValue) : $optionValue; ?><option value="<?= h($optionValue) ?>" <?= ((string) $fieldValue === $optionValue) ? 'selected' : '' ?>><?= h($optionLabel) ?></option><?php endforeach; ?></select><?php if ($fieldHelp !== ''): ?><small><?= h($fieldHelp) ?></small><?php endif; ?></label>
<?php else: ?>
<?php $htmlInputType = in_array($fieldType, ['email', 'password', 'url', 'number'], true) ? $fieldType : 'text'; ?>
<label><?= h($fieldLabel) ?><input type="<?= h($htmlInputType) ?>" name="external_fields[<?= h($fieldName) ?>]" value="<?= $htmlInputType === 'password' ? '' : h(is_scalar($fieldValue) ? (string) $fieldValue : '') ?>" maxlength="<?= $fieldMaxLength ?>" <?= $htmlInputType === 'password' ? 'autocomplete="new-password"' : '' ?> <?= $fieldRequired ? 'required' : '' ?>><?php if ($fieldHelp !== ''): ?><small><?= h($fieldHelp) ?></small><?php endif; ?></label>
<?php endif; ?>
<?php endforeach; ?>

<div class="terms-agreements">
<?php if ($externalTerms !== ''): ?><label class="terms-check"><input type="checkbox" name="accept_external_package_terms" value="1" required><span>I have read and accept the package terms shown below.</span></label><?php endif; ?>
<label class="terms-check"><input type="checkbox" name="accept_external_root_trust" value="1" required><span>I trust this external installer and understand that its <code>install.sh</code> executes as root.</span></label>
</div>
<div class="actions"><button type="submit" <?= $externalRequirementError !== '' ? 'disabled' : '' ?>>Install <?= h((string) ($externalInstallerView['name'] ?? 'Application')) ?></button><a target="_blank" rel="noopener noreferrer" class="button secondary" href="<?= h($panelUrl) ?>">Cancel</a></div>
</form>
</section>

<?php if ($externalTerms !== ''): ?><section class="card full terms-card"><h3>Package terms</h3><p>These terms came from the uploaded package's <code>terms.md</code>.</p><div class="terms-scroll"><?= h($externalTerms) ?></div></section><?php endif; ?>

<?php else: ?>

<section class="hero">
<h2>Build your Everlomp stack.</h2>
<p>A guided, dependency-aware setup. Every step is saved on the server before the next dependent step unlocks, and you can move back through completed steps without reloading the page.</p>
</section>

<div id="wizard-global-notice" class="notice wizard-notice" role="status" aria-live="polite" style="margin-top:6px;"></div>
<?php if ($message !== ''): ?><div class="notice success wizard-server-notice"><?= h($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="notice error wizard-server-notice"><?= h($error) ?></div><?php endif; ?>

<div id="wizard-root" class="wizard-shell" data-wizard-root>
<script id="wizard-state-json" type="application/json"><?= json_encode([
    'terms' => $termsAccepted,
    'database' => $databaseConfigured,
    'openlitespeed' => $lswsPasswordConfigured,
    'realIp' => $realIpReady,
    'domainOnly' => $domainOnlyReady,
    'filegator' => $filegatorInstalled,
    'primaryApp' => $primaryApp,
    'hotpocket' => $hotpocketEnabled,
    'kopia' => $kopiaConfigured,
    'publicKopiaUrl' => $kopiaDashboardUrl,
    'backupScheduleConfigured' => array_key_exists('sql_schedule', $backupInfo),
    'sshDecisionMade' => $sshDecisionMade,
    'sshEnabled' => $sshEnabled,
    'sshPort' => $sshPublicPort,
    'suggestedStep' => $wizardSuggestedStep,
    'panelUrl' => $panelUrl,
    'urls' => array_filter([
        'Everlomp installer' => $panelUrl,
        'phpMyAdmin' => $phpmyadminInstalled ? $phpMyAdminDashboardUrl : '',
        'OpenLiteSpeed WebAdmin' => $lswsPasswordConfigured ? $openLiteSpeedDashboardUrl : '',
        'FileGator' => $filegatorInstalled ? $fileGatorDashboardUrl : '',
        'WordPress site' => $wordpressInstalled ? ($wordpressBaseUrl !== '' ? $wordpressBaseUrl . '/' : '/') : '',
        'WordPress wp-admin' => $wordpressInstalled ? $wordpressAdminDashboardUrl : '',
        'Drupal site' => $drupalInstalled ? ($drupalBaseUrl !== '' ? $drupalBaseUrl . '/' : '/') : '',
        'Drupal Admin' => $drupalInstalled ? $drupalAdminDashboardUrl : '',
        'phpBB forum' => $phpbbInstalled ? ($phpbbBaseUrl !== '' ? $phpbbBaseUrl . '/' : '/') : '',
        'phpBB Admin' => $phpbbInstalled ? $phpbbAdminDashboardUrl : '',
        'External application' => $externalInstalledId !== '' ? $externalSiteUrl : '',
        'Kopia' => $kopiaConfigured ? $kopiaDashboardUrl : '',
        'Kopia Replication' => $kopiaConfigured ? $kopiaReplicationDashboardUrl : '',
    ], static fn($value) => $value !== ''),
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

<aside class="wizard-sidebar" aria-label="Installation steps">
<div class="wizard-progress"><span id="wizard-progress-bar"></span></div>
<nav class="wizard-nav" id="wizard-nav">
<?php foreach ([1=>'Terms',2=>'Database',3=>'OpenLiteSpeed',4=>'File manager',5=>'Applications',6=>'HotPocket',7=>'Backups',8=>'SSH',9=>'Finish'] as $stepNo=>$stepLabel): ?>
<button type="button" data-step="<?= $stepNo ?>" onclick="showWizardStep(<?= $stepNo ?>)"><span class="wizard-step-no"><?= $stepNo ?></span><span><?= h($stepLabel) ?></span><span class="wizard-check">✓</span></button>
<?php endforeach; ?>
</nav>
</aside>

<main class="wizard-content">
<section id="wizard-step-1" class="wizard-panel" data-step="1">
<div class="wizard-kicker">Step 1 of 9 · Required</div>
<h2 class="wizard-title">Terms before anything else.</h2>
<p class="wizard-lead">Read the installation terms in full. Until they are accepted, the server rejects setup and installation POST actions — this is enforced by PHP, not only by the wizard interface.</p>
<div class="wizard-card">
<?php if ($termsAccepted): ?>
<div class="status good">● Accepted for this installation session</div>
<h3>Everlomp installation terms accepted</h3>
<p>You can revisit this step at any time. Individual third-party applications still require their own license/terms acceptance at the step where you choose to install them.</p>
<?php else: ?>
<div class="terms-scroll" style="max-height:390px"><?php if ($everlompTerms !== ''): ?><?= h($everlompTerms) ?><?php else: ?>No terms text was found. Expected file: /home/everlomp/terms.md<?php endif; ?></div>
<form method="post" class="wizard-ajax-form" data-step="1" data-advance="2">
<input type="hidden" name="action" value="accept_terms">
<div class="terms-agreements" style="margin-top:14px">
<label class="terms-check"><input type="checkbox" name="accept_everlomp_terms" value="1" required><span>I have read and accept the Everlomp installation terms shown above.</span></label>
</div>
<div class="wizard-actions"><button type="submit">Accept &amp; Start Setup</button></div>
</form>
<?php endif; ?>
</div>
<div class="wizard-actions end"><span></span><button type="button" onclick="showWizardStep(2)" <?= $termsAccepted ? '' : 'disabled' ?>>Continue to Database →</button></div>
</section>

<section id="wizard-step-2" class="wizard-panel" data-step="2">
<div class="wizard-kicker">Step 2 of 9 · Foundation</div>
<h2 class="wizard-title">Set up the database administrator.</h2>
<p class="wizard-lead">WordPress and phpBB depend on this step. Everlomp saves the configured account state, while deliberately never writing the plaintext administrator password to its state files.</p>
<div class="wizard-card">
<?php if ($databaseConfigured): ?>
<div class="status good">● Saved and configured</div>
<h3>MariaDB administrator is ready</h3>
<div class="meta"><?php if ($databaseAdminUser !== ''): ?><div>Admin user: <strong><?= h($databaseAdminUser) ?></strong></div><?php endif; ?><div>Configuration state: <strong>saved on server</strong></div><div>Password: <strong>not stored by Everlomp</strong></div></div>
<?php if ($phpmyadminInstalled): ?><div class="access-box"><div class="access-box-title"><strong>MariaDB dashboard · phpMyAdmin</strong><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($phpMyAdminDashboardUrl) ?>">Open</a></div><div class="copy-field"><input id="url-phpmyadmin-database" type="text" readonly value="<?= h($phpMyAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-phpmyadmin-database',this)">Copy URL</button></div></div><?php endif; ?>
<div class="wizard-callout good" style="margin-top:16px"><div class="wizard-callout-icon">✓</div><div><strong>Database prerequisite complete.</strong><br>Primary application installers can now use the MariaDB helper to create their own restricted application databases.</div></div>
<?php else: ?>
<div class="status warn">● Required before application install</div>
<h3>Create the human/admin MariaDB account</h3>
<form method="post" autocomplete="off" class="wizard-ajax-form" data-step="2" data-advance="3">
<input type="hidden" name="action" value="configure_database">
<label>Database admin username<input type="text" name="db_username" value="everlomp_admin" maxlength="32" pattern="[A-Za-z][A-Za-z0-9_]{2,31}" required></label>
<div class="split"><label>Database admin password<input id="mariadb-admin-password" type="password" name="db_password" minlength="12" autocomplete="new-password" required></label><label>Confirm database admin password<input id="mariadb-admin-password-confirm" type="password" name="db_password_confirm" minlength="12" autocomplete="new-password" required></label></div>
<div class="warning"><strong>Save this password externally.</strong> Everlomp applies it to MariaDB but does not store or display the plaintext later.</div>
<div class="wizard-actions"><button type="button" class="secondary" onclick="generateMariaDbPassword()">Generate Strong Password</button><button type="submit">Save Database Configuration</button></div>
</form>
<?php endif; ?>
</div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(1)">← Terms</button><button type="button" onclick="showWizardStep(3)" <?= $databaseConfigured ? '' : 'disabled' ?>>Continue to OpenLiteSpeed →</button></div>
</section>

<section id="wizard-step-3" class="wizard-panel" data-step="3">
<div class="wizard-kicker">Step 3 of 9 · Web server &amp; network identity</div>
<h2 class="wizard-title">Secure OpenLiteSpeed — and verify the real visitor IP.</h2>
<p class="wizard-lead">This step has two mandatory checks: a saved OpenLiteSpeed WebAdmin credential and trustworthy real-client-IP forwarding from your proxy.</p>
<div class="wizard-card">
<div class="wizard-callout danger"><div class="wizard-callout-icon">!</div><div><strong>Real client IP must work.</strong><br>The anti-bruteforce system and other IP-based protections need the actual visitor IP. If OpenLiteSpeed only sees the reverse proxy/container address, those protections cannot reliably identify or block the attacking client.</div></div>
<div class="wizard-callout" style="margin-top:12px"><div class="wizard-callout-icon">↗</div><div class="host-rule"><strong>Domain-only access rule:</strong><br>Run this website through <code>https://your-domain.tld</code>. Never use <code>your-domain.tld:PORT</code>, a raw IP address, or a port-qualified public URL for Everlomp.</div></div>
<div class="wizard-status-row"><span class="wizard-pill <?= $domainOnlyReady ? 'good' : 'warn' ?>">Domain host: <?= $domainOnlyReady ? 'valid' : 'fix required' ?></span><span class="wizard-pill <?= $realIpReady ? 'good' : 'warn' ?>">Real client IP: <?= $realIpReady ? 'active' : 'not ready' ?></span><span class="wizard-pill <?= $lswsPasswordConfigured ? 'good' : 'warn' ?>">WebAdmin: <?= $lswsPasswordConfigured ? 'secured' : 'password required' ?></span></div>
<div class="meta"><div>Current Host header: <strong><?= h($host !== '' ? $host : 'missing') ?></strong></div><div>Direct/request peer: <strong><?= h($requestPeerIp !== '' ? $requestPeerIp : 'unknown') ?></strong></div><div>Forwarded client header: <strong><?= h($forwardedClientIp !== '' ? $forwardedClientIp : 'missing') ?></strong></div><?php if ($realIpTrustedProxy !== ''): ?><div>Trusted proxy peer: <strong><?= h($realIpTrustedProxy) ?></strong></div><?php endif; ?></div>
<?php if (!$domainOnlyReady): ?><div class="warning"><strong>Do not continue on this URL.</strong> Open Everlomp through its final HTTPS domain without an explicit port. Example: <code>https://example.com/install.php</code>, not <code>https://example.com:8443/install.php</code>.</div><?php endif; ?>
<?php if (!$realIpReady): ?>
<div class="wizard-divider"></div>
<h3>Real client IP forwarding</h3>
<?php if ($realIpCanConfigure): ?><p>Everlomp can see both the internal proxy peer and a public forwarded visitor IP. Apply the detected proxy now.</p><form method="post" class="wizard-ajax-form" data-step="3"><input type="hidden" name="action" value="configure_real_ip"><div class="wizard-actions"><button type="submit">Configure Real Client IP</button></div></form><?php else: ?><p>Your proxy is not currently supplying a usable public <code>X-Forwarded-For</code> or <code>X-Real-IP</code> value. Configure NPMplus/reverse proxy forwarding first, then recheck this step.</p><div class="wizard-actions"><button type="button" class="secondary" onclick="refreshWizardStep(3)">Recheck Network State</button></div><?php endif; ?>
<?php endif; ?>
<div class="wizard-divider"></div>
<?php if ($lswsPasswordConfigured): ?>
<div class="status good">● WebAdmin password saved/applied</div><h3>OpenLiteSpeed WebAdmin is secured</h3><p>Username: <strong>admin</strong>. Everlomp remembers that setup is complete but does not store the plaintext WebAdmin password.</p>
<?php else: ?>
<div class="status warn">● WebAdmin password required</div><h3>Generate OpenLiteSpeed WebAdmin credentials</h3><p>The generated password is applied immediately and displayed once so you can save it.</p>
<form method="post" class="wizard-ajax-form" data-step="3"><input type="hidden" name="action" value="generate_lsws_password"><div class="wizard-actions"><button type="submit">Generate &amp; Save WebAdmin Password</button></div></form>
<?php endif; ?>
<?php if ($lswsGeneratedPassword !== ''): ?><div class="warning" style="margin-top:14px"><strong>Save this OpenLiteSpeed password now.</strong><br>Username: <code id="lsws-generated-username"><?= h($lswsGeneratedUsername) ?></code><br>Password: <code id="lsws-generated-password"><?= h($lswsGeneratedPassword) ?></code><div class="wizard-actions"><button type="button" class="secondary" onclick="copyLswsPassword(this)">Copy Password</button></div>It will not be displayed again after this response is gone.</div><?php endif; ?>
<div class="access-box"><div class="access-box-title"><strong>OpenLiteSpeed WebAdmin dashboard</strong><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($openLiteSpeedDashboardUrl) ?>">Open</a></div><div class="copy-field"><input id="url-openlitespeed" type="text" readonly value="<?= h($openLiteSpeedDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-openlitespeed',this)">Copy URL</button></div></div>
</div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(2)">← Database</button><button type="button" onclick="showWizardStep(4)" <?= ($lswsPasswordConfigured && $realIpReady && $domainOnlyReady) ? '' : 'disabled' ?>>I’ve saved it — Continue →</button></div>
</section>

<section id="wizard-step-4" class="wizard-panel" data-step="4">
<div class="wizard-kicker">Step 4 of 9 · Recommended tool</div>
<h2 class="wizard-title">Do you want browser-based file management?</h2>
<p class="wizard-lead">FileGator is optional, but recommended: it gives you a clean browser interface for inspecting and managing the web files under the repository root you choose.</p>
<div class="wizard-choice-grid">
<div class="choice-card recommended"><span class="choice-badge">Recommended</span><h3>Install FileGator</h3><p>Useful when you want to inspect uploaded files, themes, application files, logs you expose under the managed tree, or make quick file changes without SSH.</p></div>
<div class="choice-card"><span class="choice-badge">Optional</span><h3>Skip FileGator</h3><p>Your applications still work normally. You can continue without a browser file manager, but once the installer is removed you should not expect this installer to offer FileGator later.</p></div>
</div>
<div class="wizard-card" style="margin-top:16px">
<?php if ($filegatorInstalled): ?>
<div class="status good">● FileGator installed</div><h3>File manager ready</h3><div class="meta"><div>Root: <strong><?= h((string) ($filegatorInfo['root'] ?? ($filegatorGeneratedRoot !== '' ? $filegatorGeneratedRoot : '/var/www'))) ?></strong></div><?php if (($filegatorInfo['version'] ?? '') !== ''): ?><div>Version: <strong><?= h((string) $filegatorInfo['version']) ?></strong></div><?php endif; ?><div>Admin user: <strong>admin</strong></div></div>
<?php else: ?>
<form method="post" autocomplete="off" class="wizard-ajax-form" data-step="4">
<input type="hidden" name="action" value="install_filegator">
<label>FileGator root<input type="text" name="filegator_root" value="/var/www" maxlength="512" required><small>For security, Everlomp restricts this to <code>/var/www</code> or a subdirectory.</small></label>
<div class="terms-agreements"><label class="terms-check"><input type="checkbox" name="accept_filegator_terms" value="1" required><span>I accept the <a target="_blank" rel="noopener noreferrer" href="https://github.com/filegator/filegator/blob/master/LICENSE">FileGator license terms</a>.</span></label></div>
<div class="wizard-actions"><button type="submit" name="filegator_source" value="remote">Install FileGator</button><button type="submit" name="filegator_source" value="local" class="secondary">Install Local Bundle</button></div>
<p class="meta">Local fallback: <code>/home/everlomp/filegator_local.zip</code></p>
</form>
<?php endif; ?>
<?php if ($filegatorGeneratedPassword !== ''): ?><div class="warning"><strong>Save this FileGator password now.</strong><br>Username: <code id="filegator-generated-username"><?= h($filegatorGeneratedUsername) ?></code><br>Password: <code id="filegator-generated-password"><?= h($filegatorGeneratedPassword) ?></code><br>Root: <code id="filegator-generated-root"><?= h($filegatorGeneratedRoot) ?></code><div class="wizard-actions"><button type="button" class="secondary" onclick="copyFilegatorPassword(this)">Copy Password</button></div></div><?php endif; ?>
<?php if ($filegatorInstalled): ?><div class="access-box"><div class="access-box-title"><strong>FileGator dashboard</strong><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($fileGatorDashboardUrl) ?>">Open</a></div><div class="copy-field"><input id="url-filegator" type="text" readonly value="<?= h($fileGatorDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-filegator',this)">Copy URL</button></div></div><?php endif; ?>
</div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(3)">← OpenLiteSpeed</button><div class="wizard-actions" style="margin-top:0"><button type="button" class="secondary" onclick="skipFileGator()">Skip for now</button><button type="button" onclick="completeFileGatorStep()" <?= $filegatorInstalled ? '' : 'disabled data-filegator-continue' ?>>Continue to Apps →</button></div></div>
</section>

<section id="wizard-step-5" class="wizard-panel" data-step="5">
<div class="wizard-kicker">Step 5 of 9 · Primary application</div>
<h2 class="wizard-title">Choose what this server will run.</h2>
<p class="wizard-lead">Availability is calculated from the server state. WordPress, Drupal, phpBB, and uploaded external packages are primary-app alternatives: you can install one, not multiple. External package requirements are declared by their manifest.</p>
<div id="wizard-apps-content" class="wizard-choice-grid">
<section class="choice-card <?= $wordpressInstalled ? 'recommended' : '' ?>">
<span class="choice-badge"><?= $wordpressInstalled ? 'Installed' : ($primaryApp === '' ? 'Available' : 'Unavailable') ?></span><h3>WordPress</h3><p>CMS, blog, and website platform. The installer can auto-create a restricted local database or use an existing MySQL/MariaDB database. Outbound email requires an external SMTP/mail provider; WP Mail SMTP is available as an optional install add-on.</p>
<div class="wizard-actions"><?php if ($wordpressInstalled): ?><a class="button" target="_blank" rel="noopener noreferrer" href="<?= h($wordpressBaseUrl !== '' ? $wordpressBaseUrl . '/' : '/') ?>">Open Site</a><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($wordpressAdminDashboardUrl) ?>">Admin</a><?php elseif ($primaryApp === ''): ?><button type="button" onclick="openAppInstaller('wordpress')">Configure &amp; Install</button><?php else: ?><button type="button" disabled>Another primary app is installed</button><?php endif; ?></div>
<?php if ($wordpressInstalled): ?><div class="access-box"><div class="access-box-title"><strong>WordPress wp-admin</strong></div><div class="copy-field"><input id="url-wordpress-admin" type="text" readonly value="<?= h($wordpressAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-wordpress-admin',this)">Copy URL</button></div></div><?php endif; ?>
</section>
<section class="choice-card <?= $drupalInstalled ? 'recommended' : '' ?>">
<span class="choice-badge"><?= $drupalInstalled ? 'Installed' : ($primaryApp === '' ? 'Available' : 'Unavailable') ?></span><h3>Drupal</h3><p>Drupal 11 CMS with a pinned, SHA-256-verified core archive. Everlomp can auto-create a restricted MariaDB database, use existing credentials, and fall back to the bundled Drupal tarball when Drupal.org is unavailable.</p>
<div class="wizard-actions"><?php if ($drupalInstalled): ?><a class="button" target="_blank" rel="noopener noreferrer" href="<?= h($drupalBaseUrl !== '' ? $drupalBaseUrl . '/' : '/') ?>">Open Site</a><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($drupalAdminDashboardUrl) ?>">Admin</a><?php elseif ($primaryApp === ''): ?><button type="button" onclick="openAppInstaller('drupal')">Configure &amp; Install</button><?php else: ?><button type="button" disabled>Another primary app is installed</button><?php endif; ?></div>
<?php if ($drupalInstalled): ?><div class="access-box"><div class="access-box-title"><strong>Drupal administration</strong></div><div class="copy-field"><input id="url-drupal-admin" type="text" readonly value="<?= h($drupalAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-drupal-admin',this)">Copy URL</button></div></div><?php endif; ?>
</section>
<section class="choice-card <?= $phpbbInstalled ? 'recommended' : '' ?>">
<span class="choice-badge"><?= $phpbbInstalled ? 'Installed' : ($primaryApp === '' ? 'Available' : 'Unavailable') ?></span><h3>phpBB</h3><p>Community forum software. Like WordPress, it supports either an automatically created restricted database or existing database credentials. Outbound email requires an external SMTP server, which can be configured during installation.</p>
<div class="wizard-actions"><?php if ($phpbbInstalled): ?><a class="button" target="_blank" rel="noopener noreferrer" href="<?= h($phpbbBaseUrl !== '' ? $phpbbBaseUrl . '/' : '/') ?>">Open Forum</a><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($phpbbAdminDashboardUrl) ?>">Admin</a><?php elseif ($primaryApp === ''): ?><button type="button" onclick="openAppInstaller('phpbb')">Configure &amp; Install</button><?php else: ?><button type="button" disabled>Another primary app is installed</button><?php endif; ?></div>
<?php if ($phpbbInstalled): ?><div class="access-box"><div class="access-box-title"><strong>phpBB administration</strong></div><div class="copy-field"><input id="url-phpbb-admin" type="text" readonly value="<?= h($phpbbAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-phpbb-admin',this)">Copy URL</button></div></div><?php endif; ?>
<?php if ($phpbbInstalled && (($phpbbInfo['smtp_configured'] ?? false) !== true)): ?><div class="warning">phpBB SMTP was not configured during installation. Configure an external SMTP server in phpBB administration before relying on registration, notification, or password-reset email.</div><?php endif; ?>
</section>
<?php foreach ($externalInstallers as $externalId => $externalManifest): ?>
<?php
$externalIsInstalled = $externalInstalledId === $externalId;
$externalRequirement = externalInstallerRequirementError($externalManifest, $databaseConfigured, $lswsPasswordConfigured, $realIpReady, $domainOnlyReady);
$externalCanInstall = $primaryApp === '' && $externalRequirement === '';
$externalCardSitePath = (string) ($externalManifest['site_path'] ?? '/');
if ($externalCardSitePath === '' || !str_starts_with($externalCardSitePath, '/')) $externalCardSitePath = '/';
$externalCardSiteUrl = $publicBaseUrl !== '' ? rtrim($publicBaseUrl, '/') . $externalCardSitePath : $externalCardSitePath;
?>
<section class="choice-card <?= $externalIsInstalled ? 'recommended' : '' ?>">
<span class="choice-badge"><?= $externalIsInstalled ? 'Installed' : ($primaryApp === '' ? ($externalRequirement === '' ? 'External · Available' : 'External · Locked') : 'Unavailable') ?></span>
<h3><?= h((string) ($externalManifest['name'] ?? $externalId)) ?></h3>
<p><?= h((string) ($externalManifest['description'] ?? 'Uploaded Everlomp external installer package.')) ?></p>
<div class="meta"><div>Package: <strong><?= h($externalId) ?></strong></div><?php if (($externalManifest['version'] ?? '') !== ''): ?><div>Version: <strong><?= h((string) $externalManifest['version']) ?></strong></div><?php endif; ?></div>
<?php if ($externalRequirement !== '' && $primaryApp === ''): ?><div class="warning"><?= h($externalRequirement) ?></div><?php endif; ?>
<div class="wizard-actions">
<?php if ($externalIsInstalled): ?>
<a class="button" target="_blank" rel="noopener noreferrer" href="<?= h($externalCardSiteUrl) ?>">Open App</a>
<?php elseif ($primaryApp === '' && $externalCanInstall): ?>
<button type="button" onclick="openAppInstaller('external:<?= h($externalId) ?>')">Configure &amp; Install</button>
<?php elseif ($primaryApp !== ''): ?>
<button type="button" disabled>Another primary app is installed</button>
<?php else: ?>
<button type="button" disabled>Prerequisites required</button>
<?php endif; ?>
<?php if (!$externalIsInstalled): ?><form method="post" class="wizard-ajax-form" data-step="5" style="display:inline-block;margin:0" onsubmit="return confirm('Remove the uploaded installer package <?= h($externalId) ?>?');"><input type="hidden" name="action" value="remove_external_installer"><input type="hidden" name="external_package_id" value="<?= h($externalId) ?>"><button type="submit" class="secondary">Remove Package</button></form><?php endif; ?>
</div>
</section>
<?php endforeach; ?>
<section class="choice-card">
<span class="choice-badge">External installers</span><h3>Upload installer ZIP</h3>
<p>Add a package containing <code>manifest.json</code> and <code>install.sh</code>. Valid packages are stored under <code>/home/everlomp/external-installs/&lt;id&gt;/</code> and automatically appear here.</p>
<div class="warning"><strong>Security:</strong> uploaded installers execute as root only after you explicitly confirm trust on their install screen. Review third-party scripts before running them.</div>
<form method="post" enctype="multipart/form-data" class="wizard-ajax-form" data-step="5">
<input type="hidden" name="action" value="import_external_installer">
<label>Installer ZIP<input type="file" name="external_installer_zip" accept=".zip,application/zip" required><small>Maximum compressed size: 10 MiB. Expanded packages are limited to 50 MiB and 200 ZIP entries.</small></label>
<div class="wizard-actions"><button type="submit">Upload Package</button><a class="button secondary" href="<?= h($panelUrl) ?>?download=external-installer-example">Download Example ZIP</a></div>
</form>
</section>
<section class="choice-card"><span class="choice-badge"><?= $phpmyadminInstalled ? 'Built in' : 'Missing from image' ?></span><h3>phpMyAdmin</h3><p>Database administration tool. It is part of the image rather than a selectable primary app and uses your MariaDB account for normal cookie login.</p><?php if ($phpmyadminInstalled): ?><div class="wizard-actions"><a class="button secondary" target="_blank" rel="noopener noreferrer" href="<?= h($phpMyAdminDashboardUrl) ?>">Open phpMyAdmin</a></div><div class="access-box"><div class="access-box-title"><strong>phpMyAdmin URL</strong></div><div class="copy-field"><input id="url-phpmyadmin-apps" type="text" readonly value="<?= h($phpMyAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('url-phpmyadmin-apps',this)">Copy URL</button></div></div><?php endif; ?></section>
</div>
<div id="app-installer-drawer" class="app-installer-drawer"></div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(4)">← File Manager</button><button type="button" onclick="showWizardStep(6)" <?= $primaryApp !== '' ? '' : 'disabled' ?>>Continue to HotPocket →</button></div>
</section>

<section id="wizard-step-6" class="wizard-panel" data-step="6">
<div class="wizard-kicker">Step 6 of 9 · Optional smart-contract runtime</div>
<h2 class="wizard-title">Do you need HotPocket?</h2>
<p class="wizard-lead">Enable HotPocket only if this application will run smart contracts. If you enable it, Everlomp starts HotPocket under Supervisor and the bootstrap contract runs on it so you have a target ready for contract deployment.</p>
<div class="wizard-card">
<?php if ($hotpocketEnabled): ?>
<div class="status good">● HotPocket enabled</div><h3>Smart-contract runtime is active</h3><p>Supervisor is configured to autostart HotPocket and keep it running. The bootstrap contract can run here so contracts can be deployed to this runtime.</p>
<?php else: ?>
<div class="wizard-callout"><div class="wizard-callout-icon">i</div><div><strong>This is the one installer opportunity to enable HotPocket.</strong><br>If you do not need smart contracts, leave it off. If you skip it and later remove the installer, the Everlomp installer will no longer be available to enable it for you.</div></div>
<div class="wizard-choice-grid" style="margin-top:16px"><div class="choice-card recommended"><span class="choice-badge">For smart contracts</span><h3>Enable HotPocket</h3><p>Starts HotPocket now, enables autostart, and prepares the bootstrap-contract runtime for deployments.</p><form method="post" class="wizard-ajax-form"><input type="hidden" name="action" value="enable_hotpocket"><div class="terms-agreements"><label class="terms-check"><input type="checkbox" name="accept_hotpocket_terms" value="1" required><span>I accept the <a target="_blank" rel="noopener noreferrer" href="https://github.com/EvernodeXRPL/hpcore/blob/main/evernode-license.pdf">HotPocket license terms</a>.</span></label></div><div class="wizard-actions"><button type="submit">Enable HotPocket</button></div></form></div><div class="choice-card"><span class="choice-badge">Normal web apps</span><h3>Keep it off</h3><p>Recommended if you only need WordPress, Drupal, phpBB, PHP/web files, or other non-smart-contract workloads.</p><div class="wizard-actions"><button type="button" class="secondary" onclick="skipHotPocket()">Continue without HotPocket</button></div></div></div>
<?php endif; ?>
</div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(5)">← Applications</button><button type="button" onclick="completeHotPocketStep()" <?= $hotpocketEnabled ? '' : 'disabled data-hotpocket-continue' ?>>Continue to Backups →</button></div>
</section>

<section id="wizard-step-7" class="wizard-panel" data-step="7">
<div class="wizard-kicker">Step 7 of 9 · Backup installation</div>
<h2 class="wizard-title">Schedule backups first. Then install Kopia.</h2>
<p class="wizard-lead">This step deliberately starts with MariaDB logical-backup scheduling and retention. After the schedule is saved, configure Kopia for encrypted snapshots, policies, repository storage, and optional replication.</p>
<div class="wizard-callout good"><div class="wizard-callout-icon">1</div><div><strong>First: backup schedule.</strong><br>Choose when SQL dumps happen and how many are kept. These dumps become a source Kopia can snapshot.</div></div>
<div id="backup-fragment" class="backup-fragment"><div class="wizard-card" style="margin-top:16px"><div class="wizard-lock"><span class="spinner-inline"></span>Loading backup controls…</div></div></div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(6)">← HotPocket</button><button type="button" onclick="completeBackupStep()">Backup setup reviewed — Continue →</button></div>
</section>

<section id="wizard-step-8" class="wizard-panel" data-step="8">
<div class="wizard-kicker">Step 8 of 9 · Remote access</div>
<h2 class="wizard-title">Do you want to enable SSH?</h2>
<p class="wizard-lead">SSH is disabled by default. If you enable it, Everlomp generates a fresh password for the non-root <strong>everlomp</strong> account, configures OpenSSH to listen on <code>EXTERNAL_GPTCP2_PORT</code>, reloads Supervisor, and starts OpenSSH.</p>
<div class="wizard-card">
<div class="wizard-callout danger"><div class="wizard-callout-icon">!</div><div><strong>SSH is administrative access.</strong><br>The existing <code>everlomp</code> account has passwordless <code>sudo</code> in this image, so anyone with this SSH password can obtain root privileges. Enable it only if you need remote shell access. Everlomp generates a fresh SSH password when you enable or re-apply SSH.</div></div>
<div class="wizard-status-row">
<span class="wizard-pill <?= $sshEnabled ? 'good' : 'warn' ?>">SSH: <?= $sshEnabled ? 'Enabled' : 'Disabled' ?></span>
<span class="wizard-pill <?= $sshPublicPort !== '' ? 'good' : 'warn' ?>">GPTCP2 public port: <?= $sshPublicPort !== '' ? h($sshPublicPort) : 'Unavailable' ?></span>
<span class="wizard-pill <?= $sshHostDomain !== '' ? 'good' : 'warn' ?>">SSH host: <?= $sshHostDomain !== '' ? h($sshHostDomain) : 'Unavailable' ?></span>
</div>
<?php if ($sshGeneratedPassword !== ''): ?>
<div class="warning" style="margin-top:14px"><strong>SSH is active.</strong><br>Username: <code id="ssh-generated-username"><?= h($sshGeneratedUsername !== '' ? $sshGeneratedUsername : 'everlomp') ?></code><br>Host: <code id="ssh-generated-host"><?= h($sshHostDomain) ?></code><br>Port: <code id="ssh-generated-port"><?= h($sshPublicPort) ?></code><br>Generated password: <code id="ssh-generated-password"><?= h($sshGeneratedPassword) ?></code><?php if ($sshGeneratedPassword !== '' && $sshPublicPort !== '' && $sshHostDomain !== ''): ?><br>Command: <code id="ssh-generated-command">ssh -p <?= h($sshPublicPort) ?> everlomp@<?= h($sshHostDomain) ?></code><?php endif; ?><div class="wizard-actions"><button type="button" class="secondary" onclick="copySshPassword(this)">Copy Password</button></div><strong>Save this password now.</strong> Re-applying SSH generates a new password. <code>HOST_DOMAIN_ADDRESS</code> is used only as the SSH destination host.</div>
<?php elseif ($sshEnabled): ?>
<div class="wizard-callout good"><div class="wizard-callout-icon">✓</div><div><strong>SSH is enabled.</strong><br>Supervisor will autostart <code>sshd</code>. The SSH password was generated when SSH was enabled and is not recoverable from this page after a reload; use Re-apply SSH Settings to generate a new one if needed.</div></div>
<?php else: ?>
<div class="wizard-callout"><div class="wizard-callout-icon">i</div><div><strong>SSH is currently off.</strong><br>The <code>everlomp</code> account is locked for password login and Supervisor will not autostart <code>sshd</code>.</div></div>
<?php endif; ?>
<div class="wizard-choice-grid" style="margin-top:16px">
<div class="choice-card recommended"><span class="choice-badge">Optional</span><h3><?= $sshEnabled ? 'Re-apply SSH Settings' : 'Enable SSH' ?></h3><p><?= $sshEnabled ? 'Generate a new everlomp SSH password, configure sshd for the current GPTCP2 external port, reload Supervisor, and verify it is listening.' : 'Generate a fresh password, unlock the everlomp account, configure sshd to listen on EXTERNAL_GPTCP2_PORT, enable autostart, start sshd, and verify that port is listening.' ?></p><?php if (!$sshMappingReady): ?><div class="notice error" style="margin-top:12px"><?php if ($sshHostDomain === ''): ?>HOST_DOMAIN_ADDRESS is missing. <?php endif; ?><?php if ($sshPublicPort === ''): ?>EXTERNAL_GPTCP2_PORT is missing or invalid. <?php endif; ?></div><?php endif; ?><form method="post" class="wizard-ajax-form" data-step="8" style="margin-top:14px"><input type="hidden" name="action" value="enable_ssh"><button type="submit" <?= !$sshMappingReady ? 'disabled' : '' ?>><?= $sshEnabled ? 'Re-apply & Verify SSH' : 'Enable & Verify SSH' ?></button></form></div>
<div class="choice-card"><span class="choice-badge">Closed by default</span><h3>Keep SSH disabled</h3><p>Stop sshd if it is running, keep Supervisor autostart off, and lock the everlomp password login.</p><form method="post" class="wizard-ajax-form" data-step="8" data-advance="9" style="margin-top:14px"><input type="hidden" name="action" value="disable_ssh"><button type="submit" class="secondary">Keep SSH Disabled</button></form></div>
</div>
</div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(7)">← Backups</button><?php if ($sshDecisionMade): ?><button type="button" onclick="showWizardStep(9)"><?= $sshEnabled && $sshGeneratedPassword !== '' ? 'SSH verified — Continue to Finish →' : 'Continue to Finish →' ?></button><?php else: ?><button type="button" disabled>Choose SSH setting to continue</button><?php endif; ?></div>
</section>

<section id="wizard-step-9" class="wizard-panel" data-step="9">
<div class="wizard-kicker">Step 9 of 9 · Security cleanup</div>
<h2 class="wizard-title">Review everything — then remove the installer.</h2>
<p class="wizard-lead">You can use the navigator on the left to revisit any previous step instantly without reloading the page. Do that now if you want to check a credential, optional component, SSH choice, or backup setting.</p>
<div class="wizard-card">
<h3>Installation review</h3>
<div class="review-grid"><div class="review-item"><small>Terms</small><strong><?= $termsAccepted ? 'Accepted' : 'Incomplete' ?></strong></div><div class="review-item"><small>MariaDB</small><strong><?= $databaseConfigured ? 'Configured' : 'Incomplete' ?></strong></div><div class="review-item"><small>OpenLiteSpeed / Real IP</small><strong><?= ($lswsPasswordConfigured && $realIpReady && $domainOnlyReady) ? 'Ready' : 'Needs attention' ?></strong></div><div class="review-item"><small>FileGator</small><strong><?= $filegatorInstalled ? 'Installed' : 'Skipped / not installed' ?></strong></div><div class="review-item"><small>Primary app</small><strong><?= $primaryApp !== '' ? h($primaryApp) : 'None' ?></strong></div><div class="review-item"><small>HotPocket</small><strong><?= $hotpocketEnabled ? 'Enabled' : 'Off' ?></strong></div><div class="review-item"><small>Kopia</small><strong><?= $kopiaConfigured ? 'Configured' : 'Review backup step' ?></strong></div><div class="review-item"><small>SSH / GPTCP2</small><strong><?= $sshDecisionMade ? ($sshEnabled ? 'Enabled · port ' . h($sshPublicPort) : 'Disabled') : 'Not decided' ?></strong></div><div class="review-item"><small>Installer</small><strong>Still present</strong></div></div>
<div class="wizard-divider"></div><div class="access-box"><div class="access-box-title"><strong>Quick access · dashboard URLs</strong><button type="button" class="secondary" onclick="copyAllDashboardUrls(this)">Copy all URLs</button></div><div style="display:grid;gap:10px">
<?php if ($phpmyadminInstalled): ?><div class="copy-field"><input data-dashboard-label="phpMyAdmin" data-dashboard-url id="finish-url-phpmyadmin" type="text" readonly value="<?= h($phpMyAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-phpmyadmin',this)">Copy</button></div><?php endif; ?>
<?php if ($lswsPasswordConfigured): ?><div class="copy-field"><input data-dashboard-label="OpenLiteSpeed WebAdmin" data-dashboard-url id="finish-url-openlitespeed" type="text" readonly value="<?= h($openLiteSpeedDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-openlitespeed',this)">Copy</button></div><?php endif; ?>
<?php if ($wordpressInstalled): ?><div class="copy-field"><input data-dashboard-label="WordPress wp-admin" data-dashboard-url id="finish-url-wordpress" type="text" readonly value="<?= h($wordpressAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-wordpress',this)">Copy</button></div><?php endif; ?>
<?php if ($drupalInstalled): ?><div class="copy-field"><input data-dashboard-label="Drupal Admin" data-dashboard-url id="finish-url-drupal" type="text" readonly value="<?= h($drupalAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-drupal',this)">Copy</button></div><?php endif; ?>
<?php if ($phpbbInstalled): ?><div class="copy-field"><input data-dashboard-label="phpBB Admin" data-dashboard-url id="finish-url-phpbb" type="text" readonly value="<?= h($phpbbAdminDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-phpbb',this)">Copy</button></div><?php endif; ?>
<?php if ($externalInstalledId !== ''): ?><div class="copy-field"><input data-dashboard-label="External application" data-dashboard-url id="finish-url-external" type="text" readonly value="<?= h($externalSiteUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-external',this)">Copy</button></div><?php endif; ?>
<?php if ($filegatorInstalled): ?><div class="copy-field"><input data-dashboard-label="FileGator" data-dashboard-url id="finish-url-filegator" type="text" readonly value="<?= h($fileGatorDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-filegator',this)">Copy</button></div><?php endif; ?>
<?php if ($kopiaConfigured): ?><div class="copy-field"><input data-dashboard-label="Kopia" data-dashboard-url id="finish-url-kopia" type="text" readonly value="<?= h($kopiaDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-kopia',this)">Copy</button></div><div class="copy-field"><input data-dashboard-label="Kopia Replication" data-dashboard-url id="finish-url-kopia-replication" type="text" readonly value="<?= h($kopiaReplicationDashboardUrl) ?>"><button type="button" class="secondary" onclick="copyFieldValue('finish-url-kopia-replication',this)">Copy</button></div><?php endif; ?>
</div></div>
</div>
<div class="finish-warning" style="margin-top:16px"><h3>⚠ Remove the Everlomp installer when you are done.</h3><p>Leaving <strong>/install.php</strong> exposed means leaving an administrative installation surface on the server. Removing it deletes the installer interface, the original redirecting bootstrap index if it is still unchanged, helper programs, installer metadata, bundled WordPress/Drupal/phpBB installation files, uploaded external-installer packages, and the installer’s passwordless web sudo permissions.</p><p><strong>It does not remove</strong> your installed primary application (WordPress, Drupal, phpBB, or external), FileGator, phpMyAdmin, OpenLiteSpeed, MariaDB, HotPocket, Kopia, SSH if you enabled it, or application/database data.</p><?php if ($primaryApp !== '' && $sshDecisionMade): ?><form method="post" onsubmit="return confirm('Permanently remove the Everlomp installer, helper programs, and bundled installation files? This cannot be undone from the installer.');"><input type="hidden" name="action" value="delete_everlomp_installfile"><div class="wizard-actions"><button type="submit">Permanently Remove Everlomp Installer</button></div></form><?php elseif ($primaryApp === ''): ?><div class="wizard-lock">Install a primary application before the installer can be removed.</div><?php else: ?><div class="wizard-lock">Choose whether SSH should be enabled before the installer can be removed.</div><?php endif; ?></div>
<div class="wizard-actions end"><button type="button" class="secondary" onclick="showWizardStep(8)">← SSH</button><button type="button" class="secondary" onclick="showWizardStep(1)">Review from the beginning</button></div>
</section>
</main>

<aside class="wizard-summary-panel" aria-label="Setup summary">
<h3>Setup notes</h3>
<p>Live notes for this browser tab: choices, entered values, credentials, URLs, and buttons you click.</p>
<div class="wizard-summary-warning"><strong>Contains secrets.</strong> Passwords and other credentials are intentionally included so you can copy the finished notes into your password manager or notepad. These notes stay in this tab's session storage, not in a new server-side plaintext file.</div>
<textarea id="wizard-summary-text" class="wizard-summary-text" readonly spellcheck="false" aria-label="Everlomp setup notes"></textarea>
<div class="wizard-summary-actions"><button type="button" class="secondary" data-summary-control onclick="copyWizardSummary(this)">Copy setup notes</button></div>
</aside>
</div>



<?php endif; ?>
</div>

<script>
async function copyLswsPassword(button = null) {
    return copyFieldValue('lsws-generated-password', button);
}

async function copyFilegatorPassword(button = null) {
    return copyFieldValue('filegator-generated-password', button);
}

async function copySshPassword(button = null) {
    return copyFieldValue('ssh-generated-password', button);
}

async function generateKopiaRepositoryPassword() {
    const passwordInput = document.getElementById('kopia-repository-password');
    const confirmInput = document.getElementById('kopia-repository-password-confirm');
    if (!passwordInput || !confirmInput || !window.crypto || !window.crypto.getRandomValues) {
        alert('Secure password generation is not available in this browser.');
        return;
    }
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*_-+=';
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    let password = '';
    for (const value of bytes) password += alphabet[value % alphabet.length];
    passwordInput.value = password;
    confirmInput.value = password;

    passwordInput.type = 'text';
    confirmInput.type = 'text';

    const toggleButton = document.getElementById('kopia-repository-password-toggle');
    if (toggleButton) {
        toggleButton.textContent = 'Hide Password';
    }
    captureWizardField(passwordInput);
}

function toggleKopiaRepositoryPassword() {
    const passwordInput = document.getElementById('kopia-repository-password');
    const confirmInput = document.getElementById('kopia-repository-password-confirm');
    const toggleButton = document.getElementById('kopia-repository-password-toggle');

    if (!passwordInput || !confirmInput) {
        return;
    }

    const show = passwordInput.type === 'password';
    passwordInput.type = show ? 'text' : 'password';
    confirmInput.type = show ? 'text' : 'password';

    if (toggleButton) {
        toggleButton.textContent = show ? 'Hide Password' : 'Show Password';
    }
}

function updateKopiaRepositoryMode(select) {
    if (!select) return;
    const localFields = document.getElementById('kopia-local-primary-fields');
    const useLocal = select.value === 'create';
    if (localFields) {
        localFields.classList.toggle('hidden', !useLocal);
        localFields.querySelectorAll('input, select').forEach((element) => {
            if (useLocal) element.setAttribute('required', 'required');
            else element.removeAttribute('required');
        });
    }

    const snapshotSection = document.getElementById('kopia-default-snapshots-section');
    const snapshotToggle = document.getElementById('kopia-default-snapshots-enabled');
    if (snapshotSection) snapshotSection.classList.toggle('hidden', !useLocal);
    if (snapshotToggle) {
        snapshotToggle.disabled = !useLocal;
        if (!useLocal) snapshotToggle.checked = false;
    }
    toggleKopiaDefaultSnapshots();
}

function toggleKopiaDefaultSnapshots() {
    const enabled = document.getElementById('kopia-default-snapshots-enabled');
    const fields = document.getElementById('kopia-default-snapshot-fields');
    if (!enabled || !fields) return;
    fields.classList.toggle('hidden', !enabled.checked || enabled.disabled);
}

function toggleKopiaOffsite() {
    const enabled = document.getElementById('kopia-offsite-enabled');
    const fields = document.getElementById('kopia-offsite-fields');
    if (!enabled || !fields) return;
    fields.classList.toggle('hidden', !enabled.checked);
    if (enabled.checked) {
        const select = fields.querySelector('.offsite-provider-select');
        if (select) updateOffsiteProvider(select);
    }
}

function updateOffsiteProvider(select) {
    if (!select) return;
    const form = select.closest('form');
    if (!form) return;
    form.querySelectorAll('.offsite-provider-fields').forEach((block) => {
        block.classList.toggle('hidden', block.dataset.offsiteProvider !== select.value);
    });
    const active = form.querySelector('.offsite-provider-fields:not(.hidden) .offsite-sftp-auth');
    if (active) {
        updateSftpAuth(active);
    } else {
        const testButton = form.querySelector('.offsite-test-button');
        if (testButton) {
            testButton.classList.remove('hidden');
            testButton.textContent = 'Test Connection';
        }
    }
}

function updateSftpAuth(select) {
    if (!select) return;
    const block = select.closest('[data-offsite-provider="sftp"]');
    if (!block) return;
    const useKey = select.value === 'key';
    block.querySelectorAll('.sftp-key-fields').forEach((el) => el.classList.toggle('hidden', !useKey));
    block.querySelectorAll('.sftp-password-fields').forEach((el) => el.classList.toggle('hidden', useKey));
    block.querySelectorAll('.sftp-host-key-fetch-row').forEach((el) => el.classList.toggle('hidden', useKey));
    const form = select.closest('form');
    const testButton = form ? form.querySelector('.offsite-test-button') : null;
    if (testButton) {
        testButton.classList.toggle('hidden', useKey);
        testButton.textContent = 'Test Password Connection';
    }
}

function toggleSftpPasswordChange(input) {
    const block = input ? input.closest('[data-offsite-provider="sftp"]') : null;
    if (!block) return;
    block.querySelectorAll('.sftp-new-password-fields').forEach((el) => el.classList.toggle('hidden', !input.checked));
}

function generateKopiaSftpRemotePassword(button) {
    const form = button ? button.closest('form') : null;
    if (!form || !window.crypto || !window.crypto.getRandomValues) return;
    const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%_-+=?';
    const bytes = new Uint8Array(24);
    window.crypto.getRandomValues(bytes);
    let password = '';
    for (const value of bytes) password += alphabet[value % alphabet.length];
    const p1 = form.querySelector('[name="kopia_offsite_sftp_new_password"]');
    const p2 = form.querySelector('[name="kopia_offsite_sftp_new_password_confirm"]');
    if (p1) { p1.value = password; p1.type = 'text'; captureWizardField(p1); }
    if (p2) { p2.value = password; p2.type = 'text'; }
}

async function setupKopiaSftpAccess(button) {
    const form = button ? button.closest('form') : null;
    if (!form || button.disabled) return;
    const result = form.querySelector('.sftp-bootstrap-result');
    const current = form.querySelector('[name="kopia_offsite_sftp_current_password"]');
    const change = form.querySelector('[name="kopia_offsite_sftp_change_password"]');
    const generate = form.querySelector('[name="kopia_offsite_sftp_generate_keypair"]');
    const p1 = form.querySelector('[name="kopia_offsite_sftp_new_password"]');
    const p2 = form.querySelector('[name="kopia_offsite_sftp_new_password_confirm"]');
    if (!current || !current.value) {
        if (result) { result.textContent = 'Enter the current remote SSH password.'; result.className = 'connection-test-result sftp-bootstrap-result error'; }
        return;
    }
    if (change && change.checked && (!p1 || !p2 || p1.value !== p2.value)) {
        if (result) { result.textContent = 'The new remote SSH passwords do not match.'; result.className = 'connection-test-result sftp-bootstrap-result error'; }
        return;
    }
    const data = new FormData(form);
    data.set('action', 'setup_kopia_sftp_access');
    data.set('kopia_offsite_sftp_generate_keypair', generate && generate.checked ? '1' : '0');
    data.set('kopia_offsite_sftp_change_password', change && change.checked ? '1' : '0');
    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Configuring…';
    if (result) { result.textContent = 'Connecting to the remote SSH account…'; result.className = 'connection-test-result sftp-bootstrap-result'; }
    try {
        const response = await fetch(window.location.href, {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'});
        const payload = await response.json();
        if (!response.ok || !payload || payload.ok !== true) throw new Error(payload && payload.message ? payload.message : 'Could not configure remote SSH access.');
        const known = form.querySelector('[name="kopia_offsite_sftp_known_hosts"]');
        if (known && payload.known_hosts) known.value = payload.known_hosts;
        if (payload.keypair_generated && payload.private_key) {
            const auth = form.querySelector('[name="kopia_offsite_sftp_auth"]');
            const key = form.querySelector('[name="kopia_offsite_sftp_private_key"]');
            const password = form.querySelector('[name="kopia_offsite_sftp_password"]');
            if (auth) { auth.value = 'key'; updateSftpAuth(auth); }
            if (key) key.value = payload.private_key;
            if (password) password.value = '';
            const pubWrap = form.querySelector('.sftp-generated-key-result');
            const pub = form.querySelector('.sftp-generated-public-key');
            const fp = form.querySelector('.sftp-generated-fingerprint');
            if (pubWrap) pubWrap.classList.remove('hidden');
            if (pub) pub.value = payload.public_key || '';
            if (fp) fp.textContent = payload.fingerprint || 'SSH key login verified.';
        } else {
            const auth = form.querySelector('[name="kopia_offsite_sftp_auth"]');
            const password = form.querySelector('[name="kopia_offsite_sftp_password"]');
            if (auth) { auth.value = 'password'; updateSftpAuth(auth); }
            if (password) password.value = change && change.checked && p1 ? p1.value : current.value;
        }
        current.value = '';
        if (result) { result.textContent = payload.detail || 'Remote SSH access configured.'; result.className = 'connection-test-result sftp-bootstrap-result success'; }
    } catch (error) {
        if (result) { result.textContent = error && error.message ? error.message : 'Could not configure remote SSH access.'; result.className = 'connection-test-result sftp-bootstrap-result error'; }
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
}

function updateScheduleFields(select) {
    if (!select) return;
    const form = select.closest('form') || document;
    const mode = select.value;
    const showTime = mode === 'daily' || mode === 'weekly';
    const showWeekday = mode === 'weekly';

    form.querySelectorAll('[data-schedule-for]').forEach((field) => {
        if (field.dataset.scheduleFor !== select.name) return;
        const role = field.dataset.scheduleRole;
        const visible = role === 'time' ? showTime : (role === 'weekday' ? showWeekday : true);
        field.classList.toggle('hidden', !visible);
    });
}

async function fetchKopiaSftpHostKey(button) {
    const form = button ? button.closest('form') : null;
    if (!form || button.disabled) return;
    const host = form.querySelector('[name="kopia_offsite_sftp_host"]');
    const port = form.querySelector('[name="kopia_offsite_sftp_port"]');
    const output = form.querySelector('[name="kopia_offsite_sftp_known_hosts"]');
    const result = form.querySelector('.sftp-host-key-result');
    if (!host || !output || !host.value.trim()) {
        if (result) { result.textContent = 'Enter the SFTP host first.'; result.className = 'connection-test-result sftp-host-key-result error'; }
        return;
    }
    const originalText = button.textContent;
    const data = new FormData();
    data.set('action', 'fetch_kopia_sftp_host_key');
    data.set('kopia_offsite_sftp_host', host.value.trim());
    data.set('kopia_offsite_sftp_port', port && port.value ? port.value : '22');
    button.disabled = true;
    button.textContent = 'Fetching…';
    if (result) { result.textContent = 'Reading SSH host key…'; result.className = 'connection-test-result sftp-host-key-result'; }
    try {
        const response = await fetch(window.location.href, {method:'POST', body:data, headers:{'X-Requested-With':'XMLHttpRequest'}, credentials:'same-origin'});
        const payload = await response.json();
        if (!response.ok || !payload || payload.ok !== true || !payload.known_hosts) throw new Error(payload && payload.message ? payload.message : 'Could not fetch SSH host key.');
        output.value = payload.known_hosts;
        if (result) { result.textContent = 'Host key fetched.'; result.className = 'connection-test-result sftp-host-key-result success'; }
    } catch (error) {
        if (result) { result.textContent = error && error.message ? error.message : 'Could not fetch SSH host key.'; result.className = 'connection-test-result sftp-host-key-result error'; }
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
}

async function testKopiaOffsiteConnection(button) {
    const form = button ? button.closest('form') : null;
    if (!form || button.disabled) return;

    const result = form.querySelector('.offsite-test-result');
    const originalText = button.textContent;
    const data = new FormData(form);
    data.set('action', 'test_kopia_offsite_connection');
    data.set('kopia_offsite_enabled', '1');

    button.disabled = true;
    button.textContent = 'Testing…';
    if (result) {
        result.textContent = 'Checking destination…';
        result.classList.remove('success', 'error');
    }

    try {
        const response = await fetch(window.location.href, {
            method: 'POST',
            body: data,
            headers: {'X-Requested-With': 'XMLHttpRequest'},
            credentials: 'same-origin'
        });
        const payload = await response.json();
        const ok = response.ok && payload && payload.ok === true;
        let message = payload && payload.message ? payload.message : (ok ? 'Connection succeeded.' : 'Connection failed.');
        const provider = form.querySelector('[name="kopia_offsite_type"]');
        const sftpAuth = form.querySelector('[name="kopia_offsite_sftp_auth"]');
        if (ok && provider && provider.value === 'sftp' && sftpAuth) {
            message = sftpAuth.value === 'key' ? 'SFTP key connection succeeded.' : 'SFTP password connection succeeded.';
        }

        if (result) {
            result.textContent = message;
            result.classList.toggle('success', ok);
            result.classList.toggle('error', !ok);
        } else {
            alert(message);
        }
    } catch (error) {
        const message = 'Connection test could not be completed.';
        if (result) {
            result.textContent = message;
            result.classList.remove('success');
            result.classList.add('error');
        } else {
            alert(message);
        }
    } finally {
        button.disabled = false;
        button.textContent = originalText;
    }
}

function beginKopiaBuild(form) {
    const button = document.getElementById('kopia-configure-submit');

    if (!button || button.disabled) {
        return false;
    }

    const localInstall = document.getElementById('kopia-install-local-version');
    kopiaInstallInProgress = true;
    if (!button.dataset.originalHtml) button.dataset.originalHtml = button.innerHTML;
    button.disabled = true;
    button.setAttribute('aria-busy', 'true');
    button.innerHTML = '<span class="button-spinner" aria-hidden="true"></span>'
        + (localInstall && localInstall.checked ? 'Installing local Kopia…' : 'Building Kopia…');

    form.querySelectorAll('input, select, button').forEach((element) => {
        if (element !== button) {
            element.setAttribute('aria-disabled', 'true');
        }
    });

    return true;
}

async function copyTextValue(value, button = null) {
    const text = String(value || '');
    if (!text) return false;
    let copied = false;
    try {
        await navigator.clipboard.writeText(text);
        copied = true;
    } catch (error) {
        const input = document.createElement('textarea');
        input.value = text;
        input.setAttribute('readonly','');
        input.style.position = 'fixed';
        input.style.opacity = '0';
        document.body.appendChild(input);
        input.select();
        try { copied = document.execCommand('copy'); } catch (copyError) { copied = false; }
        input.remove();
    }
    if (button && copied) {
        showCopySuccess(button);
    }
    return copied;
}

function showCopySuccess(button) {
    if (!button) return;
    if (button._copyFeedbackTimer) window.clearTimeout(button._copyFeedbackTimer);
    if (!button.dataset.copyOriginalHtml) button.dataset.copyOriginalHtml = button.innerHTML;
    const original = button.dataset.copyOriginalHtml;
    button.innerHTML = '<span class="copy-feedback-check" aria-hidden="true">✓</span><span>Copied</span>';
    button.classList.add('copy-success');
    button._copyFeedbackTimer = window.setTimeout(() => {
        button.innerHTML = original;
        button.classList.remove('copy-success');
        delete button.dataset.copyOriginalHtml;
        button._copyFeedbackTimer = null;
    }, 1400);
}

async function copyFieldValue(id, button = null) {
    const field = document.getElementById(id);
    if (!field) return false;
    return copyTextValue('value' in field ? field.value : field.textContent, button);
}

async function copyAllDashboardUrls(button = null) {
    const rows = Array.from(document.querySelectorAll('[data-dashboard-url]')).map((field) => {
        const label = field.dataset.dashboardLabel || 'Dashboard';
        return label + ': ' + field.value;
    });
    if (!rows.length) return;
    await copyTextValue(rows.join('\n'), button);
}

async function copyKopiaPassword(button = null) {
    return copyFieldValue('kopia-generated-password', button);
}

function generateMariaDbPassword() {
    const passwordInput = document.getElementById('mariadb-admin-password');
    const confirmInput = document.getElementById('mariadb-admin-password-confirm');

    if (!passwordInput || !confirmInput || !window.crypto || !window.crypto.getRandomValues) {
        alert('Secure password generation is not available in this browser.');
        return;
    }

    const lower = 'abcdefghijkmnopqrstuvwxyz';
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const digits = '23456789';
    const symbols = '!@#$%^&*_-+=';
    const all = lower + upper + digits + symbols;

    function randomIndex(length) {
        const max = Math.floor(256 / length) * length;
        const byte = new Uint8Array(1);

        do {
            window.crypto.getRandomValues(byte);
        } while (byte[0] >= max);

        return byte[0] % length;
    }

    const chars = [
        lower[randomIndex(lower.length)],
        upper[randomIndex(upper.length)],
        digits[randomIndex(digits.length)],
        symbols[randomIndex(symbols.length)]
    ];

    while (chars.length < 24) {
        chars.push(all[randomIndex(all.length)]);
    }

    for (let i = chars.length - 1; i > 0; i--) {
        const j = randomIndex(i + 1);
        [chars[i], chars[j]] = [chars[j], chars[i]];
    }

    const generated = chars.join('');
    passwordInput.value = generated;
    confirmInput.value = generated;

    passwordInput.type = 'text';
    confirmInput.type = 'text';
    passwordInput.focus();
    passwordInput.select();
    captureWizardField(passwordInput);
}

document.addEventListener('DOMContentLoaded', () => {
    const repositoryMode = document.getElementById('kopia-repository-mode');
    if (repositoryMode) updateKopiaRepositoryMode(repositoryMode);
    document.querySelectorAll('.schedule-mode-select').forEach(updateScheduleFields);
    toggleDrupalSource();
    toggleDrupalGitAuth();
});

function toggleDrupalSource(root = document) {
    if (!root || !root.querySelector) return;
    const selected = root.querySelector('input[name="drupal_installer_source"]:checked');
    const gitFields = root.querySelector('#drupal-git-source-fields');
    const repository = root.querySelector('#drupal-git-repository');
    if (!selected || !gitFields) return;

    const isGit = selected.value === 'git';
    gitFields.classList.toggle('hidden', !isGit);
    if (repository) repository.required = isGit;
    if (isGit) toggleDrupalGitAuth(root);
    else {
        const token = root.querySelector('#drupal-git-token');
        if (token) token.required = false;
    }
}

function toggleDrupalGitAuth(root = document) {
    if (!root || !root.querySelector) return;
    const selected = root.querySelector('input[name="drupal_git_auth"]:checked');
    const tokenFields = root.querySelector('#drupal-git-token-fields');
    const token = root.querySelector('#drupal-git-token');
    if (!selected || !tokenFields) return;

    const usesToken = selected.value === 'token';
    tokenFields.classList.toggle('hidden', !usesToken);
    if (token) token.required = usesToken;
}

function toggleDb(prefix) {
    const checked = document.querySelector('input[name="db_mode"]:checked');
    if (!checked) return;

    const mode = checked.value;
    const autoBox = document.getElementById(prefix + '-auto-db');
    const existingBox = document.getElementById(prefix + '-existing-db');

    if (autoBox) autoBox.classList.toggle('hidden', mode !== 'auto');
    if (existingBox) existingBox.classList.toggle('hidden', mode !== 'existing');
}

function togglePhpbbSmtp() {
    const enabled = document.getElementById('phpbb-smtp-enabled');
    const fields = document.getElementById('phpbb-smtp-fields');
    if (!enabled || !fields) return;
    fields.classList.toggle('hidden', !enabled.checked);
}

const everlompWizardSummaryStorageKey = 'everlomp-wizard-summary-v1';
let everlompWizardSummary = {fields:{}, urls:{}, actions:[]};

function loadWizardSummary() {
    try {
        const stored = sessionStorage.getItem(everlompWizardSummaryStorageKey);
        if (stored) {
            const parsed = JSON.parse(stored);
            if (parsed && typeof parsed === 'object') {
                everlompWizardSummary = {
                    fields: parsed.fields && typeof parsed.fields === 'object' ? parsed.fields : {},
                    urls: parsed.urls && typeof parsed.urls === 'object' ? parsed.urls : {},
                    actions: Array.isArray(parsed.actions) ? parsed.actions.slice(-250) : []
                };
            }
        }
    } catch (error) {
        // Summary still works in-memory if sessionStorage is blocked.
    }
}

function saveWizardSummary() {
    try {
        sessionStorage.setItem(everlompWizardSummaryStorageKey, JSON.stringify(everlompWizardSummary));
    } catch (error) {
        // Do not interrupt setup
    }
    renderWizardSummary();
}

function wizardSummaryResolveUrl(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    try { return new URL(raw, window.location.href).href; } catch (error) { return raw; }
}

function setWizardSummaryUrl(label, value) {
    const url = wizardSummaryResolveUrl(value);
    if (!label || !url) return;
    everlompWizardSummary.urls[String(label).trim()] = url;
}

function wizardSummaryFieldLabel(field) {
    const labels = {
        db_username:'MariaDB admin username', db_password:'MariaDB admin password',
        site_url:'Site URL', site_title:'WordPress site title', wp_admin_user:'WordPress admin username',
        wp_admin_email:'WordPress admin email', wp_admin_password:'WordPress admin password',
        drupal_site_name:'Drupal site name', drupal_admin_user:'Drupal admin username',
        drupal_admin_email:'Drupal admin email', drupal_admin_password:'Drupal admin password',
        drupal_installer_source:'Drupal installer source', drupal_git_repository:'Drupal Git repository',
        drupal_git_ref:'Drupal Git ref', drupal_git_document_root:'Drupal document root',
        drupal_git_auth:'Drupal Git access', drupal_git_username:'Drupal Git username', drupal_git_token:'Drupal Git token',
        phpbb_board_name:'phpBB board name', phpbb_board_description:'phpBB board description',
        phpbb_admin_user:'phpBB admin username', phpbb_admin_email:'phpBB admin email', phpbb_admin_password:'phpBB admin password',
        phpbb_smtp_enabled:'phpBB SMTP enabled', phpbb_smtp_host:'phpBB SMTP host', phpbb_smtp_port:'phpBB SMTP port',
        phpbb_smtp_auth:'phpBB SMTP authentication', phpbb_smtp_user:'phpBB SMTP username', phpbb_smtp_password:'phpBB SMTP password',
        existing_db_host:'Existing database host', existing_db_port:'Existing database port', existing_db_name:'Existing database name',
        existing_db_user:'Existing database username', existing_db_password:'Existing database password', auto_db_name:'New application database name',
        filegator_root:'FileGator root', kopia_repository_mode:'Kopia primary repository setup', kopia_repository_path:'Kopia repository path',
        kopia_repository_password:'Kopia repository encryption password', kopia_encryption:'Kopia repository encryption',
        kopia_default_snapshots_enabled:'Kopia default snapshots', kopia_default_snapshot_time:'Kopia daily snapshot time',
        kopia_offsite_type:'Kopia off-site destination type', kopia_offsite_sftp_host:'SFTP host', kopia_offsite_sftp_port:'SFTP port',
        kopia_offsite_sftp_username:'SFTP username', kopia_offsite_sftp_path:'SFTP remote path', kopia_offsite_sftp_auth:'SFTP authentication mode',
        kopia_offsite_sftp_password:'SFTP password', kopia_offsite_sftp_current_password:'SFTP current password',
        kopia_offsite_sftp_new_password:'SFTP new password', kopia_offsite_sftp_private_key:'SFTP private key',
        kopia_offsite_sftp_known_hosts:'SFTP known_hosts', kopia_offsite_webdav_url:'WebDAV URL', kopia_offsite_webdav_username:'WebDAV username',
        kopia_offsite_webdav_password:'WebDAV password', kopia_offsite_filesystem_path:'Mounted backup path', kopia_offsite_endpoint:'S3/MinIO endpoint',
        kopia_offsite_bucket:'S3/MinIO bucket', kopia_offsite_region:'S3/MinIO region', kopia_offsite_prefix:'S3/MinIO prefix',
        kopia_offsite_access_key:'S3/MinIO access key', kopia_offsite_secret_key:'S3/MinIO secret key', kopia_offsite_session_token:'S3 session token',
        kopia_offsite_kopia_token:'Kopia configuration token', kopia_offsite_rclone_remote_path:'Rclone remote path', kopia_offsite_rclone_config:'rclone.conf',
        sql_schedule:'SQL backup schedule', sql_time:'SQL backup time', sql_weekday:'SQL backup weekday', sql_keep:'SQL backups kept', sql_enabled:'SQL backups enabled',
        kopia_auto_update:'Kopia automatic updates', kopia_update_schedule:'Kopia update schedule', kopia_update_time:'Kopia update time', kopia_update_weekday:'Kopia update weekday'
    };
    const name = field.name || field.id || 'field';
    if (labels[name]) return labels[name];
    return name.replace(/[_-]+/g,' ').replace(/\b\w/g,(char)=>char.toUpperCase());
}

function wizardSummaryIsSecret(field) {
    const name = String(field.name || field.id || '').toLowerCase();
    return field.type === 'password' || /(password|secret|token|private_key|access_key|known_hosts|rclone_config)/.test(name);
}

function captureWizardField(field) {
    if (!(field instanceof HTMLInputElement || field instanceof HTMLTextAreaElement || field instanceof HTMLSelectElement)) return;
    const name = String(field.name || field.id || '');
    if (!name || field.type === 'hidden' || /(?:^|_)confirm$/.test(name) || /_password_confirm$/.test(name)) return;
    if (field.matches('[readonly]') && (/url/i.test(field.id) || /^https?:|^\//i.test(String(field.value || '')))) {
        const box = field.closest('.access-box');
        const label = box?.querySelector('.access-box-title strong')?.textContent?.trim() || field.dataset.dashboardLabel || wizardSummaryFieldLabel(field);
        setWizardSummaryUrl(label, field.value);
        saveWizardSummary();
        return;
    }
    if (field.type === 'radio' && !field.checked) return;
    let value = '';
    if (field.type === 'checkbox') value = field.checked ? 'Yes' : 'No';
    else if (field instanceof HTMLSelectElement) {
        const option = field.selectedOptions && field.selectedOptions[0] ? field.selectedOptions[0].textContent.trim() : '';
        value = option && option !== field.value ? option + ' (' + field.value + ')' : field.value;
    } else value = field.value;
    if (value === '') {
        delete everlompWizardSummary.fields[name];
    } else {
        everlompWizardSummary.fields[name] = {label:wizardSummaryFieldLabel(field), value:String(value), secret:wizardSummaryIsSecret(field)};
    }
    saveWizardSummary();
}

function captureWizardForm(form) {
    if (!(form instanceof HTMLFormElement)) return;
    form.querySelectorAll('input, textarea, select').forEach(captureWizardField);
}

function captureWizardUrls(root = document) {
    const stateUrls = everlompWizardState.urls || {};
    Object.entries(stateUrls).forEach(([label,value]) => setWizardSummaryUrl(label, value));
    if (!root.querySelectorAll) return;
    root.querySelectorAll('.access-box .copy-field input, [data-dashboard-url]').forEach((field) => {
        const box = field.closest('.access-box');
        const label = field.dataset.dashboardLabel || box?.querySelector('.access-box-title strong')?.textContent?.trim() || wizardSummaryFieldLabel(field);
        setWizardSummaryUrl(label, field.value);
    });
}

function setWizardSummaryCredential(key, label, value) {
    const text = String(value || '').trim();
    if (!text) return;
    everlompWizardSummary.fields[key] = {label, value:text, secret:true};
}

function captureOneTimeCredentials(root = document) {
    if (!root.querySelector) return;
    const lswsPass = root.querySelector('#lsws-generated-password')?.textContent?.trim() || '';
    const lswsUser = root.querySelector('#lsws-generated-username')?.textContent?.trim() || '';
    if (lswsUser) everlompWizardSummary.fields.lsws_generated_username = {label:'OpenLiteSpeed WebAdmin username',value:lswsUser,secret:false};
    if (lswsPass) setWizardSummaryCredential('lsws_generated_password','OpenLiteSpeed WebAdmin password',lswsPass);

    const fgPass = root.querySelector('#filegator-generated-password')?.textContent?.trim() || '';
    const fgUser = root.querySelector('#filegator-generated-username')?.textContent?.trim() || '';
    const fgRoot = root.querySelector('#filegator-generated-root')?.textContent?.trim() || '';
    if (fgUser) everlompWizardSummary.fields.filegator_generated_username = {label:'FileGator username',value:fgUser,secret:false};
    if (fgPass) setWizardSummaryCredential('filegator_generated_password','FileGator password',fgPass);
    if (fgRoot) everlompWizardSummary.fields.filegator_generated_root = {label:'FileGator root',value:fgRoot,secret:false};

    const kopiaPass = root.querySelector('#kopia-generated-password')?.textContent?.trim() || '';
    const kopiaUser = root.querySelector('#kopia-generated-username')?.textContent?.trim() || '';
    const kopiaUrl = root.querySelector('#kopia-generated-url')?.textContent?.trim() || '';
    if (kopiaUser) everlompWizardSummary.fields.kopia_generated_username = {label:'Kopia Web UI username',value:kopiaUser,secret:false};
    if (kopiaPass) setWizardSummaryCredential('kopia_generated_password','Kopia Web UI password',kopiaPass);
    if (kopiaUrl) setWizardSummaryUrl('Kopia', kopiaUrl);

    const sshPass = root.querySelector('#ssh-generated-password')?.textContent?.trim() || '';
    const sshUser = root.querySelector('#ssh-generated-username')?.textContent?.trim() || '';
    const sshPort = root.querySelector('#ssh-generated-port')?.textContent?.trim() || '';
    const sshCommand = root.querySelector('#ssh-generated-command')?.textContent?.trim() || '';
    if (sshUser) everlompWizardSummary.fields.ssh_generated_username = {label:'SSH username',value:sshUser,secret:false};
    if (sshPort) everlompWizardSummary.fields.ssh_gptcp2_port = {label:'SSH GPTCP2 port',value:sshPort,secret:false};
    if (sshPass) setWizardSummaryCredential('ssh_generated_password','SSH password',sshPass);
    if (sshCommand) everlompWizardSummary.fields.ssh_command = {label:'SSH command',value:sshCommand,secret:false};
}

function recordWizardAction(label, step = everlompWizardStep) {
    const clean = String(label || '').replace(/\s+/g,' ').trim();
    if (!clean) return;
    const now = new Date();
    everlompWizardSummary.actions.push({time:now.toLocaleTimeString([], {hour:'2-digit',minute:'2-digit',second:'2-digit'}), step:Number(step) || everlompWizardStep, label:clean});
    if (everlompWizardSummary.actions.length > 250) everlompWizardSummary.actions = everlompWizardSummary.actions.slice(-250);
    saveWizardSummary();
}

function renderWizardSummary() {
    const output = document.getElementById('wizard-summary-text');
    if (!output) return;
    const lines = ['EVERLOMP SETUP NOTES', '====================', ''];
    lines.push('SETUP STATUS');
    lines.push('------------');
    lines.push('Terms: ' + (everlompWizardState.terms ? 'Accepted' : 'Not accepted'));
    lines.push('MariaDB administrator: ' + (everlompWizardState.database ? 'Configured' : 'Not configured'));
    lines.push('OpenLiteSpeed WebAdmin: ' + (everlompWizardState.openlitespeed ? 'Configured' : 'Not configured'));
    lines.push('Real client IP: ' + (everlompWizardState.realIp ? 'Ready' : 'Not ready'));
    lines.push('Domain-only access: ' + (everlompWizardState.domainOnly ? 'Ready' : 'Not ready'));
    lines.push('FileGator: ' + (everlompWizardState.filegator ? 'Installed' : (wizardDecision('filegator-decided') ? 'Skipped / reviewed' : 'Not decided')));
    lines.push('Primary application: ' + (everlompWizardState.primaryApp || 'Not installed'));
    lines.push('HotPocket: ' + (everlompWizardState.hotpocket ? 'Enabled' : (wizardDecision('hotpocket-decided') ? 'Off / skipped' : 'Not decided')));
    lines.push('SQL backup schedule: ' + (everlompWizardState.backupScheduleConfigured || wizardDecision('backup-schedule-decided') ? 'Saved' : 'Not saved'));
    lines.push('Kopia: ' + (everlompWizardState.kopia ? 'Configured' : (wizardDecision('backup-decided') ? 'Reviewed / skipped' : 'Not configured')));
    lines.push('SSH / GPTCP2: ' + (everlompWizardState.sshDecisionMade ? (everlompWizardState.sshEnabled ? 'Enabled on port ' + (everlompWizardState.sshPort || '?') : 'Disabled') : 'Not decided'));
    lines.push('');
    const urls = Object.entries(everlompWizardSummary.urls || {});
    lines.push('URLS');
    lines.push('----');
    if (urls.length) urls.forEach(([label,value]) => lines.push(label + ': ' + value));
    else lines.push('(No URLs recorded yet)');
    lines.push('', 'VALUES & CREDENTIALS', '--------------------');
    const fields = Object.values(everlompWizardSummary.fields || {});
    if (fields.length) fields.forEach((item) => lines.push(item.label + ': ' + item.value));
    else lines.push('(No values recorded yet)');
    lines.push('', 'BUTTONS / ACTIONS', '-----------------');
    if (everlompWizardSummary.actions.length) everlompWizardSummary.actions.forEach((item) => lines.push('[' + item.time + '] Step ' + item.step + ': ' + item.label));
    else lines.push('(No buttons clicked yet)');
    output.value = lines.join('\n');
}

async function copyWizardSummary(button = null) {
    const output = document.getElementById('wizard-summary-text');
    if (!output) return false;
    return copyTextValue(output.value, button);
}

function captureVisibleWizardDetails(root = document) {
    captureWizardUrls(root);
    captureOneTimeCredentials(root);
    saveWizardSummary();
}

let everlompWizardState = {};
let everlompWizardStep = 1;
let backupFragmentLoaded = false;
let kopiaInstallInProgress = false;
let everlompWizardTransitionLocked = false;

function readWizardStateFrom(root = document) {
    const node = root.querySelector ? root.querySelector('#wizard-state-json') : null;
    if (!node) return null;
    try { return JSON.parse(node.textContent || '{}'); } catch (error) { return null; }
}

function wizardDecision(name) {
    return localStorage.getItem('everlomp-wizard-' + name) === '1';
}
function setWizardDecision(name) {
    localStorage.setItem('everlomp-wizard-' + name, '1');
}

function wizardMaxAllowedStep() {
    if (!everlompWizardState.terms) return 1;
    if (!everlompWizardState.database) return 2;
    if (!(everlompWizardState.openlitespeed && everlompWizardState.realIp && everlompWizardState.domainOnly)) return 3;
    if (!(everlompWizardState.filegator || wizardDecision('filegator-decided'))) return 4;
    if (!everlompWizardState.primaryApp) return 5;
    if (!(everlompWizardState.hotpocket || wizardDecision('hotpocket-decided'))) return 6;
    if (!(everlompWizardState.kopia || wizardDecision('backup-decided'))) return 7;
    if (!everlompWizardState.sshDecisionMade) return 8;
    return 9;
}

function refreshWizardTransitionButtons() {
    document.querySelectorAll('button[onclick*="showWizardStep("]').forEach((button) => {
        const match = (button.getAttribute('onclick') || '').match(/showWizardStep\((\d+)/);
        if (!match) return;
        const targetStep = Number(match[1]);
        const shouldLock = everlompWizardTransitionLocked && targetStep > everlompWizardStep;
        if (shouldLock) {
            if (!button.hasAttribute('data-everlomp-prelock-disabled')) {
                button.setAttribute('data-everlomp-prelock-disabled', button.disabled ? '1' : '0');
            }
            button.disabled = true;
        } else if (button.hasAttribute('data-everlomp-prelock-disabled')) {
            button.disabled = button.getAttribute('data-everlomp-prelock-disabled') === '1';
            button.removeAttribute('data-everlomp-prelock-disabled');
        }
    });
}

function setWizardTransitionLock(locked) {
    everlompWizardTransitionLocked = !!locked;
    refreshWizardNav();
    refreshWizardTransitionButtons();
}

function refreshWizardNav() {
    const nav = document.getElementById('wizard-nav');
    if (!nav) return;
    const maxAllowed = wizardMaxAllowedStep();
    nav.querySelectorAll('button[data-step]').forEach((button) => {
        const step = Number(button.dataset.step || 0);
        button.disabled = step > maxAllowed || (everlompWizardTransitionLocked && step > everlompWizardStep);
        button.classList.toggle('active', step === everlompWizardStep);
        button.classList.toggle('done', step < everlompWizardStep && step <= maxAllowed);
        const check = button.querySelector('.wizard-check');
        if (check) check.style.visibility = step < everlompWizardStep && step <= maxAllowed ? 'visible' : 'hidden';
    });
    const bar = document.getElementById('wizard-progress-bar');
    if (bar) bar.style.width = Math.max(100 / 9, everlompWizardStep * (100 / 9)) + '%';
}

function showWizardStep(step, force = false) {
    const root = document.getElementById('wizard-root');
    if (!root) return;
    if (everlompWizardTransitionLocked && Number(step) > everlompWizardStep) return;
    const maxAllowed = wizardMaxAllowedStep();
    const next = force ? Math.max(1, Math.min(9, step)) : Math.max(1, Math.min(maxAllowed, step));
    everlompWizardStep = next;
    localStorage.setItem('everlomp-wizard-step', String(next));
    root.querySelectorAll('.wizard-panel').forEach((panel) => panel.classList.toggle('active', Number(panel.dataset.step) === next));
    refreshWizardNav();
    refreshWizardTransitionButtons();
    if (next === 7) loadBackupFragment();
    if (window.matchMedia('(max-width: 900px)').matches) {
        const content = root.querySelector('.wizard-content');
        if (content) content.scrollIntoView({behavior:'smooth', block:'start'});
    }
}

function showWizardNotice(message, isError = false) {
    const box = document.getElementById('wizard-global-notice');
    if (!box) return;
    box.textContent = message || '';
    box.className = 'notice wizard-notice show ' + (isError ? 'error' : 'success');
    if (message) box.scrollIntoView({behavior:'smooth', block:'nearest'});
}

function setButtonBusy(button, busy, text = 'Saving…') {
    if (!button) return;
    if (busy) {
        button.dataset.originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<span class="spinner-inline"></span>' + text;
    } else {
        button.disabled = false;
        if (button.dataset.originalHtml) button.innerHTML = button.dataset.originalHtml;
    }
}

async function refreshWizardStep(step) {
    const url = everlompWizardState.panelUrl || window.location.pathname;
    try {
        const response = await fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        updateWizardFromResponse(doc, step);
    } catch (error) {
        showWizardNotice('Could not refresh the server state. Check the connection and try again.', true);
    }
}

function updateWizardFromResponse(doc, step) {
    const newState = readWizardStateFrom(doc);
    if (newState) everlompWizardState = newState;
    const currentPanel = document.getElementById('wizard-step-' + step);
    const replacement = doc.getElementById('wizard-step-' + step);
    if (currentPanel && replacement) currentPanel.innerHTML = replacement.innerHTML;

    if (Number(step) !== 9) {
        const finishCurrent = document.getElementById('wizard-step-9');
        const finishReplacement = doc.getElementById('wizard-step-9');
        if (finishCurrent && finishReplacement) finishCurrent.innerHTML = finishReplacement.innerHTML;
    }

    const appsCurrent = document.getElementById('wizard-apps-content');
    const appsReplacement = doc.getElementById('wizard-apps-content');
    if (appsCurrent && appsReplacement) appsCurrent.innerHTML = appsReplacement.innerHTML;
    refreshWizardNav();
    initializeDynamicControls(document);
    captureVisibleWizardDetails(document);
}

async function submitWizardForm(event) {
    if (event.defaultPrevented) return;
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.classList.contains('wizard-ajax-form')) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    const submitter = event.submitter || form.querySelector('button[type="submit"]');
    captureWizardForm(form);
    const data = new FormData(form);
    if (submitter && submitter.name) data.set(submitter.name, submitter.value);
    const step = Number(form.dataset.step || form.closest('.wizard-panel')?.dataset.step || everlompWizardStep);
    const action = String(data.get('action') || '');
    const busyText = action === 'enable_ssh'
        ? 'Enabling & verifying SSH…'
        : (action === 'disable_ssh' ? 'Disabling SSH…' : 'Saving…');
    setButtonBusy(submitter, true, busyText);
    try {
        const response = await fetch(everlompWizardState.panelUrl || window.location.pathname, {method:'POST', body:data, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const error = doc.querySelector('.wizard-server-notice.error, .notice.error');
        const success = doc.querySelector('.wizard-server-notice.success, .notice.success');
        updateWizardFromResponse(doc, step);
        if (error) {
            showWizardNotice(error.textContent.trim(), true);
            return;
        }
        if (success) showWizardNotice(success.textContent.trim(), false);
        if (everlompWizardState.filegator) setWizardDecision('filegator-decided');
        if (everlompWizardState.hotpocket) setWizardDecision('hotpocket-decided');
        const advance = Number(form.dataset.advance || 0);
        if (advance && advance <= wizardMaxAllowedStep()) showWizardStep(advance);
        else showWizardStep(step, true);
    } catch (error) {
        const action = String(data.get('action') || '');
        const message = action === 'enable_ssh'
            ? 'SSH setup did not return a complete response. Check the SSH state below before retrying.'
            : (action === 'disable_ssh'
                ? 'SSH disable did not return a complete response. Check the SSH state below before retrying.'
                : 'The request could not be completed. Your browser stayed on this wizard; no navigation occurred.');
        showWizardNotice(message, true);
    } finally {
        setButtonBusy(submitter, false);
    }
}

function skipFileGator() { setWizardDecision('filegator-decided'); showWizardStep(5); }
function completeFileGatorStep() { setWizardDecision('filegator-decided'); showWizardStep(5); }
function skipHotPocket() { setWizardDecision('hotpocket-decided'); showWizardStep(7); }
function completeHotPocketStep() { setWizardDecision('hotpocket-decided'); showWizardStep(7); }
async function completeBackupStep() {
    setWizardDecision('backup-decided');
    await refreshWizardStep(8);
    showWizardStep(8);
}

async function openAppInstaller(app) {
    const drawer = document.getElementById('app-installer-drawer');
    if (!drawer) return;
    drawer.innerHTML = '<div class="wizard-card"><div class="wizard-lock"><span class="spinner-inline"></span>Loading installer…</div></div>';
    try {
        const base = everlompWizardState.panelUrl || window.location.pathname;
        const joiner = base.includes('?') ? '&' : '?';
        const response = await fetch(base + joiner + 'install=' + encodeURIComponent(app), {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html, 'text/html');
        const card = Array.from(doc.querySelectorAll('section.card.full')).find((node) => !node.classList.contains('terms-card'));
        if (!card) throw new Error('Installer is not available. Check prerequisite steps.');
        const packageTerms = app.startsWith('external:')
            ? doc.querySelector('section.card.full.terms-card')
            : null;
        drawer.innerHTML = card.outerHTML + (packageTerms ? packageTerms.outerHTML : '');
        const form = drawer.querySelector('form');
        if (form) {
            form.dataset.appInstaller = app;
            form.querySelectorAll('input[name="accept_everlomp_terms"]').forEach((input) => {
                input.checked = true;
                const label = input.closest('.terms-check');
                if (label) label.style.display = 'none';
            });
            drawer.querySelectorAll('a[href*="everlomp-installation-terms"]').forEach((link) => {
                link.href = '#';
                link.onclick = (event) => { event.preventDefault(); showWizardStep(1); };
            });
            drawer.querySelectorAll('a.back, a.button.secondary').forEach((link) => {
                if ((link.textContent || '').trim().toLowerCase() === 'cancel') link.remove();
            });
        }
        initializeDynamicControls(drawer);
        drawer.scrollIntoView({behavior:'smooth', block:'start'});
    } catch (error) {
        drawer.innerHTML = '<div class="notice error">' + escapeHtml(error && error.message ? error.message : 'Could not load the application installer.') + '</div>';
    }
}

async function submitAppInstaller(event) {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.dataset.appInstaller) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    const app = form.dataset.appInstaller;
    const button = event.submitter || form.querySelector('button[type="submit"]');
    setButtonBusy(button, true, 'Installing…');
    try {
        const base = everlompWizardState.panelUrl || window.location.pathname;
        const joiner = base.includes('?') ? '&' : '?';
        captureWizardForm(form);
        const data = new FormData(form);
        if (event.submitter && event.submitter.name) data.set(event.submitter.name, event.submitter.value);
        const response = await fetch(base + joiner + 'install=' + encodeURIComponent(app), {method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html,'text/html');
        const returnedState = readWizardStateFrom(doc);
        if (returnedState && returnedState.primaryApp) {
            updateWizardFromResponse(doc, 5);
            document.getElementById('app-installer-drawer').innerHTML = '';
            refreshWizardNav();
            refreshWizardTransitionButtons();
            captureVisibleWizardDetails(document);

            const appName = app === 'wordpress'
                ? 'WordPress'
                : (app === 'drupal' ? 'Drupal' : (app === 'phpbb' ? 'phpBB' : 'External application'));
            showWizardNotice(appName + ' installed successfully.', false);
            showWizardStep(6);
            return;
        }
        const error = doc.querySelector('.notice.error');
        if (error) showWizardNotice(error.textContent.trim(), true);
        const card = Array.from(doc.querySelectorAll('section.card.full')).find((node) => !node.classList.contains('terms-card'));
        if (card) {
            const drawer = document.getElementById('app-installer-drawer');
            drawer.innerHTML = card.outerHTML;
            const newForm = drawer.querySelector('form');
            if (newForm) {
                newForm.dataset.appInstaller = app;
                newForm.querySelectorAll('input[name="accept_everlomp_terms"]').forEach((input) => { input.checked = true; const label=input.closest('.terms-check'); if(label) label.style.display='none'; });
            }
            initializeDynamicControls(drawer);
        }
    } catch (error) {
        showWizardNotice('Application installation could not be completed.', true);
    } finally {
        setButtonBusy(button, false);
    }
}

function decorateBackupGrid(grid) {
    if (!grid) return grid;
    grid.querySelectorAll('.terms-card').forEach((node) => node.remove());
    grid.querySelectorAll('input[name="accept_everlomp_terms"]').forEach((input) => {
        input.checked = true;
        const label = input.closest('.terms-check');
        if (label) label.style.display = 'none';
    });
    const sections = Array.from(grid.querySelectorAll(':scope > section'));
    const sql = sections.find((section) => section.querySelector('h3')?.textContent.includes('MariaDB logical backups'));
    const kopia = sections.find((section) => ['Kopia','Enable Kopia'].includes((section.querySelector('h3')?.textContent || '').trim()));
    const replication = sections.find((section) => section.querySelector('h3')?.textContent.includes('Repository replication'));
    const updates = sections.find((section) => section.querySelector('h3')?.textContent.includes('Kopia source updates'));
    const ordered = [sql, kopia, replication, updates].filter(Boolean);
    ordered.forEach((section) => section.remove());
    const phase1 = document.createElement('div'); phase1.className='backup-phase'; phase1.innerHTML='<strong>1. Save the backup schedule</strong><span>Set SQL dump timing and retention before Kopia snapshots are configured.</span>';
    grid.prepend(phase1);
    if (sql) phase1.after(sql);
    if (kopia) {
        const scheduleReady = !!everlompWizardState.backupScheduleConfigured || wizardDecision('backup-schedule-decided');
        const phase2 = document.createElement('div'); phase2.className='backup-phase'; phase2.innerHTML='<strong>2. Install & configure Kopia</strong><span>Create encrypted snapshot storage, policies, and optional replication.</span>';
        (sql || phase1).after(phase2); phase2.after(kopia);
        if (replication) kopia.after(replication);
        if (updates && !!everlompWizardState.kopia && !kopiaInstallInProgress) { const phase3=document.createElement('div'); phase3.className='backup-phase'; phase3.innerHTML='<strong>3. Optional Kopia maintenance</strong><span>Choose automatic source-update behavior after installation.</span>'; (replication || kopia).after(phase3); phase3.after(updates); } else if (updates) { updates.remove(); }
        if (!scheduleReady) {
            [kopia, replication, updates].filter(Boolean).forEach((section) => section.classList.add('hidden'));
            const lock = document.createElement('div');
            lock.className = 'wizard-card';
            lock.innerHTML = '<div class="wizard-lock"><strong>Save the backup schedule first.</strong><br>Once the SQL schedule/retention choice is saved, Kopia installation unlocks here without leaving the wizard.</div>';
            phase2.after(lock);
        }
    }
    return grid;
}

async function loadBackupFragment(force = false) {
    const host = document.getElementById('backup-fragment');
    if (!host || (backupFragmentLoaded && !force)) return;
    backupFragmentLoaded = true;
    try {
        const base = everlompWizardState.panelUrl || window.location.pathname;
        const joiner = base.includes('?') ? '&' : '?';
        const response = await fetch(base + joiner + 'view=backup', {credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html,'text/html');
        const grid = doc.querySelector('.grid');
        if (!grid) throw new Error('Backup controls were not returned.');
        const clone = grid.cloneNode(true);
        decorateBackupGrid(clone);
        host.innerHTML = '';
        host.appendChild(clone);
        initializeDynamicControls(host);
        updateKopiaStateFromBackup(host);
        captureVisibleWizardDetails(host);
    } catch (error) {
        host.innerHTML = '<div class="notice error">Could not load backup controls. <button type="button" class="secondary" onclick="loadBackupFragment(true)">Retry</button></div>';
        backupFragmentLoaded = false;
    }
}

function updateKopiaStateFromBackup(root) {
    const text = root ? root.textContent || '' : '';
    if (text.includes('Kopia configured')) everlompWizardState.kopia = true;
    refreshWizardNav();
}

async function submitBackupForm(event) {
    const form = event.target;
    const host = document.getElementById('backup-fragment');
    if (!(form instanceof HTMLFormElement) || !host || !host.contains(form)) return;
    event.preventDefault();
    if (!form.reportValidity()) return;
    const button = event.submitter || form.querySelector('button[type="submit"]');
    captureWizardForm(form);
    const data = new FormData(form);
    if (event.submitter && event.submitter.name) data.set(event.submitter.name,event.submitter.value);
    const submittedAction = String(data.get('action') || '');
    const configuringKopia = submittedAction === 'configure_kopia';

    if (configuringKopia) {
        if (!beginKopiaBuild(form)) return;
        setWizardTransitionLock(true);
    } else if (button && !button.disabled) {
        setButtonBusy(button, true, 'Saving…');
    }

    try {
        const base = everlompWizardState.panelUrl || window.location.pathname;
        const joiner = base.includes('?') ? '&' : '?';
        const response = await fetch(base + joiner + 'view=backup', {method:'POST',body:data,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});
        const html = await response.text();
        const doc = new DOMParser().parseFromString(html,'text/html');
        const error = doc.querySelector('.notice.error');
        const success = doc.querySelector('.notice.success');
        const grid = doc.querySelector('.grid');
        if (!grid) throw new Error('Backup response was incomplete.');
        if (configuringKopia) {
            kopiaInstallInProgress = false;
            setWizardTransitionLock(false);
            if (button) {
                button.removeAttribute('aria-busy');
                setButtonBusy(button, false);
            }
        }
        if (!error && submittedAction === 'save_sql_backup_settings') {
            everlompWizardState.backupScheduleConfigured = true;
            setWizardDecision('backup-schedule-decided');
        }
        if (!error && submittedAction === 'configure_kopia') {
            const returnedText = grid.textContent || '';
            everlompWizardState.kopia = returnedText.includes('Kopia configured');
        }
        const clone = grid.cloneNode(true);
        decorateBackupGrid(clone);
        host.innerHTML=''; host.appendChild(clone);
        initializeDynamicControls(host);
        updateKopiaStateFromBackup(host);
        captureVisibleWizardDetails(host);
        if (error) showWizardNotice(error.textContent.trim(),true); else if (success) showWizardNotice(success.textContent.trim(),false);
    } catch (error) {
        if (configuringKopia) {
            kopiaInstallInProgress = false;
            setWizardTransitionLock(false);
            if (button) {
                button.removeAttribute('aria-busy');
                setButtonBusy(button, false);
            }
        }

        if (everlompWizardStep === 7) {
            const detail = error && error.message ? String(error.message) : '';
            showWizardNotice(
                configuringKopia
                    ? ('Kopia installation did not return a complete response.' + (detail ? ' ' + detail : ''))
                    : ('Backup settings could not be saved.' + (detail ? ' ' + detail : '')),
                true
            );
        } else {
            console.error('Delayed backup request failed after leaving Step 7:', error);
        }
    }
}

function initializeDynamicControls(root = document) {
    const repositoryMode = root.querySelector ? root.querySelector('#kopia-repository-mode') : null;
    if (repositoryMode) updateKopiaRepositoryMode(repositoryMode);
    if (root.querySelectorAll) root.querySelectorAll('.schedule-mode-select').forEach(updateScheduleFields);
    const offsite = root.querySelector ? root.querySelector('#kopia-offsite-enabled') : null;
    if (offsite) toggleKopiaOffsite();
    const provider = root.querySelector ? root.querySelector('.offsite-provider-select') : null;
    if (provider) updateOffsiteProvider(provider);
    toggleDrupalSource(root);
    toggleDrupalGitAuth(root);
}

function escapeHtml(value) {
    return String(value).replace(/[&<>'"]/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[char]));
}

function initializeEverlompWizard() {
    const state = readWizardStateFrom(document);
    if (!state) return;
    everlompWizardState = state;
    loadWizardSummary();
    captureVisibleWizardDetails(document);
    if (state.filegator) setWizardDecision('filegator-decided');
    if (state.hotpocket) setWizardDecision('hotpocket-decided');
    if (state.kopia) setWizardDecision('backup-decided');
    if (state.backupScheduleConfigured) setWizardDecision('backup-schedule-decided');
    let stored = Number(localStorage.getItem('everlomp-wizard-step') || state.suggestedStep || 1);
    if (!Number.isFinite(stored) || stored < 1 || stored > 9) stored = state.suggestedStep || 1;
    const maxAllowed = wizardMaxAllowedStep();
    if (stored > maxAllowed) stored = maxAllowed;
    if (stored < Number(state.suggestedStep || 1)) stored = Math.min(Number(state.suggestedStep || 1), maxAllowed);
    showWizardStep(stored, true);
}

document.addEventListener('input', (event) => {
    const field = event.target;
    if (!field || !field.closest || !field.closest('#wizard-root')) return;
    captureWizardField(field);
});
document.addEventListener('change', (event) => {
    const field = event.target;
    if (!field || !field.closest || !field.closest('#wizard-root')) return;
    captureWizardField(field);
});
document.addEventListener('change', (event) => {
    const field = event.target;
    if (!(field instanceof HTMLInputElement) || field.type !== 'checkbox') return;

    const addonNames = new Set([
        'wp_theme_install_local[]',
        'wp_plugin_install_local[]',
        'wp_theme_activate[]',
        'wp_plugin_activate[]',
    ]);

    if (addonNames.has(field.name) && field.checked) {
        const includeName = field.name.startsWith('wp_theme_') ? 'wp_themes[]' : 'wp_plugins[]';
        const include = Array.from(document.querySelectorAll(`input[name="${includeName}"]`))
            .find((input) => input.value === field.value);
        if (include) include.checked = true;
    }

    if (field.name === 'wp_theme_activate[]' && field.checked) {
        document.querySelectorAll('input[name="wp_theme_activate[]"]').forEach((other) => {
            if (other !== field) other.checked = false;
        });
    }

    if ((field.name === 'wp_themes[]' || field.name === 'wp_plugins[]') && !field.checked) {
        const prefix = field.name === 'wp_themes[]' ? 'wp_theme_' : 'wp_plugin_';
        document.querySelectorAll(`input[name="${prefix}install_local[]"], input[name="${prefix}activate[]"]`).forEach((option) => {
            if (option.value === field.value) option.checked = false;
        });
    }
});
document.addEventListener('click', (event) => {
    const button = event.target && event.target.closest ? event.target.closest('#wizard-root button') : null;
    if (!button || button.disabled || button.hasAttribute('data-summary-control') || button.closest('.wizard-summary-panel')) return;
    const step = button.closest('.wizard-panel')?.dataset.step || everlompWizardStep;
    recordWizardAction(button.textContent || button.getAttribute('aria-label') || 'Button', step);
});
document.addEventListener('submit', submitWizardForm);
document.addEventListener('submit', submitAppInstaller);
document.addEventListener('submit', submitBackupForm);
document.addEventListener('DOMContentLoaded', initializeEverlompWizard);



const everlompInitialKeyStatus = <?= json_encode($keyStatus, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
const everlompKeyReplaceCsrf = <?= json_encode($keyReplaceCsrf, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
let everlompKeyWaitOldInstance = String(everlompInitialKeyStatus.instance || '');
let everlompKeyStableReadyCount = 0;
let everlompKeyPollTimer = null;
let everlompKeyManualRestartConfirmed = false;
let everlompKeyEnabling = false;

async function setEverlompKeyMode(mode) {
    const data = new FormData();
    data.set('action', 'key_set_mode');
    data.set('mode', mode);
    const response = await fetch(window.location.pathname, {method:'POST', body:data, credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
    const payload = await response.json().catch(() => ({}));
    if (!response.ok || !payload || payload.ok !== true) throw new Error(payload.message || payload.key_error || 'Could not save the encryption choice.');
    return payload;
}

function validEverlompKeyText(value) {
    const text = String(value || '').trim();
    return /^[A-Za-z0-9_-]{43}=?$/.test(text) || /^[0-9A-Fa-f]{64}$/.test(text);
}

function generateEverlompKey() {
    const field = document.getElementById('everlomp-key-value');
    const error = document.getElementById('key-entry-error');
    if (!field || !window.crypto || !window.crypto.getRandomValues) {
        if (error) { error.textContent='This browser cannot securely generate a key. Paste an existing 32-byte key instead.'; error.classList.remove('hidden'); }
        return;
    }
    const bytes = new Uint8Array(32);
    window.crypto.getRandomValues(bytes);
    let binary = '';
    bytes.forEach((b) => { binary += String.fromCharCode(b); });
    field.value = btoa(binary).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');
    field.type = 'text';
    const toggle = document.getElementById('everlomp-key-toggle');
    if (toggle) toggle.textContent = 'Hide key';
    if (error) error.classList.add('hidden');
}

async function copyEverlompKey(button) {
    const field = document.getElementById('everlomp-key-value');
    const error = document.getElementById('key-entry-error');
    if (!field || !validEverlompKeyText(field.value)) {
        if (error) { error.textContent='Enter or generate a valid 32-byte key first.'; error.classList.remove('hidden'); }
        return;
    }
    try {
        await navigator.clipboard.writeText(field.value.trim());
        if (button) { const old=button.textContent; button.textContent='Copied'; setTimeout(()=>button.textContent=old,1400); }
    } catch (_) {
        field.type='text'; field.focus(); field.select();
    }
}

function toggleEverlompKeyVisibility() {
    const field=document.getElementById('everlomp-key-value');
    const button=document.getElementById('everlomp-key-toggle');
    if (!field) return;
    field.type = field.type === 'password' ? 'text' : 'password';
    if (button) button.textContent = field.type === 'password' ? 'Show key' : 'Hide key';
}

function prepareEverlompKeyMode() {
    const panel=document.getElementById('key-entry-panel');
    const error=document.getElementById('key-entry-error');
    if (panel) panel.classList.remove('hidden');
    if (error) error.classList.add('hidden');
    if (!document.getElementById('everlomp-key-value')?.value) generateEverlompKey();
}

async function disableEverlompKeyMode() {
    try {
        await setEverlompKeyMode('disabled');
        window.location.reload();
    } catch (e) { alert(e.message || 'Could not disable encryption mode.'); }
}

async function enableMountedEverlompKey() {
    try {
        await setEverlompKeyMode('enabled');
        window.location.reload();
    } catch (e) { alert(e.message || 'Could not enable the mounted key.'); }
}

async function beginEverlompRestartWait() {
    const field=document.getElementById('everlomp-key-value');
    const error=document.getElementById('key-entry-error');
    const panel=document.getElementById('key-entry-panel');
    if (!field || !validEverlompKeyText(field.value)) {
        if (error) { error.textContent='Generate or paste a valid 32-byte key before continuing.'; error.classList.remove('hidden'); }
        return;
    }
    try {
        await setEverlompKeyMode('pending');
        everlompKeyWaitOldInstance = String(everlompInitialKeyStatus.instance || everlompKeyWaitOldInstance || '');
        if (panel) {
            panel.innerHTML = '<div class="wizard-divider"></div><h3>Now save the key in Evernode</h3>'
                + '<p>Set the Docker Swarm secret named <code>key</code> to the value you just copied. Saving it should replace/restart this container. Keep this tab open; Everlomp will wait as long as necessary.</p>'
                + '<div class="key-wait-list">'
                + '<div id="key-wait-submitted" class="key-wait-item good"><span>✓</span><span>Encryption mode marked as pending</span></div>'
                + '<div id="key-wait-restart" class="key-wait-item active"><span class="key-spinner"></span><span>Waiting for a new container instance…</span></div>'
                + '<div class="actions"><button type="button" id="key-replace-task-button" onclick="replaceEverlompSwarmTask(this)">I saved the key — replace container now</button><button type="button" style="margin-left:6px;" class="secondary" id="key-restart-confirm-button" onclick="confirmEverlompRestart(this)">It has restarted now — check</button></div>'
                + '<div id="key-wait-key" class="key-wait-item"><span>○</span><span>Waiting for <code>/run/secrets/key</code></span></div>'
                + '<div id="key-wait-ready" class="key-wait-item"><span>○</span><span>Waiting for Everlomp services</span></div>'
                + '</div><div id="key-gate-runtime-error" class="key-gate-error hidden"></div>';
        }
        everlompKeyStableReadyCount=0;
        clearTimeout(everlompKeyPollTimer);
        everlompKeyPollTimer=setTimeout(pollEverlompKeyRestart, 500);
    } catch (e) {
        if (error) { error.textContent=e.message || 'Could not enter key waiting mode.'; error.classList.remove('hidden'); }
    }
}

function setKeyWaitRow(id, state, html) {
    const row=document.getElementById(id); if (!row) return;
    row.classList.remove('good','active');
    if (state) row.classList.add(state);
    row.innerHTML = (state === 'active' ? '<span class="key-spinner"></span>' : (state === 'good' ? '<span>✓</span>' : '<span>○</span>')) + '<span>' + html + '</span>';
}

async function replaceEverlompSwarmTask(button) {
    if (!window.confirm('Use this only after you saved the new key secret in Evernode. The current container will stop and this page will disconnect briefly while Docker Swarm creates a fresh task. Continue?')) return;

    const runtimeError=document.getElementById('key-gate-runtime-error');
    if (runtimeError) runtimeError.classList.add('hidden');
    if (button) { button.disabled=true; button.textContent='Requesting task replacement…'; }
    setKeyWaitRow('key-wait-restart','active','Requesting a fresh Docker Swarm task…');

    try {
        const body=new URLSearchParams();
        body.set('action','key_replace_task');
        body.set('csrf',everlompKeyReplaceCsrf);
        const response=await fetch(window.location.pathname, {
            method:'POST',
            credentials:'same-origin',
            cache:'no-store',
            headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'},
            body:body.toString()
        });
        let payload={};
        try { payload=await response.json(); } catch (_) {}
        if (!response.ok || payload.ok === false) throw new Error(payload.message || 'Could not request the Swarm task replacement.');

        if (payload.replacement_needed === false) {
            setKeyWaitRow('key-wait-key','good','Docker Swarm key is already mounted and valid');
            everlompKeyManualRestartConfirmed=true;
        } else {
            setKeyWaitRow('key-wait-restart','active','Task replacement requested — waiting for this container to stop…');
        }
        everlompKeyStableReadyCount=0;
        clearTimeout(everlompKeyPollTimer);
        everlompKeyPollTimer=setTimeout(pollEverlompKeyRestart, 1200);
    } catch (e) {
        if (button) { button.disabled=false; button.textContent='I saved the key — replace container now'; }
        if (runtimeError) { runtimeError.textContent=e.message || 'Could not request a new Swarm task.'; runtimeError.classList.remove('hidden'); }
        setKeyWaitRow('key-wait-restart','active','Waiting for a new container instance…');
    }
}

function confirmEverlompRestart(button) {
    everlompKeyManualRestartConfirmed = true;
    everlompKeyStableReadyCount = 0;
    if (button) {
        button.disabled = true;
        button.textContent = 'Restart confirmed — checking…';
    }
    setKeyWaitRow('key-wait-restart','good','Restart confirmed manually; verifying this container');
    clearTimeout(everlompKeyPollTimer);
    everlompKeyPollTimer=setTimeout(pollEverlompKeyRestart, 50);
}

async function pollEverlompKeyRestart() {
    const gate=document.getElementById('everlomp-key-gate');
    if (!gate) return;
    try {
        const response=await fetch(window.location.pathname + '?key_status=1&_=' + Date.now(), {cache:'no-store',credentials:'same-origin'});
        const status=await response.json();
        const instance=String(status.instance || '');
        const newInstance=!!instance && !!everlompKeyWaitOldInstance && instance !== everlompKeyWaitOldInstance;
        const restartConfirmed=newInstance || everlompKeyManualRestartConfirmed;

        if (newInstance) {
            setKeyWaitRow('key-wait-restart','good','New container instance detected automatically');
            const manualButton=document.getElementById('key-restart-confirm-button');
            if (manualButton) { manualButton.disabled=true; manualButton.textContent='Restart detected automatically'; }
        } else if (everlompKeyManualRestartConfirmed) {
            setKeyWaitRow('key-wait-restart','good','Restart confirmed manually; verifying this container');
        } else {
            setKeyWaitRow('key-wait-restart','active','Waiting for a new container instance…');
        }

        if (restartConfirmed && status.key_valid) {
            setKeyWaitRow('key-wait-key','good','Docker Swarm key is mounted and valid');
        } else if (restartConfirmed) {
            setKeyWaitRow('key-wait-key','active','Waiting for <code>/run/secrets/key</code>…');
        } else {
            setKeyWaitRow('key-wait-key','','Waiting for restart confirmation first');
        }

        if (restartConfirmed && status.key_valid && status.ready) {
            setKeyWaitRow('key-wait-ready','good','Baseline Everlomp services are healthy');
            everlompKeyStableReadyCount++;
        } else {
            setKeyWaitRow('key-wait-ready',restartConfirmed ? 'active' : '',restartConfirmed ? 'Waiting for Everlomp services…' : 'Waiting for restart confirmation first');
            everlompKeyStableReadyCount=0;
        }

        const runtimeError=document.getElementById('key-gate-runtime-error');
        if (runtimeError) {
            if (restartConfirmed && !status.key_valid && status.key_error) {
                const placeholder = String(status.key_error).toLowerCase().includes('placeholder');
                runtimeError.textContent = placeholder
                    ? 'This task restarted, but /run/secrets/key is still Evernode\'s placeholder. Make sure you saved the new key in Evernode, then use “I saved the key — replace container now” to force a fresh Swarm task.'
                    : status.key_error;
                runtimeError.classList.remove('hidden');
                const replaceButton=document.getElementById('key-replace-task-button');
                if (placeholder && replaceButton) { replaceButton.disabled=false; replaceButton.textContent='I saved the key — replace container now'; }
            } else runtimeError.classList.add('hidden');
        }

        if (restartConfirmed && status.key_valid && status.ready && everlompKeyStableReadyCount >= 3) {
            if (status.mode === 'pending' && !everlompKeyEnabling) {
                everlompKeyEnabling=true;
                try {
                    await setEverlompKeyMode('enabled');
                } catch (e) {
                    everlompKeyEnabling=false;
                    if (runtimeError) { runtimeError.textContent=e.message || 'The mounted key could not be enabled.'; runtimeError.classList.remove('hidden'); }
                    everlompKeyPollTimer=setTimeout(pollEverlompKeyRestart, 3000);
                    return;
                }
            }
            clearTimeout(everlompKeyPollTimer);
            window.location.reload();
            return;
        }
    } catch (_) {
        everlompKeyStableReadyCount=0;
    }
    everlompKeyPollTimer=setTimeout(pollEverlompKeyRestart, 3000);
}

if (everlompInitialKeyStatus.mode === 'pending' || (everlompInitialKeyStatus.mode === 'enabled' && !everlompInitialKeyStatus.key_valid)) {
    document.addEventListener('DOMContentLoaded', () => { everlompKeyPollTimer=setTimeout(pollEverlompKeyRestart, 1000); });
}

</script>
</body>
</html>
