<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;
use App\Helpers;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();

$user = Auth::requirePermission('template.deploy');
\App\Config::requireSsh();
$body = Request::jsonBody();

// ── Allowed image catalog ────────────────────────────────────────────────────
// ── All available cloud images, grouped by distro family ─────────────────────
$ALL_CLOUD_IMAGES = [
    'ubuntu' => [
        'ubuntu-24.04' => ['name' => 'Ubuntu 24.04 LTS (Noble)',  'url' => 'https://cloud-images.ubuntu.com/noble/current/noble-server-cloudimg-amd64.img', 'default_user' => 'ubuntu'],
        'ubuntu-22.04' => ['name' => 'Ubuntu 22.04 LTS (Jammy)',  'url' => 'https://cloud-images.ubuntu.com/jammy/current/jammy-server-cloudimg-amd64.img', 'default_user' => 'ubuntu'],
        'ubuntu-20.04' => ['name' => 'Ubuntu 20.04 LTS (Focal)',  'url' => 'https://cloud-images.ubuntu.com/focal/current/focal-server-cloudimg-amd64.img', 'default_user' => 'ubuntu'],
    ],
    'debian' => [
        'debian-12' => ['name' => 'Debian 12 (Bookworm)', 'url' => 'https://cloud.debian.org/images/cloud/bookworm/latest/debian-12-genericcloud-amd64.qcow2', 'default_user' => 'debian'],
        'debian-11' => ['name' => 'Debian 11 (Bullseye)', 'url' => 'https://cloud.debian.org/images/cloud/bullseye/latest/debian-11-genericcloud-amd64.qcow2', 'default_user' => 'debian'],
    ],
    'rocky' => [
        'rocky-9' => ['name' => 'Rocky Linux 9', 'url' => 'https://dl.rockylinux.org/pub/rocky/9/images/x86_64/Rocky-9-GenericCloud.latest.x86_64.qcow2', 'default_user' => 'rocky'],
    ],
    'alma' => [
        'almalinux-9' => ['name' => 'AlmaLinux 9', 'url' => 'https://repo.almalinux.org/almalinux/9/cloud/x86_64/images/AlmaLinux-9-GenericCloud-latest.x86_64.qcow2', 'default_user' => 'almalinux'],
    ],
    'centos' => [
        'centos-stream-9' => ['name' => 'CentOS Stream 9', 'url' => 'https://cloud.centos.org/centos/9-stream/x86_64/images/CentOS-Stream-GenericCloud-9-latest.x86_64.qcow2', 'default_user' => 'cloud-user'],
    ],
    'fedora' => [
        'fedora-41' => ['name' => 'Fedora 41 Cloud', 'url' => 'https://download.fedoraproject.org/pub/fedora/linux/releases/41/Cloud/x86_64/images/Fedora-Cloud-Base-Generic-41-1.4.x86_64.qcow2', 'default_user' => 'fedora'],
    ],
    'opensuse' => [
        'opensuse-leap-15.6' => ['name' => 'openSUSE Leap 15.6', 'url' => 'https://download.opensuse.org/distribution/leap/15.6/appliances/openSUSE-Leap-15.6-Minimal-VM.x86_64-Cloud.qcow2', 'default_user' => 'opensuse'],
    ],
    'arch' => [
        'arch-linux' => ['name' => 'Arch Linux (latest)', 'url' => 'https://geo.mirror.pkgbuild.com/images/latest/Arch-Linux-x86_64-cloudimg.qcow2', 'default_user' => 'arch'],
    ],
];

// Filter by enabled distro families (CLOUD_DISTROS env, comma-separated, default: all)
$enabledDistros = array_filter(array_map('trim', explode(',', Config::get('CLOUD_DISTROS', 'ubuntu,debian,rocky,alma,centos,fedora,opensuse,arch'))));
$CLOUD_IMAGES = [];
foreach ($enabledDistros as $distro) {
    if (isset($ALL_CLOUD_IMAGES[$distro])) {
        $CLOUD_IMAGES = array_merge($CLOUD_IMAGES, $ALL_CLOUD_IMAGES[$distro]);
    }
}

// ── Validate inputs ──────────────────────────────────────────────────────────
$imageId = $body['image_id'] ?? '';
$isCustom = str_starts_with($imageId, 'custom:');

if ($isCustom) {
    $customId = (int) substr($imageId, 7);
    $db = \App\Database::connection();
    $stmt = $db->prepare('SELECT * FROM custom_images WHERE id = ?');
    $stmt->execute([$customId]);
    $customImg = $stmt->fetch();
    if (!$customImg) Response::error('Custom image not found', 404);

    $image = [
        'name'         => $customImg['name'],
        'url'          => '',
        'default_user' => $customImg['default_user'],
        'ostype'       => $customImg['ostype'],
        'custom_file'  => $customImg['filename'],
    ];
} else {
    if (!isset($CLOUD_IMAGES[$imageId])) {
        Response::error('Invalid image ID', 400);
    }
    $image = $CLOUD_IMAGES[$imageId];
}

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

$vlanTag = isset($body['net_vlan']) ? (int)$body['net_vlan'] : 0;
if ($vlanTag && ($vlanTag < 1 || $vlanTag > 4094)) {
    Response::error('VLAN tag must be between 1 and 4094', 400);
}

$cores = (int)($body['cores'] ?? 2);
if ($cores < 1 || $cores > 128) {
    Response::error('CPU cores must be between 1 and 128', 400);
}
// Check vCPU capacity on target node
Helpers::checkNodeCpuCapacity(Helpers::createAPI(), $nodeName, $cores);

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
$lines[] = 'qm stop $VMID --skiplock 2>/dev/null || true';
$lines[] = 'qm destroy $VMID --purge --skiplock 2>/dev/null || true';
// Remove orphaned cloudinit disk (e.g. RBD image left from a failed run on another node)
$lines[] = 'pvesm free ' . escapeshellarg($storage . ':vm-' . $vmid . '-cloudinit') . ' 2>/dev/null || true';
$lines[] = '';
$lines[] = "echo ''";
$customFile = $image['custom_file'] ?? '';
if ($customFile) {
    // Custom image: use local copy on the node
    $isIso = (bool)preg_match('/\.iso$/i', $customFile);
    $remoteSrc = $isIso
        ? '/var/lib/vz/template/iso/' . $customFile
        : '/var/lib/vz/template/custom/' . $customFile;
    $lines[] = "echo '==> [1/8] Using custom image " . addslashes($image['name']) . "...'";
    $lines[] = 'if [ ! -f ' . escapeshellarg($remoteSrc) . ' ]; then echo "ERROR: Custom image not found at ' . addslashes($remoteSrc) . ' — distribute it first."; exit 1; fi';
    $lines[] = 'cp ' . escapeshellarg($remoteSrc) . ' "$IMG"';
} else {
    // Built-in image: download
    AppLogger::debug('http', 'External request: cloud image download', ['url' => $image['url'], 'image' => $imageId, 'node' => $nodeName]);
    $lines[] = "echo '==> [1/8] Downloading " . addslashes($image['name']) . "...'";
    $compressed = $image['compressed'] ?? '';
    if ($compressed === 'xz') {
        $lines[] = 'if command -v wget >/dev/null 2>&1; then wget -q --show-progress -O "${IMG}.xz" ' . escapeshellarg($image['url']) . '; else curl -L --progress-bar -o "${IMG}.xz" ' . escapeshellarg($image['url']) . '; fi';
        $lines[] = "echo '    Decompressing (xz)...'";
        $lines[] = 'xz -d "${IMG}.xz"';
    } else {
        $lines[] = 'if command -v wget >/dev/null 2>&1; then wget -q --show-progress -O "$IMG" ' . escapeshellarg($image['url']) . '; else curl -L --progress-bar -o "$IMG" ' . escapeshellarg($image['url']) . '; fi';
    }
}
$lines[] = '';
$lines[] = 'echo "==> [2/8] Creating VM $VMID..."';
$lines[] = 'qm create $VMID'
    . ' --name ' . escapeshellarg($name)
    . ' --memory ' . (int)$memory
    . ' --cores ' . (int)$cores
    . ' --net0 virtio,bridge=' . escapeshellarg($bridge) . ($vlanTag ? ',tag=' . $vlanTag : '')
    . ' --ostype ' . escapeshellarg($image['ostype'] ?? 'l26') . ' --cpu host';
$lines[] = '';
$lines[] = "echo '==> [3/8] Importing disk (may take a while)...'";
$lines[] = 'qm importdisk $VMID "$IMG" ' . escapeshellarg($storage) . ' --format qcow2';
$lines[] = '';
$lines[] = "echo '==> [4/8] Configuring hardware...'";
$lines[] = "DISK=\$(qm config \$VMID | grep '^unused0:' | awk '{print \$2}')";
$lines[] = 'qm set $VMID --scsihw virtio-scsi-pci --scsi0 "${DISK},discard=on,ssd=1"';
$lines[] = 'qm set $VMID --ide2 ' . escapeshellarg($storage) . ':cloudinit';
$lines[] = 'qm set $VMID --boot order=scsi0';
$lines[] = 'qm set $VMID --serial0 socket --vga std';
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

$lines[] = '';
$lines[] = "echo '==> [6/8] Configuring QEMU guest agent installation...'";
// Find a storage that supports snippets (auto-detect, don't hardcode 'local')
$lines[] = '# Find a storage with snippets support';
$lines[] = 'SNIPPET_STORAGE=""';
$lines[] = 'SNIPPET_DIR=""';
// Check all enabled storages for snippets content type
$lines[] = 'for sid in $(pvesm status --enabled 2>/dev/null | awk "NR>1 {print \$1}"); do';
$lines[] = '  if pvesm status --enabled 2>/dev/null | grep "^${sid} " | grep -q snippets 2>/dev/null; then';
$lines[] = '    SNIPPET_STORAGE="$sid"';
$lines[] = '    break';
$lines[] = '  fi';
$lines[] = 'done';
// Alternative: parse storage.cfg for snippet support
$lines[] = 'if [ -z "$SNIPPET_STORAGE" ]; then';
$lines[] = '  while IFS= read -r line; do';
$lines[] = '    if echo "$line" | grep -qE "^(dir|nfs|cifs|glusterfs|btrfs): "; then';
$lines[] = '      sid=$(echo "$line" | awk "{print \$2}")';
$lines[] = '    elif echo "$line" | grep -q "content " && echo "$line" | grep -q "snippets"; then';
$lines[] = '      # Check this storage is not disabled';
$lines[] = '      if pvesm status --enabled 2>/dev/null | grep -q "^${sid} "; then';
$lines[] = '        SNIPPET_STORAGE="$sid"';
$lines[] = '        break';
$lines[] = '      fi';
$lines[] = '    fi';
$lines[] = '  done < /etc/pve/storage.cfg';
$lines[] = 'fi';
// If still no snippet storage, try to enable snippets on 'local' (if enabled) or the target storage
$lines[] = 'if [ -z "$SNIPPET_STORAGE" ]; then';
$lines[] = '  for try_sid in local ' . escapeshellarg($storage) . '; do';
$lines[] = '    if pvesm status --enabled 2>/dev/null | grep -q "^${try_sid} "; then';
$lines[] = '      STYPE=$(pvesm status 2>/dev/null | awk "/^${try_sid} /{print \$2}")';
$lines[] = '      if echo "$STYPE" | grep -qE "^(dir|nfs|cifs|glusterfs|btrfs)$"; then';
$lines[] = '        echo "    Enabling snippets on ${try_sid} storage..."';
$lines[] = '        CURRENT_CONTENT=$(pvesm status 2>/dev/null | awk "/^${try_sid} /{for(i=3;i<=NF;i++) printf \"%s \",\$i}" | grep -oP "content=\\K[^ ]*" || true)';
$lines[] = '        if [ -n "$CURRENT_CONTENT" ]; then';
$lines[] = '          pvesm set "$try_sid" --content "${CURRENT_CONTENT},snippets" 2>/dev/null || true';
$lines[] = '        else';
$lines[] = '          pvesm set "$try_sid" --content "snippets" 2>/dev/null || true';
$lines[] = '        fi';
$lines[] = '        sleep 1';
$lines[] = '        SNIPPET_STORAGE="$try_sid"';
$lines[] = '        break';
$lines[] = '      fi';
$lines[] = '    fi';
$lines[] = '  done';
$lines[] = 'fi';
// Resolve snippet path and create vendor cloud-init file
$vendorFileName = 'ci_vendor_' . $vmid . '.yaml';
$lines[] = 'if [ -n "$SNIPPET_STORAGE" ]; then';
// Use pvesm path on the exact volume ID so the file is written exactly where Proxmox expects it
$lines[] = '  SNIPPET_VOL="${SNIPPET_STORAGE}:snippets/' . $vendorFileName . '"';
$lines[] = '  SNIPPET_PATH=$(pvesm path "$SNIPPET_VOL" 2>/dev/null || echo "")';
$lines[] = '  if [ -z "$SNIPPET_PATH" ]; then';
// Fallback: resolve storage base path and construct snippets path manually
$lines[] = '    STYPE=$(grep -A5 "^\\(dir\\|nfs\\|cifs\\|btrfs\\|glusterfs\\): ${SNIPPET_STORAGE}$" /etc/pve/storage.cfg 2>/dev/null | grep "path " | awk "{print \$2}" || echo "")';
$lines[] = '    SBASE="${STYPE:-/var/lib/vz}"';
$lines[] = '    SNIPPET_PATH="${SBASE}/snippets/' . $vendorFileName . '"';
$lines[] = '  fi';
$lines[] = '  SNIPPET_DIR="$(dirname "$SNIPPET_PATH")"';
$lines[] = '  mkdir -p "$SNIPPET_DIR"';
$lines[] = '  cat > "$SNIPPET_PATH" << \'CI_VENDOR_EOF\'';
$lines[] = '#cloud-config';
$lines[] = 'packages:';
$lines[] = '  - qemu-guest-agent';
foreach ($ciPackages as $pkg) {
    $lines[] = '  - ' . $pkg;
}
$lines[] = 'runcmd:';
$lines[] = '  - ["sh", "-c", "command -v systemctl >/dev/null 2>&1 && { systemctl daemon-reload; systemctl enable --now qemu-guest-agent; } || { service qemu-guest-agent start 2>/dev/null || true; }"]';
foreach ($ciRuncmd as $cmd) {
    $lines[] = '  - ' . json_encode($cmd);
}
$lines[] = 'CI_VENDOR_EOF';
$lines[] = '  sync';
// Verify the file actually exists before attaching
$lines[] = '  if [ -f "$SNIPPET_PATH" ]; then';
$lines[] = '    echo "    Attaching vendor cloud-init snippet (${SNIPPET_STORAGE})..."';
$lines[] = '    qm set $VMID --cicustom "vendor=${SNIPPET_VOL}" 2>/dev/null || echo "    Warning: could not attach vendor snippet (qemu-guest-agent will need manual install)"';
$lines[] = '  else';
$lines[] = '    echo "    Warning: Snippet file not found at $SNIPPET_PATH — skipping vendor cloud-init"';
$lines[] = '  fi';
$lines[] = 'else';
$lines[] = '  echo "    Warning: No snippet-capable storage found — skipping vendor cloud-init"';
$lines[] = '  echo "    (qemu-guest-agent will need to be installed manually: apt install qemu-guest-agent)"';
$lines[] = 'fi';

$lines[] = '';
$lines[] = "echo '==> [7/8] Resizing disk to " . (int)$diskSize . "G...'";
$lines[] = 'qm resize $VMID scsi0 ' . (int)$diskSize . 'G';
$lines[] = '';
$lines[] = "echo '==> [8/8] Starting VM...'";
// Try starting the VM. If it fails due to snippet volume issues, remove cicustom and retry.
// The cloud-init ISO already contains user/network data; vendor-data (qemu-guest-agent) is optional.
$lines[] = 'if ! qm start $VMID 2>&1; then';
$lines[] = '  echo "    Start failed — removing cicustom snippet reference and retrying..."';
$lines[] = '  qm set $VMID --delete cicustom 2>/dev/null || true';
$lines[] = '  if [ -n "$SNIPPET_PATH" ]; then rm -f "$SNIPPET_PATH"; fi';
$lines[] = '  qm start $VMID';
$lines[] = '  echo "    Warning: VM started without vendor cloud-init — install qemu-guest-agent manually: apt install qemu-guest-agent"';
$lines[] = 'else';
// Remove cicustom reference after successful start — no longer needed and prevents
// "volume does not exist" errors after migration to another node (snippets are per-node).
$lines[] = '  echo "    Removing cloud-init snippet reference (no longer needed after first boot)..."';
$lines[] = '  qm set $VMID --delete cicustom 2>/dev/null || true';
$lines[] = '  if [ -n "$SNIPPET_PATH" ]; then rm -f "$SNIPPET_PATH"; fi';
$lines[] = 'fi';
$lines[] = 'rm -f "$IMG"';

$lines[] = "echo ''";
$lines[] = 'echo "==> Done! VM $VMID (' . addslashes($name) . ') is starting."';
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
chmod($dataFile, 0600);

AppLogger::info('deploy', "Cloud-init deploy VM {$vmid} ({$name}) on {$nodeName} with {$image['name']}", [
    'image' => $imageId, 'cores' => $cores, 'memory' => $memory, 'disk' => $diskSize,
], $user['id'] ?? null);

Response::success(['token' => $token]);
