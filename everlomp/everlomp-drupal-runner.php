<?php
declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: everlomp-drupal-runner.php <install|verify> <drupal-root>\n");
    exit(64);
}

$mode = (string) $argv[1];
$root = realpath((string) $argv[2]);
if ($root === false || !is_file($root . '/autoload.php') || !is_file($root . '/core/includes/install.core.inc')) {
    fwrite(STDERR, "Invalid Drupal root.\n");
    exit(65);
}

chdir($root);

if ($mode === 'install') {
    $required = [
        'EVERLOMP_DRUPAL_SITE_NAME',
        'EVERLOMP_DRUPAL_ADMIN_USER',
        'EVERLOMP_DRUPAL_ADMIN_EMAIL',
        'EVERLOMP_DRUPAL_ADMIN_PASSWORD',
        'EVERLOMP_DRUPAL_DB_HOST',
        'EVERLOMP_DRUPAL_DB_NAME',
        'EVERLOMP_DRUPAL_DB_USER',
        'EVERLOMP_DRUPAL_DB_PASSWORD',
    ];
    foreach ($required as $name) {
        if (getenv($name) === false) {
            fwrite(STDERR, "Missing environment variable: {$name}\n");
            exit(66);
        }
    }

    if (!defined('MAINTENANCE_MODE')) {
        define('MAINTENANCE_MODE', 'install');
    }

    $classLoader = require $root . '/autoload.php';
    require_once $root . '/core/includes/errors.inc';
    set_error_handler('_drupal_error_handler');
    set_exception_handler('_drupal_exception_handler');
    require_once $root . '/core/includes/install.core.inc';

    $driverNamespace = 'Drupal\\mysql\\Driver\\Database\\mysql';
    $port = trim((string) (getenv('EVERLOMP_DRUPAL_DB_PORT') ?: ''));
    $dbForm = [
        'database' => (string) getenv('EVERLOMP_DRUPAL_DB_NAME'),
        'username' => (string) getenv('EVERLOMP_DRUPAL_DB_USER'),
        'password' => (string) getenv('EVERLOMP_DRUPAL_DB_PASSWORD'),
        'prefix' => '',
        'host' => (string) getenv('EVERLOMP_DRUPAL_DB_HOST'),
        'port' => $port,
    ];

    $settings = [
        'interactive' => false,
        'parameters' => [
            'profile' => 'standard',
            'langcode' => 'en',
        ],
        'forms' => [
            'install_settings_form' => [
                'driver' => $driverNamespace,
                $driverNamespace => $dbForm,
            ],
            'install_configure_form' => [
                'site_name' => (string) getenv('EVERLOMP_DRUPAL_SITE_NAME'),
                'site_mail' => (string) getenv('EVERLOMP_DRUPAL_ADMIN_EMAIL'),
                'account' => [
                    'name' => (string) getenv('EVERLOMP_DRUPAL_ADMIN_USER'),
                    'mail' => (string) getenv('EVERLOMP_DRUPAL_ADMIN_EMAIL'),
                    'pass' => [
                        'pass1' => (string) getenv('EVERLOMP_DRUPAL_ADMIN_PASSWORD'),
                        'pass2' => (string) getenv('EVERLOMP_DRUPAL_ADMIN_PASSWORD'),
                    ],
                ],
                'enable_update_status_module' => null,
                'enable_update_status_emails' => null,
            ],
        ],
    ];

    install_drupal($classLoader, $settings);
    fwrite(STDOUT, "Drupal installation API completed.\n");
    exit(0);
}

if ($mode === 'verify') {
    $siteUrl = (string) (getenv('EVERLOMP_DRUPAL_SITE_URL') ?: 'https://localhost');
    $classLoader = require $root . '/autoload.php';

    $request = Symfony\Component\HttpFoundation\Request::create($siteUrl . '/', 'GET');
    $kernel = Drupal\Core\DrupalKernel::createFromRequest($request, $classLoader, 'prod');
    $kernel->boot();
    $container = $kernel->getContainer();
    $database = $container->get('database');
    $value = $database->query('SELECT 1')->fetchField();
    if ((string) $value !== '1') {
        fwrite(STDERR, "Drupal database bootstrap check failed.\n");
        exit(67);
    }
    $siteName = $container->get('config.factory')->get('system.site')->get('name');
    if (!is_string($siteName) || trim($siteName) === '') {
        fwrite(STDERR, "Drupal configuration bootstrap check failed.\n");
        exit(68);
    }
    $kernel->shutdown();
    fwrite(STDOUT, "Drupal bootstrap verified.\n");
    exit(0);
}

fwrite(STDERR, "Unknown mode: {$mode}\n");
exit(64);
