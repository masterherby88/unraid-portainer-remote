<?php
/** Small, server-side Portainer API client. No API key is sent to the browser. */

function pr_config_path() {
    return '/boot/config/plugins/portainer.remote/portainer.remote.cfg';
}

function pr_defaults() {
    return array(
        'portainer_url' => '',
        'api_key' => '',
        'endpoint_id' => '',
        'container_name' => 'iobroker',
        'verify_tls' => 'yes',
        'refresh_seconds' => '30'
    );
}

function pr_load_config() {
    $cfg = pr_defaults();
    $path = pr_config_path();
    if (is_file($path)) {
        $loaded = @parse_ini_file($path, false, INI_SCANNER_RAW);
        if (is_array($loaded)) $cfg = array_merge($cfg, $loaded);
    }
    return $cfg;
}

function pr_clean_string($value, $max = 2048) {
    $value = trim((string)$value);
    $value = str_replace(array("\0", "\r", "\n"), '', $value);
    return substr($value, 0, $max);
}

function pr_validate_url($url) {
    $url = rtrim(pr_clean_string($url, 500), '/');
    $parts = @parse_url($url);
    if (!$parts || empty($parts['scheme']) || empty($parts['host']) ||
        !in_array(strtolower($parts['scheme']), array('http', 'https'), true) ||
        isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
        throw new RuntimeException('Bitte eine gültige Portainer-Basis-URL mit http:// oder https:// angeben.');
    }
    return $url;
}

function pr_validate_config($cfg, $require_endpoint = true, $require_key = true) {
    $cfg['portainer_url'] = pr_validate_url(isset($cfg['portainer_url']) ? $cfg['portainer_url'] : '');
    $cfg['api_key'] = pr_clean_string(isset($cfg['api_key']) ? $cfg['api_key'] : '', 1024);
    $cfg['endpoint_id'] = pr_clean_string(isset($cfg['endpoint_id']) ? $cfg['endpoint_id'] : '', 12);
    $cfg['container_name'] = pr_clean_string(isset($cfg['container_name']) ? $cfg['container_name'] : '', 128);
    $cfg['verify_tls'] = (isset($cfg['verify_tls']) && $cfg['verify_tls'] === 'no') ? 'no' : 'yes';
    $cfg['refresh_seconds'] = (string)max(10, min(300, (int)(isset($cfg['refresh_seconds']) ? $cfg['refresh_seconds'] : 30)));
    if ($require_key && $cfg['api_key'] === '') throw new RuntimeException('API-Schlüssel fehlt.');
    if ($require_endpoint && (!ctype_digit($cfg['endpoint_id']) || (int)$cfg['endpoint_id'] < 1)) {
        throw new RuntimeException('Portainer-Environment-ID muss eine positive Zahl sein.');
    }
    if ($require_endpoint && $cfg['container_name'] === '') throw new RuntimeException('Docker-Containername fehlt.');
    return $cfg;
}

function pr_save_config($cfg) {
    $dir = dirname(pr_config_path());
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('Plugin-Konfigurationsverzeichnis konnte nicht erstellt werden.');
    $lines = array('# Portainer Remote settings; API key is stored locally on the Unraid flash drive.');
    foreach (array('portainer_url', 'api_key', 'endpoint_id', 'container_name', 'verify_tls', 'refresh_seconds') as $key) {
        $value = isset($cfg[$key]) ? (string)$cfg[$key] : '';
        $value = str_replace(array('\\', '"'), array('\\\\', '\\"'), $value);
        $lines[] = $key . '="' . $value . '"';
    }
    $path = pr_config_path();
    $tmp = $path . '.tmp';
    if (@file_put_contents($tmp, implode("\n", $lines) . "\n", LOCK_EX) === false) throw new RuntimeException('Konfiguration konnte nicht geschrieben werden.');
    @chmod($tmp, 0600);
    if (!@rename($tmp, $path)) { @unlink($tmp); throw new RuntimeException('Konfiguration konnte nicht atomar gespeichert werden.'); }
    @chmod($path, 0600);
}

function pr_api_request($cfg, $method, $path, $raw = false) {
    if (!function_exists('curl_init')) throw new RuntimeException('PHP-cURL ist auf diesem Unraid-System nicht verfügbar.');
    $url = rtrim($cfg['portainer_url'], '/') . '/api' . $path;
    $verify = isset($cfg['verify_tls']) && $cfg['verify_tls'] !== 'no';
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => array('X-API-Key: ' . $cfg['api_key'], 'Accept: application/json'),
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS
    ));
    $body = curl_exec($ch);
    $curl_error = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException('Portainer ist nicht erreichbar: ' . ($curl_error !== '' ? $curl_error : 'Verbindungsfehler'));
    if ($http < 200 || $http >= 300) {
        $messages = array(401 => 'API-Schlüssel wurde abgelehnt', 403 => 'Portainer verweigert den Zugriff auf diese Umgebung', 404 => 'Portainer-Environment oder Container wurde nicht gefunden');
        throw new RuntimeException((isset($messages[$http]) ? $messages[$http] : 'Portainer-API meldet einen Fehler') . ' (HTTP ' . $http . ').');
    }
    if ($raw) return $body;
    $decoded = json_decode($body, true);
    if (!is_array($decoded) && $http !== 204) throw new RuntimeException('Portainer lieferte keine gültige JSON-Antwort.');
    return $decoded;
}

function pr_endpoints($cfg) {
    $data = pr_api_request($cfg, 'GET', '/endpoints');
    $result = array();
    foreach ($data as $endpoint) {
        if (!isset($endpoint['Id'])) continue;
        $result[] = array('id' => (int)$endpoint['Id'], 'name' => isset($endpoint['Name']) ? (string)$endpoint['Name'] : ('Environment ' . $endpoint['Id']));
    }
    return $result;
}

function pr_container($cfg) {
    $endpoint = (int)$cfg['endpoint_id'];
    $containers = pr_api_request($cfg, 'GET', '/endpoints/' . $endpoint . '/docker/containers/json?all=1');
    $wanted = strtolower(ltrim($cfg['container_name'], '/'));
    $matches = array();
    foreach ($containers as $container) {
        foreach ((isset($container['Names']) && is_array($container['Names'])) ? $container['Names'] : array() as $name) {
            if (strtolower(ltrim((string)$name, '/')) === $wanted) { $matches[] = $container; break; }
        }
    }
    if (count($matches) > 1) throw new RuntimeException('Mehrere Container haben denselben Namen. Bitte den Namen eindeutiger wählen.');
    if (!$matches) return null;
    $container = $matches[0];
    $id = isset($container['Id']) ? (string)$container['Id'] : '';
    if ($id === '') throw new RuntimeException('Container-Antwort enthält keine ID.');
    $inspect = pr_api_request($cfg, 'GET', '/endpoints/' . $endpoint . '/docker/containers/' . rawurlencode($id) . '/json');
    $state = isset($inspect['State']) && is_array($inspect['State']) ? $inspect['State'] : array();
    $health = isset($state['Health']['Status']) ? $state['Health']['Status'] : null;
    return array(
        'id' => $id,
        'name' => ltrim(isset($container['Names'][0]) ? (string)$container['Names'][0] : $cfg['container_name'], '/'),
        'state' => isset($container['State']) ? (string)$container['State'] : (isset($state['Status']) ? (string)$state['Status'] : 'unknown'),
        'status' => isset($container['Status']) ? (string)$container['Status'] : '',
        'health' => $health,
        'image' => isset($container['Image']) ? (string)$container['Image'] : '',
        'started_at' => isset($state['StartedAt']) ? (string)$state['StartedAt'] : ''
    );
}

function pr_container_action($cfg, $action, $container_id) {
    $allowed = array('start', 'stop', 'restart');
    if (!in_array($action, $allowed, true)) throw new RuntimeException('Aktion nicht erlaubt.');
    if (!preg_match('/^[a-fA-F0-9]+$/', $container_id)) throw new RuntimeException('Ungültige Container-ID.');
    return pr_api_request($cfg, 'POST', '/endpoints/' . (int)$cfg['endpoint_id'] . '/docker/containers/' . rawurlencode($container_id) . '/' . $action);
}

function pr_container_logs($cfg, $container_id, $tail = 100) {
    if (!preg_match('/^[a-fA-F0-9]+$/', $container_id)) throw new RuntimeException('Ungültige Container-ID.');
    $tail = max(1, min(200, (int)$tail));
    $logs = pr_api_request($cfg, 'GET', '/endpoints/' . (int)$cfg['endpoint_id'] . '/docker/containers/' . rawurlencode($container_id) . '/logs?stdout=1&stderr=1&tail=' . $tail . '&timestamps=1', true);
    // Docker multiplexes stdout/stderr into 8-byte framed records when TTY is disabled.
    $decoded = '';
    $offset = 0;
    $framed = strlen($logs) >= 8;
    while ($framed && $offset + 8 <= strlen($logs)) {
        $stream = ord($logs[$offset]);
        $lengthData = unpack('Nlength', substr($logs, $offset + 4, 4));
        $length = isset($lengthData['length']) ? (int)$lengthData['length'] : -1;
        if (!in_array($stream, array(1, 2), true) || $length < 0 || $offset + 8 + $length > strlen($logs)) { $framed = false; break; }
        $decoded .= substr($logs, $offset + 8, $length);
        $offset += 8 + $length;
    }
    if ($framed && $offset === strlen($logs)) $logs = $decoded;
    return substr($logs, -20000);
}
