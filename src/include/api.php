<?php
/**
 * api.php — JSON endpoint for the vm.manager plugin UI.
 *
 * Read-only (no CSRF):
 *   GET ?action=list        -> { vms:[...], gpu_configured:bool }
 *   GET ?action=token       -> { token }            (live CSRF token)
 *   GET ?action=memstatus   vm=<name>   -> { status:{ts,done,ok,message}|null }
 *   GET ?action=gpucfg      -> { cfg:{...}, gpus:[...] }   (config + detected GPUs)
 *
 * Mutating (require valid CSRF token; VM name validated against the live list):
 *   start|stop|forcestop|restart|forcerestart   vm=<name>
 *   mem    vm=<name> size=<8G|8192M|4096>
 *   gpu    vm=<name> mode=on|off|toggle|status
 *   savegpu  gpu_host=<DOMAIN:BUS:SLOT> [guest_bus=..] [guest_slot=..]
 *
 * Runs as root under emhttp's php-fpm.
 */

require_once __DIR__ . '/vmlib.php';

header('Content-Type: application/json');

function out(array $data): void { echo json_encode($data); exit; }

function require_csrf(): void {
    $real = csrf_token();
    $got  = (string)($_REQUEST['csrf_token'] ?? '');
    if ($real === '' || !hash_equals($real, $got)) {
        http_response_code(403);
        out(['ok' => false, 'message' => 'Invalid CSRF token.']);
    }
}

$action = $_REQUEST['action'] ?? '';

// --- read-only ---------------------------------------------------------------
if ($action === 'list') {
    out(['ok' => true, 'vms' => vm_all(), 'gpu_configured' => gpu_configured()]);
}

// Live CSRF token (the page fetches this via jQuery GET right before each action;
// same-origin + Unraid login gate it, and delivering it to the page is its point).
if ($action === 'token') {
    out(['ok' => true, 'token' => csrf_token()]);
}

if ($action === 'memstatus') {
    $vm = trim($_REQUEST['vm'] ?? '');
    if ($vm === '' || !vm_valid($vm)) out(['ok' => false, 'message' => 'unknown vm']);
    out(['ok' => true, 'status' => mem_status_read($vm)]);
}

if ($action === 'gpucfg') {
    $c = cfg_load();
    out(['ok' => true, 'cfg' => [
        'gpu_host'   => $c['gpu_host'],
        'guest_bus'  => $c['guest_bus'],
        'guest_slot' => $c['guest_slot'],
        'has_audio'  => (bool)$c['has_audio'],
        'configured' => gpu_configured(),
    ], 'gpus' => detect_gpus()]);
}

// --- everything below mutates state -----------------------------------------
require_csrf();

// Save GPU config: validate the chosen device against the live PCI scan.
if ($action === 'savegpu') {
    $host = strtolower(trim($_REQUEST['gpu_host'] ?? ''));
    if ($host === '') {                          // empty = clear configuration
        cfg_save(array_merge(cfg_load(), ['gpu_host' => '']));
        out(ok('GPU configuration cleared.'));
    }
    $match = null;
    foreach (detect_gpus() as $g) if (strtolower($g['id']) === $host) { $match = $g; break; }
    if (!$match) out(fail("'$host' is not a display device on this system."));

    $c = cfg_load();
    $c['gpu_host']   = $match['id'];
    $c['has_audio']  = (bool)$match['has_audio'];
    $c['guest_bus']  = norm_hex2((string)($_REQUEST['guest_bus']  ?? $c['guest_bus']));
    $c['guest_slot'] = norm_hex2((string)($_REQUEST['guest_slot'] ?? $c['guest_slot']));
    if (!cfg_save($c)) out(fail('Failed to write config to flash.'));
    out(ok('GPU configuration saved'
         . ($match['has_audio'] ? ' (VGA + audio).' : ' (VGA only — no audio function found).')));
}

$vm = trim($_REQUEST['vm'] ?? '');
if ($vm === '' || !vm_valid($vm)) {
    http_response_code(400);
    out(['ok' => false, 'message' => "Unknown VM: '$vm'."]);
}

switch ($action) {
    case 'start':        out(act_start($vm));
    case 'stop':         out(act_stop($vm));
    case 'forcestop':    out(act_forcestop($vm));
    case 'restart':      out(act_restart($vm));
    case 'forcerestart': out(act_forcerestart($vm));
    case 'mem':          out(act_mem($vm, trim($_REQUEST['size'] ?? '')));
    case 'gpu':          out(act_gpu($vm, trim($_REQUEST['mode'] ?? 'toggle')));
    default:
        http_response_code(400);
        out(['ok' => false, 'message' => "Unknown action: '$action'."]);
}
