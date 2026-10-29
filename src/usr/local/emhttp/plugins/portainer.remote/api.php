<?php
require_once __DIR__ . '/lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function pr_json_response($status, $payload) {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') pr_json_response(405, array('ok' => false, 'error' => 'POST required'));
$vars = @parse_ini_file('/var/local/emhttp/var.ini', false, INI_SCANNER_RAW);
$expected = is_array($vars) && isset($vars['csrf_token']) ? (string)$vars['csrf_token'] : '';
$provided = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
if ($expected === '' || $provided === '' || !hash_equals($expected, $provided)) pr_json_response(403, array('ok' => false, 'error' => 'Sitzung abgelaufen oder CSRF-Token ungültig. Seite neu laden.'));

try {
    $action = isset($_POST['action']) ? (string)$_POST['action'] : '';
    $saved = pr_load_config();
    if ($action === 'test') {
        $cfg = $saved;
        $cfg['portainer_url'] = isset($_POST['portainer_url']) ? $_POST['portainer_url'] : '';
        $submitted_key = isset($_POST['api_key']) ? pr_clean_string($_POST['api_key'], 1024) : '';
        if ($submitted_key !== '') $cfg['api_key'] = $submitted_key;
        $cfg['verify_tls'] = isset($_POST['verify_tls']) && $_POST['verify_tls'] === 'yes' ? 'yes' : 'no';
        $cfg = pr_validate_config($cfg, false);
        $endpoints = pr_endpoints($cfg);
        pr_json_response(200, array('ok' => true, 'endpoints' => $endpoints, 'message' => 'Portainer-Verbindung erfolgreich.'));
    }
    if ($action === 'save') {
        $cfg = $saved;
        $cfg['portainer_url'] = isset($_POST['portainer_url']) ? $_POST['portainer_url'] : '';
        $cfg['endpoint_id'] = isset($_POST['endpoint_id']) ? $_POST['endpoint_id'] : '';
        $cfg['container_name'] = isset($_POST['container_name']) ? $_POST['container_name'] : '';
        $cfg['refresh_seconds'] = isset($_POST['refresh_seconds']) ? $_POST['refresh_seconds'] : '30';
        $cfg['verify_tls'] = isset($_POST['verify_tls']) && $_POST['verify_tls'] === 'yes' ? 'yes' : 'no';
        if (isset($_POST['clear_api_key']) && $_POST['clear_api_key'] === 'yes') $cfg['api_key'] = '';
        elseif (isset($_POST['api_key']) && trim((string)$_POST['api_key']) !== '') $cfg['api_key'] = $_POST['api_key'];
        $clearing_key = isset($_POST['clear_api_key']) && $_POST['clear_api_key'] === 'yes';
        $cfg = pr_validate_config($cfg, true, !$clearing_key);
        pr_save_config($cfg);
        pr_json_response(200, array('ok' => true, 'message' => 'Einstellungen gespeichert.'));
    }

    $cfg = pr_validate_config($saved, true);
    if ($action === 'status') {
        $container = pr_container($cfg);
        pr_json_response(200, array('ok' => true, 'container' => $container, 'checked_at' => date(DATE_ATOM)));
    }
    if ($action === 'logs') {
        $id = isset($_POST['container_id']) ? (string)$_POST['container_id'] : '';
        $logs = pr_container_logs($cfg, $id, isset($_POST['tail']) ? $_POST['tail'] : 100);
        pr_json_response(200, array('ok' => true, 'logs' => $logs));
    }
    if (in_array($action, array('start', 'stop', 'restart'), true)) {
        // Resolve the configured exact container name server-side; never trust a browser-supplied ID.
        $container = pr_container($cfg);
        if (!$container) throw new RuntimeException('Der konfigurierte Container wurde nicht gefunden.');
        pr_container_action($cfg, $action, $container['id']);
        pr_json_response(200, array('ok' => true, 'message' => 'Aktion gesendet.'));
    }
    pr_json_response(400, array('ok' => false, 'error' => 'Unbekannte Aktion.'));
} catch (Throwable $e) {
    pr_json_response(400, array('ok' => false, 'error' => $e->getMessage()));
}
