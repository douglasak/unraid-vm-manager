<?php
/**
 * vmlib.php — core VM-management logic for the vm.manager Unraid plugin.
 *
 * Runs on the Unraid host: emhttp/php-fpm are root on the same box as libvirt,
 * so this calls virsh directly (no SSH). GPU passthrough is configured at runtime
 * (see GPU config below) rather than hardcoded, so it works on any host.
 */

const PLUGIN     = 'vm.manager';
const PLUGIN_DIR = '/usr/local/emhttp/plugins/' . PLUGIN;
const CFG_DIR    = '/boot/config/plugins/' . PLUGIN;     // flash: survives reboot
const CFG_FILE   = CFG_DIR . '/' . PLUGIN . '.cfg';

// --- var.ini / CSRF ---------------------------------------------------------

/**
 * Read Unraid's var.ini robustly. Under php-fpm, open_basedir may block PHP file
 * functions from /var/local/emhttp; exec() spawns a process not subject to it.
 */
function emhttp_var_raw(): string {
    $ini = @file_get_contents('/var/local/emhttp/var.ini');
    if ($ini === false || $ini === '') {
        $out = [];
        @exec('cat /var/local/emhttp/var.ini 2>/dev/null', $out);
        $ini = implode("\n", $out);
    }
    return (string)$ini;
}

/** Pull a single key from var.ini (quote-agnostic), avoiding parse_ini_file. */
function emhttp_var(string $key, string $default = ''): string {
    $re = '/^' . preg_quote($key, '/') . '="?([^"\r\n]*)"?/m';
    if (preg_match($re, emhttp_var_raw(), $m)) return $m[1];
    return $default;
}

/** Unraid CSRF token. */
function csrf_token(): string {
    return emhttp_var('csrf_token');
}

// --- binaries ---------------------------------------------------------------

function which_bin(array $candidates, string $fallback): string {
    foreach ($candidates as $c) if (is_executable($c)) return $c;
    return $fallback;
}
function virsh_bin(): string {
    static $b = null;
    return $b ??= which_bin(['/usr/sbin/virsh','/usr/bin/virsh','/usr/local/sbin/virsh','/usr/local/bin/virsh'], 'virsh');
}
function lspci_bin(): string {
    static $b = null;
    return $b ??= which_bin(['/usr/sbin/lspci','/sbin/lspci','/usr/bin/lspci'], 'lspci');
}

/** Run virsh with each argument escaped. Returns [rc, output-lines]. */
function virsh(array $args): array {
    $cmd = escapeshellarg(virsh_bin());
    foreach ($args as $a) $cmd .= ' ' . escapeshellarg((string)$a);
    $out = []; $rc = 0;
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, $out];
}

// --- VM queries -------------------------------------------------------------

function vm_list_names(): array {
    [$rc, $out] = virsh(['list', '--all', '--name']);
    if ($rc !== 0) return [];
    return array_values(array_filter(array_map('trim', $out), fn($n) => $n !== ''));
}

/** Only names virsh reports are accepted — guards every action. */
function vm_valid(string $vm): bool {
    return in_array($vm, vm_list_names(), true);
}

function vm_state(string $vm): string {
    [$rc, $out] = virsh(['domstate', $vm]);
    return $rc === 0 ? trim(implode('', $out)) : 'unknown';
}

function vm_dumpxml(string $vm): string {
    [$rc, $out] = virsh(['dumpxml', '--inactive', $vm]);
    return $rc === 0 ? implode("\n", $out) : '';
}

function vm_mem_kib(string $vm): ?int {
    $xml = vm_dumpxml($vm);
    if ($xml !== '' && preg_match('#<memory[^>]*>(\d+)</memory>#', $xml, $m)) return (int)$m[1];
    return null;
}

/** Is the configured passthrough GPU attached in the persistent config? */
function vm_gpu_on(string $vm): bool {
    if (!gpu_configured()) return false;
    $xml = vm_dumpxml($vm);
    return $xml !== '' && strpos($xml, gpu_source_match()) !== false;
}

function kib_to_human(?int $kib): string {
    if ($kib === null) return '?';
    if ($kib >= 1048576) {
        $g = $kib / 1048576;
        return ($g == (int)$g) ? sprintf('%dG', $g) : sprintf('%.1fG', $g);
    }
    return sprintf('%dM', (int)round($kib / 1024));
}

/** Parse "8G" / "8192M" / "4096" (bare = MB) -> KiB, or null if invalid. */
function to_kib(string $size): ?int {
    $s = strtoupper(trim($size));
    if (!preg_match('/^(\d+)\s*([GMK])?$/', $s, $m)) return null;
    $n = (int)$m[1];
    switch ($m[2] ?? '') {
        case 'G': $kib = $n * 1024 * 1024; break;
        case 'M': $kib = $n * 1024;        break;
        case 'K': $kib = $n;               break;
        default:  $kib = $n * 1024;        break;
    }
    if ($kib < 262144 || $kib > 536870912) return null;   // 256 MiB .. 512 GiB
    return $kib;
}

function vm_info(string $vm): array {
    $kib = vm_mem_kib($vm);
    return [
        'name'    => $vm,
        'state'   => vm_state($vm),
        'mem_kib' => $kib,
        'ram'     => kib_to_human($kib),
        'gpu'     => vm_gpu_on($vm) ? 'on' : 'off',
    ];
}

function vm_all(): array {
    return array_map('vm_info', vm_list_names());
}

// --- GPU configuration ------------------------------------------------------
// Config (on flash, persists): gpu_host = "DOMAIN:BUS:SLOT" (no function),
// e.g. "0000:01:00"; guest_bus/guest_slot = optional explicit guest target
// (blank = let libvirt auto-assign); has_audio = whether a .1 audio function
// exists for this card.

function cfg_load(): array {
    $defaults = ['gpu_host' => '', 'guest_bus' => '0x06', 'guest_slot' => '0x00', 'has_audio' => true];
    $raw = @file_get_contents(CFG_FILE);
    if ($raw === false || $raw === '') {
        $o = []; @exec('cat ' . escapeshellarg(CFG_FILE) . ' 2>/dev/null', $o);
        $raw = implode("\n", $o);
    }
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) return array_merge($defaults, $j);
    }
    return $defaults;
}

/** Save config to flash. Writes via a temp file + cp to dodge open_basedir on /boot. */
function cfg_save(array $c): bool {
    $json = json_encode($c, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $tmp  = tempnam(sys_get_temp_dir(), 'vmmcfg');
    if ($tmp === false || @file_put_contents($tmp, $json) === false) return false;
    @exec('mkdir -p ' . escapeshellarg(CFG_DIR) . ' && cp ' . escapeshellarg($tmp) . ' ' . escapeshellarg(CFG_FILE), $o, $rc);
    @unlink($tmp);
    return $rc === 0;
}

function gpu_configured(): bool {
    return (bool)preg_match('/^[0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2}$/i', cfg_load()['gpu_host'] ?? '');
}

/** [domain, bus, slot] as libvirt 0x-prefixed hex, from "0000:01:00". */
function gpu_host_parts(): array {
    [$d, $b, $s] = explode(':', cfg_load()['gpu_host']);
    return ["0x$d", "0x$b", "0x$s"];
}

/** Detection string: the VGA function's host source <address> (no type='pci'). */
function gpu_source_match(): string {
    [$d, $b, $s] = gpu_host_parts();
    return "<address domain='$d' bus='$b' slot='$s' function='0x0'/>";
}

/** Normalise a guest bus/slot field ("6","06","0x6") to "0x06", or '' if blank/invalid. */
function norm_hex2(string $v): string {
    $v = trim($v);
    if ($v === '') return '';
    if (!preg_match('/^(0x)?([0-9a-f]{1,2})$/i', $v, $m)) return '';
    return '0x' . strtolower(str_pad($m[2], 2, '0', STR_PAD_LEFT));
}

/** Guest <address> element for a function, or '' to let libvirt auto-assign. */
function gpu_guest_address(string $func): string {
    $c  = cfg_load();
    $gb = norm_hex2($c['guest_bus'] ?? '');
    $gs = norm_hex2($c['guest_slot'] ?? '');
    if ($gb === '' || $gs === '') return '';
    $mf = ($func === '0x0') ? " multifunction='on'" : '';
    return "<address type='pci' domain='0x0000' bus='$gb' slot='$gs' function='$func'$mf/>";
}

function gpu_hostdev_xml(string $func): string {
    [$d, $b, $s] = gpu_host_parts();
    $guest = gpu_guest_address($func);
    return "<hostdev mode='subsystem' type='pci' managed='yes'>\n"
         . "  <driver name='vfio'/>\n"
         . "  <source>\n"
         . "    <address domain='$d' bus='$b' slot='$s' function='$func'/>\n"
         . "  </source>\n"
         . ($guest !== '' ? "  $guest\n" : '')
         . "</hostdev>\n";
}

/** Enumerate display-class PCI devices (and whether each has a .1 audio sibling). */
function detect_gpus(): array {
    $out = []; @exec(escapeshellarg(lspci_bin()) . ' -D -nn 2>/dev/null', $out);
    $vga = []; $audio = [];
    foreach ($out as $line) {
        if (!preg_match('/^([0-9a-f]{4}:[0-9a-f]{2}:[0-9a-f]{2})\.([0-9a-f])\s+(.*)$/i', $line, $m)) continue;
        [$slot, $func, $desc] = [$m[1], strtolower($m[2]), $m[3]];
        if (preg_match('/\[03[0-9a-f]{2}\]/i', $desc)) {                 // display controller class 03xx
            $vga[$slot] = trim(preg_replace('/\s*\[[0-9a-f]{4}:[0-9a-f]{4}\].*$/i', '', $desc));
        } elseif ($func === '1' && preg_match('/\[0403\]/', $desc)) {    // audio device
            $audio[$slot] = true;
        }
    }
    $gpus = [];
    foreach ($vga as $slot => $desc) {
        $gpus[] = ['id' => $slot, 'desc' => $desc, 'has_audio' => isset($audio[$slot])];
    }
    return $gpus;
}

// --- actions ----------------------------------------------------------------

function ok(string $msg, array $extra = []): array  { return ['ok' => true,  'message' => $msg] + $extra; }
function fail(string $msg, array $extra = []): array { return ['ok' => false, 'message' => $msg] + $extra; }

function act_start(string $vm): array {
    if (vm_state($vm) === 'running') return ok("'$vm' is already running.");
    [$rc, $out] = virsh(['start', $vm]);
    return $rc === 0 ? ok("Started '$vm'.") : fail(trim(implode("\n", $out)));
}
function act_stop(string $vm): array {
    [$rc, $out] = virsh(['shutdown', $vm]);
    return $rc === 0 ? ok("Shutdown signal sent to '$vm'. (Use Force stop if it doesn't stop.)") : fail(trim(implode("\n", $out)));
}
function act_forcestop(string $vm): array {
    [$rc, $out] = virsh(['destroy', $vm]);
    return $rc === 0 ? ok("Force-stopped '$vm'.") : fail(trim(implode("\n", $out)));
}
function act_restart(string $vm): array {
    [$rc, $out] = virsh(['reboot', $vm]);
    return $rc === 0 ? ok("Reboot signal sent to '$vm'.") : fail(trim(implode("\n", $out)));
}
function act_forcerestart(string $vm): array {
    [$rc, $out] = virsh(['reset', $vm]);
    return $rc === 0 ? ok("Hard-reset '$vm'.") : fail(trim(implode("\n", $out)));
}

// --- memory (with async status for the running-VM restart cycle) ------------

function mem_status_path(string $vm): string {
    return sys_get_temp_dir() . '/vm.manager.mem-' . md5($vm);
}

function mem_status_read(string $vm): ?array {
    $raw = @file_get_contents(mem_status_path($vm));
    if ($raw === false || $raw === '') return null;
    $kv = [];
    foreach (explode("\n", $raw) as $l) {
        if ($l === '') continue;
        [$k, $v] = array_pad(explode('=', $l, 2), 2, '');
        $kv[$k] = $v;
    }
    $okf = $kv['ok'] ?? '';
    return [
        'ts'      => (int)($kv['ts'] ?? 0),
        'done'    => ($okf !== ''),     // empty 'ok' = still in progress
        'ok'      => ($okf === '1'),
        'message' => $kv['msg'] ?? '',
    ];
}

/**
 * Set memory (both max and current). libvirt only allows max to change while the
 * VM is off, so for a running VM we stop → apply → start in the BACKGROUND (the
 * graceful shutdown can take ~60s) and write the outcome to a status file the UI
 * polls — so a failed restart surfaces as a real error instead of a silent stop.
 */
function act_mem(string $vm, string $size): array {
    $kib = to_kib($size);
    if ($kib === null) return fail("Invalid size '$size' (use e.g. 8G, 8192M, 4096; 256M..512G).");
    $human = kib_to_human($kib);

    if (vm_state($vm) === 'running') {
        $status = mem_status_path($vm);
        @file_put_contents($status, "ts=" . time() . "\nok=\nmsg=Applying $human…\n");

        $tpl = <<<'BASH'
err=""
%1$s shutdown %2$s 2>/dev/null
for i in $(seq 1 30); do [ "$(%1$s domstate %2$s 2>/dev/null)" = running ] || break; sleep 2; done
[ "$(%1$s domstate %2$s 2>/dev/null)" = running ] && %1$s destroy %2$s 2>/dev/null
e=$(%1$s setmaxmem %2$s %3$d --config 2>&1) || err="setmaxmem: $e"
[ -z "$err" ] && { e=$(%1$s setmem %2$s %3$d --config 2>&1) || err="setmem: $e"; }
if [ -z "$err" ]; then
  if e=$(%1$s start %2$s 2>&1); then
    printf 'ts=%%s\nok=1\nmsg=%%s\n' "$(date +%%s)" "%4$s applied and VM restarted." > %5$s
  else
    printf 'ts=%%s\nok=0\nmsg=%%s\n' "$(date +%%s)" "%4$s applied but VM failed to restart: $(printf %%s "$e" | tr '\n' ' ' | cut -c1-180)" > %5$s
  fi
else
  printf 'ts=%%s\nok=0\nmsg=%%s\n' "$(date +%%s)" "Memory change failed: $(printf %%s "$err" | tr '\n' ' ' | cut -c1-180)" > %5$s
fi
BASH;
        $script = sprintf($tpl, escapeshellarg(virsh_bin()), escapeshellarg($vm), $kib, $human, escapeshellarg($status));
        exec('nohup bash -c ' . escapeshellarg($script) . ' >/dev/null 2>&1 &');
        return ok("Restarting '$vm' to apply $human…", ['async' => true]);
    }

    [$rc1, $o1] = virsh(['setmaxmem', $vm, $kib, '--config']);
    [$rc2, $o2] = virsh(['setmem',    $vm, $kib, '--config']);
    if ($rc1 !== 0 || $rc2 !== 0) return fail(trim(implode("\n", array_merge($o1, $o2))));
    return ok("Memory of '$vm' set to $human.");
}

// --- GPU passthrough toggle (dynamic fragments from config) -----------------

function act_gpu(string $vm, string $want = 'toggle'): array {
    if (!gpu_configured()) {
        return fail('No GPU configured. Open GPU Passthrough settings and select your card first.');
    }
    $cur = vm_gpu_on($vm) ? 'on' : 'off';

    if ($want === 'status') return ok("GPU passthrough is $cur for '$vm'.", ['gpu' => $cur]);
    if ($want === 'toggle') $want = ($cur === 'on') ? 'off' : 'on';
    if ($want !== 'on' && $want !== 'off') return fail("Bad GPU request '$want'.");
    if ($want === $cur) return ok("GPU passthrough already $cur for '$vm'.", ['gpu' => $cur]);
    if (vm_state($vm) !== 'shut off') return fail("'$vm' must be shut off to change GPU passthrough.");

    $hasAudio = (bool)(cfg_load()['has_audio'] ?? false);

    // Write generated fragments to temp files for virsh.
    $vgaFile = tempnam(sys_get_temp_dir(), 'vmmvga');
    @file_put_contents($vgaFile, gpu_hostdev_xml('0x0'));
    $audFile = null;
    if ($hasAudio) {
        $audFile = tempnam(sys_get_temp_dir(), 'vmmaud');
        @file_put_contents($audFile, gpu_hostdev_xml('0x1'));
    }

    $err = [];
    if ($want === 'on') {
        [$r, $o] = virsh(['attach-device', $vm, $vgaFile, '--config']); if ($r !== 0) $err = array_merge($err, $o);
        if ($hasAudio && !$err) { [$r, $o] = virsh(['attach-device', $vm, $audFile, '--config']); if ($r !== 0) $err = array_merge($err, $o); }
        $msg = "GPU passthrough enabled for '$vm'. Start it to use the card.";
    } else {
        if ($hasAudio) { [$r, $o] = virsh(['detach-device', $vm, $audFile, '--config']); if ($r !== 0) $err = array_merge($err, $o); }
        [$r, $o] = virsh(['detach-device', $vm, $vgaFile, '--config']); if ($r !== 0) $err = array_merge($err, $o);
        $msg = "GPU passthrough disabled for '$vm' (VNC only).";
    }

    @unlink($vgaFile);
    if ($audFile) @unlink($audFile);

    if ($err) return fail(trim(implode("\n", $err)));
    return ok($msg, ['gpu' => $want]);
}
