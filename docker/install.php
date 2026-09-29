<?php
// Check the database directly: loading Moodle before installation may redirect or exit.
$db = new PDO(
    'pgsql:host=' . getenv('MOODLE_DB_HOST') . ';dbname=' . getenv('MOODLE_DB_NAME'),
    getenv('MOODLE_DB_USER'),
    getenv('MOODLE_DB_PASSWORD'),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$tables = $db->query("SELECT count(*) FROM pg_tables WHERE schemaname = 'public'")->fetchColumn();
if ((int)$tables === 0) {
    $arguments = [
        PHP_BINARY, 'admin/cli/install_database.php', '--agree-license',
        '--fullname=Local Moodle', '--shortname=local',
        '--adminuser=' . getenv('MOODLE_ADMIN_USER'),
        '--adminpass=' . getenv('MOODLE_ADMIN_PASSWORD'),
        '--adminemail=' . getenv('MOODLE_ADMIN_EMAIL'),
    ];
    $process = proc_open($arguments, [STDIN, STDOUT, STDERR], $pipes);
    exit(proc_close($process));
}
// A failed partial installation must not be mistaken for a working site.
if (!$db->query("SELECT to_regclass('public.mdl_config')")->fetchColumn()) {
    fwrite(STDERR, "Incomplete Moodle installation. See docker/README.md for recovery.\n");
    exit(1);
}
$installed = $db->query("SELECT value FROM mdl_config WHERE name = 'version'")->fetchColumn();
$admin = $db->query("SELECT value FROM mdl_config WHERE name = 'siteadmins'")->fetchColumn();
if (!$installed || !$admin) {
    fwrite(STDERR, "Incomplete Moodle installation. See docker/README.md for recovery.\n");
    exit(1);
}
echo "Moodle is already installed; keeping existing accounts and data.\n";
