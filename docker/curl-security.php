<?php
// Retain the normal private-network blocks except the two local integration addresses.
function moodle_docker_blocked_hosts(): string {
    $allowed = [];
    foreach (['torus.test', 'moodle.test'] as $host) {
        foreach (gethostbynamel($host) ?: [] as $ip) {
            $allowed[] = ip2long($ip);
        }
    }
    $exclude = function (int $network, int $prefix) use (&$exclude, $allowed): array {
        $size = 2 ** (32 - $prefix);
        foreach ($allowed as $ip) {
            if ($ip >= $network && $ip < $network + $size) {
                if ($prefix === 32) {
                    return [];
                }
                return array_merge(
                    $exclude($network, $prefix + 1),
                    $exclude($network + (int)($size / 2), $prefix + 1),
                );
            }
        }
        return [long2ip($network) . '/' . $prefix];
    };
    $blocked = ['127.0.0.0/8', '0.0.0.0', 'localhost', '169.254.169.254', '::1'];
    foreach (['10.0.0.0' => 8, '172.16.0.0' => 12, '192.168.0.0' => 16] as $ip => $prefix) {
        $blocked = array_merge($blocked, $exclude(ip2long($ip), $prefix));
    }
    return implode("\n", $blocked);
}
