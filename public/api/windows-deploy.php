<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Config;
use App\Helpers;
use App\Database;
use App\AppLogger;

Bootstrap::init();
Request::requireMethod('POST');
Request::validateCsrf();

$user = Auth::requirePermission('template.deploy');
$body = Request::jsonBody();

// ── Validate inputs ─────────────────────────────────────────────────────────
$imageId = (int)($body['image_id'] ?? 0);
$db = Database::connection();
$stmt = $db->prepare('SELECT * FROM windows_images WHERE id = ?');
$stmt->execute([$imageId]);
$image = $stmt->fetch(\PDO::FETCH_ASSOC);
if (!$image) Response::error('Windows image not found', 404);

$vmid = (int)($body['vmid'] ?? 0);
if ($vmid < 100 || $vmid > 999999999) Response::error('VMID must be between 100 and 999999999', 400);

$name = $body['name'] ?? '';
if (!$name || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.\-]{0,62}$/', $name)) {
    Response::error('Invalid VM name', 400);
}

$nodeName = $body['node'] ?? '';
if (!$nodeName || !Helpers::validateNodeName($nodeName)) Response::error('Invalid node name', 400);

$storage = $body['storage'] ?? '';
if (!$storage || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]{0,63}$/', $storage)) Response::error('Invalid storage name', 400);

$bridge = $body['bridge'] ?? '';
if (!$bridge || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9\-_]{0,15}$/', $bridge)) Response::error('Invalid bridge name', 400);

$cores = max(1, min(128, (int)($body['cores'] ?? 2)));
$memory = max(2048, min(131072, (int)($body['memory'] ?? 4096)));
$diskSize = max(30, min(10000, (int)($body['disk_size'] ?? 64)));

$isoStorage = 'local';
$isoFile = $image['iso_filename'];
$tags = preg_replace('/[^a-z0-9\-_;]/', '', strtolower($body['tags'] ?? ''));

// ── Resolve SSH host ────────────────────────────────────────────────────────
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

// ── Build deployment script ─────────────────────────────────────────────────
$lines = [];
$lines[] = 'export PATH="/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"';
$lines[] = 'set -e';
$lines[] = "trap 'echo \"ERROR: failed at line \$LINENO (exit \$?)\"' ERR";
$lines[] = 'VMID=' . (int)$vmid;
$lines[] = '';
// Clean up previous attempts
$lines[] = 'qm stop $VMID --skiplock 2>/dev/null || true';
$lines[] = 'qm destroy $VMID --purge --skiplock 2>/dev/null || true';
$lines[] = '';

// Step 1: Create VM optimized for Windows
$lines[] = 'echo "==> [1/5] Creating Windows VM $VMID..."';
$lines[] = 'qm create $VMID'
    . ' --name ' . escapeshellarg($name)
    . ' --memory ' . (int)$memory
    . ' --cores ' . (int)$cores
    . ' --net0 virtio,bridge=' . escapeshellarg($bridge)
    . ' --ostype win11'
    . ' --cpu host'
    . ' --bios ovmf'
    . ' --machine pc-q35-9.0'
    . ' --efidisk0 ' . escapeshellarg($storage) . ':1,efitype=4m,pre-enrolled-keys=1'
    . ' --tpmstate0 ' . escapeshellarg($storage) . ':1,version=v2.0'
    . ' --agent enabled=1';
$lines[] = '';

// Step 2: Configure hardware
$lines[] = "echo '==> [2/5] Configuring hardware...'";
$lines[] = 'if [ ! -f ' . escapeshellarg('/var/lib/vz/template/iso/' . $isoFile) . ' ]; then echo "ERROR: ISO not found on this node. Distribute the image first via Custom Images."; exit 1; fi';
$lines[] = 'qm set $VMID --scsihw virtio-scsi-pci --scsi0 ' . escapeshellarg($storage) . ':' . (int)$diskSize . ',discard=on,ssd=1';
$lines[] = 'qm set $VMID --ide2 ' . escapeshellarg($isoStorage . ':iso/' . $isoFile) . ',media=cdrom';
$lines[] = 'qm set $VMID --boot order=ide2';

// VirtIO drivers ISO (if available)
$lines[] = '';
$lines[] = "echo '==> [3/5] Mounting VirtIO drivers...'";
$lines[] = '# Try to find VirtIO ISO on the node';
$lines[] = 'VIRTIO_ISO=$(find /var/lib/vz/template/iso/ -iname "virtio-win*.iso" 2>/dev/null | head -1)';
$lines[] = 'if [ -n "$VIRTIO_ISO" ]; then';
$lines[] = '  VIRTIO_BASENAME=$(basename "$VIRTIO_ISO")';
$lines[] = '  qm set $VMID --ide0 ' . escapeshellarg($isoStorage) . ':iso/"$VIRTIO_BASENAME",media=cdrom';
$lines[] = '  echo "    VirtIO ISO: $VIRTIO_BASENAME"';
$lines[] = 'else';
$lines[] = '  echo "    WARNING: No VirtIO ISO found in /var/lib/vz/template/iso/"';
$lines[] = '  echo "    Download from: https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso"';
$lines[] = 'fi';

// Step 4: Create and mount floppy with autounattend.xml
$lines[] = '';
$lines[] = "echo '==> [4/5] Preparing unattended install...'";

if ($image['autounattend_xml']) {
    $floppyDir = '/tmp/pve_win_' . $vmid . '_floppy';
    $floppyImg = '/tmp/pve_win_' . $vmid . '_floppy.img';

    $lines[] = 'mkdir -p ' . escapeshellarg($floppyDir);

    // Write autounattend.xml
    $xmlContent = $image['autounattend_xml'];
    // Inject product key if provided
    if ($image['product_key']) {
        // Replace placeholder or inject key
        if (str_contains($xmlContent, '{{PRODUCT_KEY}}')) {
            $xmlContent = str_replace('{{PRODUCT_KEY}}', $image['product_key'], $xmlContent);
        }
    }
    $lines[] = 'cat > ' . escapeshellarg($floppyDir . '/autounattend.xml') . " << 'WIN_XML_EOF'";
    $lines[] = $xmlContent;
    $lines[] = 'WIN_XML_EOF';

    // Create a post-install script to install QEMU guest agent
    if ($image['install_guest_tools']) {
        $lines[] = 'cat > ' . escapeshellarg($floppyDir . '/install-guest-agent.cmd') . " << 'WIN_CMD_EOF'";
        $lines[] = '@echo off';
        $lines[] = 'echo Installing QEMU Guest Agent...';
        $lines[] = 'for %%d in (D E F G) do (';
        $lines[] = '    if exist "%%d:\\guest-agent\\qemu-ga-x86_64.msi" (';
        $lines[] = '        msiexec /i "%%d:\\guest-agent\\qemu-ga-x86_64.msi" /qn /norestart';
        $lines[] = '        goto :done';
        $lines[] = '    )';
        $lines[] = ')';
        $lines[] = ':done';
        $lines[] = 'echo Guest agent installation complete.';
        $lines[] = 'WIN_CMD_EOF';
    }

    // Create floppy image
    $lines[] = 'apt-get install -y dosfstools >/dev/null 2>&1 || true';
    $lines[] = 'dd if=/dev/zero of=' . escapeshellarg($floppyImg) . ' bs=1440K count=1 2>/dev/null';
    $lines[] = 'mkfs.fat -F 12 ' . escapeshellarg($floppyImg);
    $lines[] = 'mcopy -i ' . escapeshellarg($floppyImg) . ' ' . escapeshellarg($floppyDir . '/autounattend.xml') . ' ::';
    if ($image['install_guest_tools']) {
        $lines[] = 'mcopy -i ' . escapeshellarg($floppyImg) . ' ' . escapeshellarg($floppyDir . '/install-guest-agent.cmd') . ' ::';
    }

    // Copy floppy to Proxmox snippets and mount
    $lines[] = 'mkdir -p /var/lib/vz/template/iso/';
    $lines[] = 'cp ' . escapeshellarg($floppyImg) . ' /var/lib/vz/template/iso/win_floppy_' . $vmid . '.img';
    $lines[] = 'qm set $VMID --floppy0 ' . escapeshellarg($isoStorage . ':iso/win_floppy_' . $vmid . '.img');
    $lines[] = 'rm -rf ' . escapeshellarg($floppyDir) . ' ' . escapeshellarg($floppyImg);
    $lines[] = "echo '    Autounattend.xml injected via floppy'";
} else {
    $lines[] = "echo '    No autounattend.xml configured — manual installation required'";
}

if ($tags) {
    $lines[] = 'qm set $VMID --tags ' . escapeshellarg($tags);
}

// Step 5: Start VM
$lines[] = '';
$lines[] = "echo '==> [5/5] Starting VM...'";
$lines[] = 'qm start $VMID';
$lines[] = "echo ''";
if ($image['autounattend_xml']) {
    $lines[] = 'echo "==> Done! VM $VMID (' . addslashes($name) . ') is booting into Windows unattended setup."';
    $lines[] = "echo '    Installation will complete automatically. Guest agent will be installed post-setup.'";
} else {
    $lines[] = 'echo "==> Done! VM $VMID (' . addslashes($name) . ') is booting from the Windows ISO."';
    $lines[] = "echo '    Connect via VNC/console to complete the installation.'";
}

// Base64-encode and create terminal session
$script = implode("\n", $lines);
$b64Script = base64_encode($script);

$token = bin2hex(random_bytes(16));
$dataFile = sys_get_temp_dir() . '/term_sess_' . $token . '.json';

file_put_contents($dataFile, json_encode([
    'ssh_host'       => $sshHost,
    'user_id'        => $user['id'],
    'expires'        => time() + 600,
    'direct_command' => 'echo ' . escapeshellarg($b64Script) . ' | base64 -d | bash',
]), LOCK_EX);

AppLogger::info('deploy', "Windows deploy VM {$vmid} ({$name}) on {$nodeName} with {$image['name']}", [
    'iso' => $isoFile, 'cores' => $cores, 'memory' => $memory, 'disk' => $diskSize,
], $user['id'] ?? null);

Response::success(['token' => $token]);
