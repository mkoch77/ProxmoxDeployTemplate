<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;
use App\Helpers;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();

$user = Auth::requirePermission('template.deploy');
$body = Request::jsonBody();

// ── Allowed image catalog ────────────────────────────────────────────────────
const CLOUD_IMAGES = [
    'ubuntu-24.04' => [
        'name'         => 'Ubuntu 24.04 LTS (Noble)',
        'url'          => 'https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img',
        'default_user' => 'ubuntu',
    ],
    'ubuntu-22.04' => [
        'name'         => 'Ubuntu 22.04 LTS (Jammy)',
        'url'          => 'https://cloud-images.ubuntu.com/jammy/current/jammy-server-cloudimg-amd64.img',
        'default_user' => 'ubuntu',
    ],
    'debian-12' => [
        'name'         => 'Debian 12 (Bookworm)',
        'url'          => 'https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2',
        'default_user' => 'debian',
    ],
    'debian-11' => [
        'name'         => 'Debian 11 (Bullseye)',
        'url'          => 'https://cloud.debian.org/images/cloud/bullseye/latest/debian-11-genericcloud-amd64.qcow2',
        'default_user' => 'debian',
    ],
    'rocky-9' => [
        'name'         => 'Rocky Linux 9',
        'url'          => 'https://dl.rockylinux.org/pub/rocky/9/images/x86_64/Rocky-9-GenericCloud.latest.x86_64.qcow2',
        'default_user' => 'rocky',
    ],
    'almalinux-9' => [
        'name'         => 'AlmaLinux 9',
        'url'          => 'https://repo.almalinux.org/almalinux/9/cloud/x86_64/images/AlmaLinux-9-GenericCloud.latest.x86_64.qcow2',
        'default_user' => 'almalinux',
    ],
];

// ── Validate inputs ──────────────────────────────────────────────────────────
$imageId = $body['image_id'] ?? '';
if (!isset(CLOUD_IMAGES[$imageId])) {
    Response::error('Invalid image ID', 400);
}
$image = CLOUD_IMAGES[$imageId];

$vmid = (int)($body['vmid'] ?? 0);
if ($vmid < 100 || $vmid > 999999999) {
    Response::error('VMID must be between 100 and 999999999', 400);
}

$name = $body['name'] ?? '';
if (!$name || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,62}$/', $name)) {
    Response::error('Invalid VM name (alphanumeric, dots and dashes only)', 400);
}

$nodeName = $body['node'] ?? '';
if (!$nodeName || !Helpers::validateNodeName($nodeName)) {
    Response::error('Invalid node name', 400);
}

$storage = $body['storage'] ?? '';
if (!$storage || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]{0,63}$/', $storage)) {
    Response::error('Invalid storage name', 400);
}

$bridge = $body['bridge'] ?? '';
if (!$bridge || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]{0,15}$/', $bridge)) {
    Response::error('Invalid bridge name', 400);
}

$cores = (int)($body['cores'] ?? 2);
if ($cores < 1 || $cores > 128) {
    Response::error('CPU cores must be between 1 and 128', 400);
}

$memory = (int)($body['memory'] ?? 2048);
if ($memory < 256 || $memory > 131072) {
    Response::error('Memory must be between 256 and 131072 MB', 400);
}

$diskSize = (int)($body['disk_size'] ?? 10);
if ($diskSize < 2 || $diskSize > 10000) {
    Response::error('Disk size must be between 2 and 10000 GB', 400);
}

$ciUser = $body['ci_user'] ?? $image['default_user'];
if (!preg_match('/^[a-z_][a-z0-9_\-]{0,31}$/', $ciUser)) {
    Response::error('Invalid cloud-init user (lowercase letters, digits, - and _ only)', 400);
}

$ciPassword   = $body['ci_password'] ?? '';
$ciSshKeys    = $body['ci_sshkeys'] ?? '';
$ciNameserver = $body['ci_nameserver'] ?? '';
if ($ciNameserver && !filter_var($ciNameserver, FILTER_VALIDATE_IP)) {
    Response::error('Invalid DNS server (must be a valid IP)', 400);
}

$ciSearchdomain = $body['ci_searchdomain'] ?? '';

// Tags: semicolon-separated, e.g. "prod;web;debian"
$tags = preg_replace('/[^a-z0-9\-_;]/', '', strtolower($body['tags'] ?? ''));

// Custom packages (one per line, alphanumeric + .-+ only)
$ciPackages = array_values(array_filter(array_map('trim', explode("\n", $body['ci_packages'] ?? '')),
    fn($p) => preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.+\-]{0,127}$/', $p)));

// Custom run commands (one per line)
$ciRuncmd = array_values(array_filter(array_map('trim', explode("\n", $body['ci_runcmd'] ?? ''))));

$ipType = $body['ip_type'] ?? 'dhcp';
if ($ipType === 'static') {
    $ciIp = $body['ci_ip'] ?? '';
    $ciGw = $body['ci_gw'] ?? '';
    if (!$ciIp || !preg_match('#^\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}/\d{1,2}$#', $ciIp)) {
        Response::error('Invalid static IP – use CIDR notation (e.g. 192.168.1.50/24)', 400);
    }
    $ipconfig = 'ip=' . $ciIp;
    if ($ciGw) {
        if (!filter_var($ciGw, FILTER_VALIDATE_IP)) {
            Response::error('Invalid gateway IP', 400);
        }
        $ipconfig .= ',gw=' . $ciGw;
    }
} else {
    $ipconfig = 'ip=dhcp';
}

// ── Resolve SSH host ─────────────────────────────────────────────────────────
$envKey  = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $nodeName));
$sshHost = Config::get($envKey, '');
if (!$sshHost) {
    $sshHost = $nodeName;
    try {
        $api    = Helpers::createAPI();
        $status = $api->getClusterStatus();
        foreach ($status['data'] ?? [] as $entry) {
            if (($entry['type'] ?? '') === 'node' &&
                strtolower($entry['name'] ?? '') === strtolower($nodeName) &&
                !empty($entry['ip'])) {
                $sshHost = $entry['ip'];
                break;
            }
        }
    } catch (\Exception $e) { /* fall back to node name */ }
}

// ── Build deployment script ──────────────────────────────────────────────────
$tmpImg  = '/tmp/pve_ci_' . $vmid . '_' . bin2hex(random_bytes(4)) . '.img';
$tmpKeys = '/tmp/pve_ci_' . $vmid . '_keys.pub';

$lines   = [];
$lines[] = 'export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"';
$lines[] = 'set -e';
$lines[] = "trap 'echo \"ERROR: failed at line \$LINENO (exit \$?)\"' ERR";
$lines[] = 'VMID=' . (int)$vmid;
$lines[] = 'IMG=' . escapeshellarg($tmpImg);
$lines[] = 'KEY=' . escapeshellarg($tmpKeys);
$lines[] = '';
// Clean up any leftover VM/disks from a previous failed run with the same VMID
$lines[] = 'if qm status $VMID >/dev/null 2>&1; then';
$lines[] = '  echo "==> Cleaning up existing VM $VMID from a previous run..."';
$lines[] = '  qm stop $VMID --skiplock 2>/dev/null || true';
$lines[] = '  qm destroy $VMID --purge --skiplock 2>/dev/null || true';
$lines[] = 'fi';
$lines[] = '';
$lines[] = "echo ''";
$lines[] = "echo '==> [1/8] Downloading " . addslashes($image['name']) . "...'";
$lines[] = 'if command -v wget >/dev/null 2>&1; then wget -q --show-progress -O "$IMG" ' . escapeshellarg($image['url']) . '; else curl -L --progress-bar -o "$IMG" ' . escapeshellarg($image['url']) . '; fi';
$lines[] = '';
$lines[] = "echo '==> [2/8] Creating VM \$VMID...'";
$lines[] = 'qm create $VMID'
    . ' --name ' . escapeshellarg($name)
    . ' --memory ' . (int)$memory
    . ' --cores ' . (int)$cores
    . ' --net0 virtio,bridge=' . escapeshellarg($bridge)
    . ' --ostype l26 --cpu host';
$lines[] = '';
$lines[] = "echo '==> [3/8] Importing disk (may take a while)...'";
$lines[] = 'qm importdisk $VMID "$IMG" ' . escapeshellarg($storage) . ' --format qcow2';
$lines[] = '';
$lines[] = "echo '==> [4/8] Configuring hardware...'";
$lines[] = "DISK=\$(qm config \$VMID | grep '^unused0:' | awk '{print \$2}')";
$lines[] = 'qm set $VMID --scsihw virtio-scsi-pci --scsi0 "${DISK},discard=on,ssd=1"';
$lines[] = 'qm set $VMID --ide2 ' . escapeshellarg($storage) . ':cloudinit';
$lines[] = 'qm set $VMID --boot order=scsi0';
$lines[] = 'qm set $VMID --serial0 socket --vga serial0';
$lines[] = 'qm set $VMID --agent enabled=1';
$lines[] = '';
$lines[] = "echo '==> [5/8] Configuring Cloud-Init...'";
$ciSetCmd = 'qm set $VMID --ciuser ' . escapeshellarg($ciUser);
if ($ciPassword) {
    $ciSetCmd .= ' --cipassword ' . escapeshellarg($ciPassword);
}
$lines[] = $ciSetCmd;
if ($ciSshKeys) {
    $lines[] = 'echo ' . escapeshellarg(base64_encode($ciSshKeys)) . ' | base64 -d > "$KEY"';
    $lines[] = 'qm set $VMID --sshkeys "$KEY"';
    $lines[] = 'rm -f "$KEY"';
}
$lines[] = 'qm set $VMID --ipconfig0 ' . escapeshellarg($ipconfig);
if ($ciNameserver)   $lines[] = 'qm set $VMID --nameserver '   . escapeshellarg($ciNameserver);
if ($ciSearchdomain) $lines[] = 'qm set $VMID --searchdomain ' . escapeshellarg($ciSearchdomain);
if ($tags)           $lines[] = 'qm set $VMID --tags '         . escapeshellarg($tags);

// ── Vendor-data: auto-install qemu-guest-agent so the dashboard can read the IP ──
$vendorFile = '/var/lib/vz/snippets/ci_vendor_' . $vmid . '.yaml';
$lines[] = '';
$lines[] = "echo '==> [6/8] Configuring QEMU guest agent installation...'";
$lines[] = 'mkdir -p /var/lib/vz/snippets';
$lines[] = 'cat > ' . escapeshellarg($vendorFile) . " << 'CI_VENDOR_EOF'";
$lines[] = '#cloud-config';
$lines[] = 'packages:';
$lines[] = '  - qemu-guest-agent';
foreach ($ciPackages as $pkg) {
    $lines[] = '  - ' . $pkg;
}
$lines[] = 'runcmd:';
$lines[] = '  - systemctl daemon-reload';
$lines[] = '  - systemctl enable --now qemu-guest-agent';
foreach ($ciRuncmd as $cmd) {
    $lines[] = '  - ' . json_encode($cmd); // JSON strings are valid YAML scalars
}
$lines[] = 'CI_VENDOR_EOF';
// Enable snippets on local storage if not already (additive, preserves existing content types)
$lines[] = 'LCONT=$(pvesh get /storage/local --output-format json 2>/dev/null | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get(\'content\',\'\'))" 2>/dev/null || echo "")';
$lines[] = 'if [ -n "$LCONT" ] && ! echo "$LCONT" | grep -q snippets; then pvesh set /storage/local --content "${LCONT},snippets" 2>/dev/null || true; fi';
// PVE 8.1+ uses --vendor-data; older PVE uses --cicustom vendor=
$lines[] = 'qm set $VMID --vendor-data ' . escapeshellarg('local:snippets/ci_vendor_' . $vmid . '.yaml') . ' 2>/dev/null || qm set $VMID --cicustom ' . escapeshellarg('vendor=local:snippets/ci_vendor_' . $vmid . '.yaml') . ' 2>/dev/null || true';

$lines[] = '';
$lines[] = "echo '==> [7/8] Resizing disk to " . (int)$diskSize . "G...'";
$lines[] = 'qm resize $VMID scsi0 ' . (int)$diskSize . 'G';
$lines[] = '';
$lines[] = "echo '==> [8/8] Starting VM...'";
$lines[] = 'qm start $VMID';
$lines[] = 'rm -f "$IMG" ' . escapeshellarg($vendorFile);
$lines[] = "echo ''";
$lines[] = "echo '==> Done! VM \$VMID (" . addslashes($name) . ") is starting.'";
$lines[] = "echo '    The QEMU guest agent will install on first boot — the IP appears in the dashboard once it is running.'";

// Base64-encode the script so it survives SSH argument escaping without issues
$script    = implode("\n", $lines);
$b64Script = base64_encode($script);

// ── Create terminal session token ────────────────────────────────────────────
$token    = bin2hex(random_bytes(16));
$dataFile = sys_get_temp_dir() . '/term_sess_' . $token . '.json';

file_put_contents($dataFile, json_encode([
    'ssh_host'       => $sshHost,
    'user_id'        => $user['id'],
    'expires'        => time() + 600,
    'direct_command' => 'echo ' . escapeshellarg($b64Script) . ' | base64 -d | bash',
]), LOCK_EX);

Response::success(['token' => $token]);
