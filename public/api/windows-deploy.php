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

// ── Resolve SSH host ────────────────────────────────────────────────────────
$envKey  = 'SSH_HOST_' . strtoupper(str_replace('-', '_', $nodeName));
$sshHost = Config::get($envKey, '');
if (!$sshHost) {
    $sshHost = $nodeName;
    try {
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

// ── Check if ISO exists on the target node ──────────────────────────────────
$needsDistribute = false;
$isoStorage = null;
$localIsoPath = '';
try {
    $storages = $api->getStorages($nodeName, 'iso');
    foreach (($storages['data'] ?? []) as $stor) {
        $sid = $stor['storage'] ?? '';
        if (!$sid) continue;
        try {
            $contents = $api->get("/nodes/{$nodeName}/storage/{$sid}/content", ['content' => 'iso']);
            foreach (($contents['data'] ?? []) as $entry) {
                if (str_ends_with($entry['volid'] ?? '', '/' . $isoFile)) {
                    $isoStorage = $sid;
                    break 2;
                }
            }
        } catch (\Exception $e) {}
    }
} catch (\Exception $e) {}

if ($isoStorage === null) {
    // ISO not on any node storage — check if we can distribute from Custom Images
    $imagesDir = realpath(__DIR__ . '/../../data/images');
    $localIsoPath = $imagesDir ? $imagesDir . '/' . $isoFile : '';
    if ($localIsoPath && file_exists($localIsoPath)) {
        $needsDistribute = true;
        $isoStorage = 'local'; // SCP puts it in /var/lib/vz/template/iso/ = local storage
    } else {
        Response::error("ISO '{$isoFile}' not found — neither on node '{$nodeName}' nor in Custom Images. Upload the ISO first.", 404);
    }
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
$lines[] = '  echo "    VirtIO ISO not found — downloading automatically..."';
$lines[] = '  VIRTIO_URL="https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso"';
$lines[] = '  VIRTIO_DEST="$ISO_STORE_PATH/virtio-win.iso"';
$lines[] = '  if [ -z "$ISO_STORE_PATH" ]; then VIRTIO_DEST="/var/lib/vz/template/iso/virtio-win.iso"; fi';
$lines[] = '  if wget -q --show-progress -O "$VIRTIO_DEST" "$VIRTIO_URL" 2>&1; then';
$lines[] = '    VIRTIO_BASENAME=$(basename "$VIRTIO_DEST")';
$lines[] = '    qm set $VMID --ide0 ' . escapeshellarg($isoStorage) . ':iso/"$VIRTIO_BASENAME",media=cdrom';
$lines[] = '    echo "    VirtIO ISO downloaded and mounted: $VIRTIO_BASENAME"';
$lines[] = '  else';
$lines[] = '    echo "ERROR: VirtIO drivers are required for Windows deployment but could not be downloaded."';
$lines[] = '    echo "Please download manually: https://fedorapeople.org/groups/virt/virtio-win/direct-downloads/stable-virtio/virtio-win.iso"';
$lines[] = '    echo "Place it in: $ISO_STORE_PATH/ or /var/lib/vz/template/iso/"';
$lines[] = '    qm destroy $VMID --purge --skiplock 2>/dev/null || true';
$lines[] = '    exit 1';
$lines[] = '  fi';
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

    // Inject QEMU guest agent installation into FirstLogonCommands
    if ($image['install_guest_tools']) {
        $guestAgentCmd = '<SynchronousCommand wcm:action="add">'
            . '<Order>99</Order>'
            . '<CommandLine>cmd /c "for %d in (D E F G H) do if exist %d:\guest-agent\qemu-ga-x86_64.msi msiexec /i %d:\guest-agent\qemu-ga-x86_64.msi /qn /norestart"</CommandLine>'
            . '<Description>Install QEMU Guest Agent</Description>'
            . '</SynchronousCommand>';

        // Insert before </FirstLogonCommands> if it exists
        if (str_contains($xmlContent, '</FirstLogonCommands>')) {
            $xmlContent = str_replace('</FirstLogonCommands>', $guestAgentCmd . "\n            </FirstLogonCommands>", $xmlContent);
        }
    }

    $lines[] = 'cat > ' . escapeshellarg($unattendDir . '/Autounattend.xml') . " << 'WIN_XML_EOF'";
    $lines[] = $xmlContent;
    $lines[] = 'WIN_XML_EOF';

    // Create ISO image with autounattend files
    // Use -J (Joliet) without -r (Rock Ridge) to preserve exact Windows filenames
    // Use -joliet-long for long filenames, -input-charset utf-8 for special chars
    $lines[] = 'apt-get install -y genisoimage >/dev/null 2>&1 || true';
    $lines[] = 'genisoimage -J -joliet-long -input-charset utf-8 -V OEMDRV -o ' . escapeshellarg($unattendIso) . ' ' . escapeshellarg($unattendDir) . ' 2>/dev/null';

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

// Debug: Show VM config before start
$lines[] = '';
$lines[] = "echo '    Final VM configuration:'";
$lines[] = 'qm config $VMID | grep -E "^(scsi|ide|sata|efidisk|boot|bios|machine|scsihw)" | sed "s/^/    /"';

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

// SSH options for key auth
$keyDir = getenv('SSH_KEY_DIR') ?: '/var/www/html/data/.ssh';
$keyPath = $keyDir . '/id_ed25519';
$sshOpts = '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o LogLevel=ERROR -i ' . escapeshellarg($keyPath);
$sshUser = \App\Config::get('SSH_USER', 'root');
$sshPort = (int)\App\Config::get('SSH_PORT', 22);

$sshCmd = 'ssh -tt ' . $sshOpts . ' -p ' . $sshPort
    . ' ' . escapeshellarg($sshUser . '@' . $sshHost)
    . ' ' . escapeshellarg('echo ' . escapeshellarg($b64Script) . ' | base64 -d | bash');

$sessData = [
    'ssh_host'  => $sshHost,
    'user_id'   => $user['id'],
    'expires'   => time() + 3600, // 1h for large ISO transfers
];

if ($needsDistribute) {
    // Build a compound local command: SCP the ISO first, then SSH deploy
    $scpCmd = 'echo "==> [0/5] Distributing ISO to node ' . escapeshellarg($nodeName) . '..." && '
        . 'scp ' . $sshOpts . ' -P ' . $sshPort . ' '
        . escapeshellarg($localIsoPath) . ' '
        . escapeshellarg($sshUser . '@' . $sshHost . ':/var/lib/vz/template/iso/' . $isoFile)
        . ' && echo "    ISO distributed successfully." && ';
    $sessData['raw_command'] = $scpCmd . $sshCmd;
} else {
    $sessData['direct_command'] = 'echo ' . escapeshellarg($b64Script) . ' | base64 -d | bash';
}

file_put_contents($dataFile, json_encode($sessData), LOCK_EX);
chmod($dataFile, 0600);

AppLogger::info('deploy', "Windows deploy VM {$vmid} ({$name}) on {$nodeName} with {$image['name']}", [
    'iso' => $isoFile, 'cores' => $cores, 'memory' => $memory, 'disk' => $diskSize,
], $user['id'] ?? null);

Response::success(['token' => $token]);
