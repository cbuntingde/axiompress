<?php

declare(strict_types=1);

// Operator-only. Creates a new SELECT-only identity/view; never bootstraps executable WordPress code.
if (PHP_SAPI !== 'cli' || PHP_OS_FAMILY !== 'Linux' || getenv('AXIOM_SETUP_LOCAL') !== '1') {
    throw new RuntimeException('Explicit local operator setup required.');
}
$root = '/var/www';
$cache = $root . '/.cache/axiompress-native';
if (file_exists($cache) || is_link($cache)) {
    throw new RuntimeException('Native setup already exists; reuse its private configuration.');
}
$nonce = bin2hex(random_bytes(6));
$password = bin2hex(random_bytes(32));
$user = 'axread_' . $nonce;
$view = 'axiompress_public_' . $nonce;
// This operator tool is deliberately restricted to the repository's disposable local development database.
$db = new mysqli('mysql', 'root', (string) getenv('AXIOM_LOCAL_ROOT_PASSWORD'), 'wordpress_develop');
$db->set_charset('utf8mb4');
$stage = $cache . '.setup-' . $nonce;
$viewCreated = false;
$userCreated = false;
$published = false;
$mask = umask(0077);
try {
    $db->query('CREATE SQL SECURITY DEFINER VIEW `' . $view . '` AS
        SELECT ID, post_title, post_content, post_status, post_password, post_type, post_modified_gmt, post_date_gmt
        FROM wp_posts WHERE post_status = \'publish\' AND post_password = \'\'
            AND post_type IN (\'post\', \'page\') AND post_date_gmt <= UTC_TIMESTAMP()');
    $viewCreated = true;
    $db->query("CREATE USER '$user'@'%' IDENTIFIED BY '$password'");
    $userCreated = true;
    $db->query("GRANT SELECT ON wordpress_develop.`$view` TO '$user'@'%'");
    if (!mkdir($stage, 0700, true)) {
        throw new RuntimeException('Native secret directory could not be created.');
    }
    if (file_put_contents($stage . '/password', $password) !== strlen($password)) {
        throw new RuntimeException('Native secret could not be written.');
    }
    if (!chown($stage . '/password', 10001) || !chmod($stage . '/password', 0400)) {
        throw new RuntimeException('Native secret ownership could not be restricted.');
    }
    $configuration = array('AXIOM_DB_HOST' => 'mysql', 'AXIOM_DB_PORT' => '3306',
        'AXIOM_DB_NAME' => 'wordpress_develop', 'AXIOM_DB_USER' => $user,
        'AXIOM_DB_PASSWORD_FILE' => '/run/secrets/native-db', 'AXIOM_PUBLIC_VIEW' => $view, 'AXIOM_SITE_ID' => '1');
    $environment = '';
    foreach ($configuration as $key => $value) {
        $environment .= $key . '=' . $value . "\n";
    }
    if (file_put_contents($stage . '/native.env', $environment) !== strlen($environment)) {
        throw new RuntimeException('Native environment could not be written.');
    }
    if (!rename($stage, $cache)) {
        throw new RuntimeException('Native configuration could not be published.');
    }
    $published = true;
} finally {
    try {
        if (!$published) {
            // Only objects created by this attempt are removed. Existing CMS data is never modified.
            if ($userCreated) {
                $db->query("DROP USER '$user'@'%'");
            }
            if ($viewCreated) {
                $db->query('DROP VIEW `' . $view . '`');
            }
            foreach (array('password', 'native.env') as $file) {
                if (is_file($stage . '/' . $file) && !unlink($stage . '/' . $file)) {
                    throw new RuntimeException('Failed setup secret cleanup.');
                }
            }
            if (is_dir($stage) && !rmdir($stage)) {
                throw new RuntimeException('Failed setup directory cleanup.');
            }
        }
    } finally {
        umask($mask);
        $db->close();
    }
}
echo "Created local public-content view and SELECT-only identity; credentials remain in ignored .cache.\n";
