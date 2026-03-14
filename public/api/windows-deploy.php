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

$vlanTag = isset($body['net_vlan']) ? (int)$body['net_vlan'] : 0;
if ($vlanTag && ($vlanTag < 1 || $vlanTag > 4094)) Response::error('VLAN tag must be between 1 and 4094', 400);

$cores = max(1, min(128, (int)($body['cores'] ?? 2)));
$memory = max(2048, min(131072, (int)($body['memory'] ?? 4096)));
$diskSize = max(30, min(10000, (int)($body['disk_size'] ?? 64)));

// Check vCPU capacity on target node
$api = Helpers::createAPI();
Helpers::checkNodeCpuCapacity($api, $nodeName, $cores);

$isoFile = $image['iso_filename'];
$tags = preg_replace('/[^a-z0-9\-_;]/', '', strtolower($body['tags'] ?? ''));

// Find which storage holds the ISO
$isoStorage = 'local'; // fallback
try {
    $storages = $api->getStorages($nodeName, 'iso');
    foreach (($storages['data'] ?? []) as $stor) {
        $sid = $stor['storage'] ?? '';
        if (!$sid) continue;
        try {
            $contents = $api->get("/nodes/{$nodeName}/storage/{$sid}/content", ['content' => 'iso']);
            foreach (($contents['data'] ?? []) as $entry) {
                $volid = $entry['volid'] ?? '';
                // volid format: "storage:iso/filename.iso"
                if (str_ends_with($volid, '/' . $isoFile)) {
                    $isoStorage = $sid;
                    break 2;
                }
            }
        } catch (\Exception $e) { /* skip this storage */ }
    }
} catch (\Exception $e) { /* fallback to 'local' */ }

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
    . ' --net0 virtio,bridge=' . escapeshellarg($bridge) . ($vlanTag ? ',tag=' . $vlanTag : '')
    . ' --ostype win11'
    . ' --cpu host'
    . ' --bios ovmf'
    . ' --machine pc-q35-9.0'
    . ' --efidisk0 ' . escapeshellarg($storage) . ':1,efitype=4m,pre-enrolled-keys=0'
    . ' --agent enabled=1';
$lines[] = '';

// TPM 2.0: try to add, skip on failure (swtpm can fail on NFS/shared storage)
$lines[] = 'echo "    Adding TPM 2.0..."';
$lines[] = 'if ! qm set $VMID --tpmstate0 ' . escapeshellarg($storage) . ':1,version=v2.0 2>/dev/null; then';
$lines[] = '  echo "    WARNING: TPM 2.0 could not be added (swtpm init failed — common on shared/NFS storage)."';
$lines[] = '  echo "    The VM will work without TPM. Windows 11 requires TPM, Windows Server does not."';
$lines[] = 'fi';
$lines[] = '';

// Step 2: Configure hardware
$lines[] = "echo '==> [2/5] Configuring hardware...'";
$lines[] = 'ISO_PATH=$(pvesm path ' . escapeshellarg($isoStorage . ':iso/' . $isoFile) . ' 2>/dev/null)';
$lines[] = 'if [ -z "$ISO_PATH" ] || [ ! -f "$ISO_PATH" ]; then echo "ERROR: ISO not found on storage ' . escapeshellarg($isoStorage) . '. Distribute the image first via Custom Images."; exit 1; fi';
$lines[] = 'qm set $VMID --scsihw virtio-scsi-pci --scsi0 ' . escapeshellarg($storage) . ':' . (int)$diskSize . ',discard=on,ssd=1';
$lines[] = 'qm set $VMID --ide2 ' . escapeshellarg($isoStorage . ':iso/' . $isoFile) . ',media=cdrom';
$lines[] = 'qm set $VMID --boot order="ide2;scsi0"';

// VirtIO drivers ISO (if available)
$lines[] = '';
$lines[] = "echo '==> [3/5] Mounting VirtIO drivers...'";
$lines[] = '# Try to find VirtIO ISO on the same storage';
$lines[] = 'ISO_STORE_PATH=$(pvesm path ' . escapeshellarg($isoStorage . ':iso/dummy') . ' 2>/dev/null | sed "s|/dummy$||")';
$lines[] = 'VIRTIO_ISO=$(find "$ISO_STORE_PATH" /var/lib/vz/template/iso/ -iname "virtio-win*.iso" 2>/dev/null | head -1)';
$lines[] = 'if [ -n "$VIRTIO_ISO" ]; then';
$lines[] = '  VIRTIO_BASENAME=$(basename "$VIRTIO_ISO")';
$lines[] = '  qm set $VMID --ide0 ' . escapeshellarg($isoStorage) . ':iso/"$VIRTIO_BASENAME",media=cdrom';
$lines[] = '  echo "    VirtIO ISO: $VIRTIO_BASENAME"';
$lines[] = 'else';
$lines[] = '  echo "    WARNING: No VirtIO ISO found on storage ' . $isoStorage . '"';
$lines[] = '  echo "    Download from: https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso"';
$lines[] = 'fi';

// Step 4: Create and mount autounattend ISO
$lines[] = '';
$lines[] = "echo '==> [4/5] Preparing unattended install...'";

if ($image['autounattend_xml']) {
    $unattendDir = '/tmp/pve_win_' . $vmid . '_unattend';
    $unattendIso = '/tmp/pve_win_' . $vmid . '_unattend.iso';

    $lines[] = 'mkdir -p ' . escapeshellarg($unattendDir);

    // Write autounattend.xml
    $xmlContent = $image['autounattend_xml'];
    // Inject product key if provided
    if ($image['product_key']) {
        if (str_contains($xmlContent, '{{PRODUCT_KEY}}')) {
            $xmlContent = str_replace('{{PRODUCT_KEY}}', $image['product_key'], $xmlContent);
        }
    }
    $lines[] = 'cat > ' . escapeshellarg($unattendDir . '/autounattend.xml') . " << 'WIN_XML_EOF'";
    $lines[] = $xmlContent;
    $lines[] = 'WIN_XML_EOF';

    // Create a post-install script to install QEMU guest agent
    if ($image['install_guest_tools']) {
        $lines[] = 'cat > ' . escapeshellarg($unattendDir . '/install-guest-agent.cmd') . " << 'WIN_CMD_EOF'";
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

    // Create ISO image with autounattend files
    $lines[] = 'apt-get install -y genisoimage >/dev/null 2>&1 || true';
    $lines[] = 'genisoimage -J -r -o ' . escapeshellarg($unattendIso) . ' ' . escapeshellarg($unattendDir) . ' 2>/dev/null';

    // Copy ISO to local storage (always directory-based, works reliably) and mount as CD-ROM
    $lines[] = 'mkdir -p /var/lib/vz/template/iso';
    $lines[] = 'cp ' . escapeshellarg($unattendIso) . ' /var/lib/vz/template/iso/win_unattend_' . $vmid . '.iso';
    $lines[] = 'qm set $VMID --sata0 local:iso/win_unattend_' . $vmid . '.iso,media=cdrom';
    $lines[] = 'rm -rf ' . escapeshellarg($unattendDir) . ' ' . escapeshellarg($unattendIso);
    $lines[] = "echo '    Autounattend.xml injected via ISO'";
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
$lines[] = "echo '    Waiting for UEFI boot...'";
$lines[] = '# Send keystrokes in background to catch "Press any key to boot from CD" prompt';
$lines[] = '# UEFI+TPM+EFI disk init can take 10-20s, so keep pressing over a wide window';
$lines[] = '(for i in $(seq 1 30); do qm sendkey $VMID ret 2>/dev/null; sleep 1; done) &';
$lines[] = 'SENDKEY_PID=$!';
$lines[] = 'sleep 15';
$lines[] = "echo '    Boot keystrokes sent.'";
$lines[] = 'kill $SENDKEY_PID 2>/dev/null || true';
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
