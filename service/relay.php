<?php

/** Standalone SSE relay. PHP CLI only; no GLPI bootstrap, database or extensions. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(1);
}

function nb_decode(string $value): string|false
{
    if (!preg_match('/^[A-Za-z0-9_-]+$/D', $value)) {
        return false;
    }
    return base64_decode(strtr($value, '-_', '+/'), true);
}

/** @return array{exp:int,channels:list<string>}|null */
function nb_claims(?string $token, string $secret): ?array
{
    if ($token === null || strlen($token) > 16384 || substr_count($token, '.') !== 1) {
        return null;
    }
    [$payload, $signature] = explode('.', $token, 2);
    $given = nb_decode($signature);
    $json = nb_decode($payload);
    if ($given === false || $json === false
        || !hash_equals(hash_hmac('sha256', $payload, $secret, true), $given)) {
        return null;
    }
    $claims = json_decode($json, true);
    $now = time();
    if (!is_array($claims) || !isset($claims['exp'], $claims['channels'])
        || !is_int($claims['exp']) || $claims['exp'] <= $now || $claims['exp'] > $now + 125
        || !is_array($claims['channels']) || !array_is_list($claims['channels'])
        || count($claims['channels']) < 1 || count($claims['channels']) > 1000) {
        return null;
    }
    foreach ($claims['channels'] as $channel) {
        if (!is_string($channel) || !preg_match('/^[gu]_[1-9][0-9]*$/D', $channel)) {
            return null;
        }
    }
    return $claims;
}

/** @param array<string,mixed> $client */
function nb_reply(array &$client, int $status, string $reason): void
{
    $client['out'] = "HTTP/1.1 $status $reason\r\nContent-Length: 0\r\nCache-Control: no-store\r\nConnection: close\r\n\r\n";
    $client['closing'] = true;
}

/** @param array<string,mixed> $client */
function nb_request(array &$client, string $origin, string $secret, int $active): void
{
    [$headers] = explode("\r\n\r\n", $client['in'], 2);
    $lines = explode("\r\n", $headers);
    $request = array_shift($lines);
    if (!preg_match('~^GET ([^ ]+) HTTP/1\.[01]$~D', $request, $match)) {
        nb_reply($client, 404, 'Not Found');
        return;
    }
    $uri = parse_url($match[1]);
    if (!is_array($uri) || ($uri['path'] ?? '') !== '/events') {
        nb_reply($client, 404, 'Not Found');
        return;
    }
    $origins = [];
    foreach ($lines as $line) {
        if (preg_match('/^Origin:[ \t]*(.*)$/iD', $line, $value)) {
            $origins[] = trim($value[1]);
        }
    }
    if (count($origins) > 1 || (count($origins) === 1 && $origins[0] !== $origin) || $active >= 1024) {
        nb_reply($client, 403, 'Forbidden');
        return;
    }
    parse_str($uri['query'] ?? '', $query);
    $claims = nb_claims(is_string($query['token'] ?? null) ? $query['token'] : null, $secret);
    if ($claims === null) {
        nb_reply($client, 401, 'Unauthorized');
        return;
    }
    $client['channels'] = array_fill_keys($claims['channels'], true);
    $client['exp'] = $claims['exp'];
    $client['live'] = true;
    $client['out'] = "HTTP/1.1 200 OK\r\nContent-Type: text/event-stream\r\nCache-Control: no-store\r\nConnection: keep-alive\r\nX-Accel-Buffering: no\r\nX-Content-Type-Options: nosniff\r\n\r\nretry: 3000\n: connected\n\n";
    $client['in'] = '';
}

$secret = getenv('NB_SECRET') ?: '';
$stateDir = str_replace('\\', '/', getenv('NB_STATE_DIR') ?: '');
$origin = getenv('NB_ORIGIN') ?: '';
$port = getenv('NB_PORT') ?: '8091';
if (strlen($secret) < 32 || str_starts_with($secret, 'REPLACE_')
    || !(str_starts_with($stateDir, '/') || (PHP_OS_FAMILY === 'Windows' && preg_match('~^[a-z]:/~i', $stateDir)))
    || !is_dir($stateDir) || !is_readable($stateDir)
    || !preg_match('~^https?://[^/?#@]+$~D', $origin)
    || !ctype_digit($port) || (int) $port > 65535 || (int) $port < 1) {
    fwrite(STDERR, "notificationbell: invalid relay configuration\n");
    exit(1);
}
$server = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
if ($server === false) {
    fwrite(STDERR, "notificationbell: cannot listen on loopback\n");
    exit(1);
}
stream_set_blocking($server, false);
fwrite(STDOUT, "notificationbell: realtime relay ready on loopback\n");

$clients = [];
$revisions = [];
$lastPoll = 0.0;
$lastHeartbeat = microtime(true);
while (true) {
    $read = [$server];
    $write = [];
    foreach ($clients as $client) {
        $read[] = $client['socket'];
        if ($client['out'] !== '') {
            $write[] = $client['socket'];
        }
    }
    $except = null;
    if (@stream_select($read, $write, $except, 0, 200000) === false) {
        continue;
    }
    if (in_array($server, $read, true)) {
        $socket = @stream_socket_accept($server, 0);
        if ($socket !== false) {
            stream_set_blocking($socket, false);
            if (count($clients) >= 1152) {
                fclose($socket);
            } else {
                $clients[(int) $socket] = [
                    'socket' => $socket, 'in' => '', 'out' => '', 'created' => microtime(true),
                    'live' => false, 'closing' => false, 'exp' => 0, 'channels' => [],
                ];
            }
        }
    }
    foreach ($read as $socket) {
        if ($socket === $server || !isset($clients[(int) $socket])) {
            continue;
        }
        $id = (int) $socket;
        $data = @fread($socket, 4096);
        if ($data === false || $data === '' && feof($socket)) {
            fclose($socket);
            unset($clients[$id]);
            continue;
        }
        if ($clients[$id]['live'] || $clients[$id]['closing']) {
            continue;
        }
        $clients[$id]['in'] .= $data;
        if (strlen($clients[$id]['in']) > 16384) {
            nb_reply($clients[$id], 431, 'Request Header Fields Too Large');
        } elseif (str_contains($clients[$id]['in'], "\r\n\r\n")) {
            $active = count(array_filter($clients, static fn(array $client): bool => $client['live']));
            nb_request($clients[$id], $origin, $secret, $active);
        }
    }
    foreach ($write as $socket) {
        $id = (int) $socket;
        if (!isset($clients[$id])) {
            continue;
        }
        $count = @fwrite($socket, $clients[$id]['out']);
        if ($count === false) {
            fclose($socket);
            unset($clients[$id]);
            continue;
        }
        $clients[$id]['out'] = substr($clients[$id]['out'], $count);
        if ($clients[$id]['out'] === '' && $clients[$id]['closing']) {
            fclose($socket);
            unset($clients[$id]);
        }
    }
    $now = microtime(true);
    foreach ($clients as $id => $client) {
        if (($client['live'] && ($client['exp'] <= time() || strlen($client['out']) > 32768))
            || (!$client['live'] && $now - $client['created'] > 5)) {
            fclose($client['socket']);
            unset($clients[$id]);
        }
    }
    if ($now - $lastPoll >= 1) {
        $lastPoll = $now;
        $channels = [];
        foreach ($clients as $client) {
            if ($client['live']) {
                $channels += $client['channels'];
            }
        }
        if ($channels !== [] && !is_readable($stateDir)) {
            foreach ($clients as $id => $client) {
                fclose($client['socket']);
                unset($clients[$id]);
            }
            fwrite(STDERR, "notificationbell: revision storage unavailable\n");
            continue;
        }
        $changed = [];
        foreach ($channels as $channel => $_) {
            $filename = $stateDir . '/' . $channel;
            $revision = is_file($filename) ? @file_get_contents($filename) : '';
            if ($revision === false) {
                $revision = '';
            }
            if (!array_key_exists($channel, $revisions) || $revision !== $revisions[$channel]) {
                $changed[$channel] = true;
            }
            $revisions[$channel] = $revision;
        }
        $revisions = array_intersect_key($revisions, $channels);
        foreach ($clients as &$client) {
            if ($client['live'] && array_intersect_key($client['channels'], $changed) !== []) {
                $client['out'] .= "event: changed\ndata: {}\n\n";
            }
        }
        unset($client);
    }
    if ($now - $lastHeartbeat >= 15) {
        $lastHeartbeat = $now;
        foreach ($clients as &$client) {
            if ($client['live']) {
                $client['out'] .= ": heartbeat\n\n";
            }
        }
        unset($client);
    }
}
