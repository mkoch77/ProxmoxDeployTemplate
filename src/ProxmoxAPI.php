<?php

namespace App;

class ProxmoxAPI
{
    /** @var string[] */
    private array $hosts;
    private int $port;
    private string $tokenId;
    private string $tokenSecret;
    private bool $verifySSL;

    /**
     * @param string|string[] $host  Primary host or list of hosts (tried in order on failure).
     */
    public function __construct(
        string|array $host,
        int $port,
        string $tokenId,
        string $tokenSecret,
        bool $verifySSL = false
    ) {
        $this->hosts = is_array($host) ? array_values(array_filter($host)) : [$host];
        $this->port = $port;
        $this->tokenId = $tokenId;
        $this->tokenSecret = $tokenSecret;
        $this->verifySSL = $verifySSL;
    }

    // --- Low-level HTTP ---

    private function baseUrl(string $host): string
    {
        return "https://{$host}:{$this->port}/api2/json";
    }

    /**
     * @param array $options  Optional overrides: 'connect_timeout', 'timeout'
     */
    private function request(string $method, string $path, array $params = [], array $options = []): array
    {
        // Validate path segments to prevent injection via node/VM names
        if (preg_match('#[^a-zA-Z0-9/_\-.:+@!]#', $path)) {
            throw new \InvalidArgumentException('Invalid characters in API path');
        }

        $lastError = null;
        $connectTimeout = $options['connect_timeout'] ?? 2;
        $totalTimeout   = $options['timeout'] ?? 8;

        AppLogger::debug('api', "Proxmox API {$method} {$path}", ['params' => array_keys($params)]);

        foreach ($this->hosts as $host) {
            $url = $this->baseUrl($host) . $path;
            $ch = curl_init();

            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
                CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT        => $totalTimeout,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: PVEAPIToken=' . $this->tokenId . '=' . $this->tokenSecret,
                ],
            ];

            switch (strtoupper($method)) {
                case 'GET':
                    if (!empty($params)) {
                        $url .= '?' . http_build_query($params);
                    }
                    break;
                case 'POST':
                    $opts[CURLOPT_POST] = true;
                    $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
                    break;
                case 'PUT':
                    $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
                    $opts[CURLOPT_POSTFIELDS] = http_build_query($params);
                    break;
                case 'DELETE':
                    $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
                    if (!empty($params)) {
                        $url .= '?' . http_build_query($params);
                    }
                    break;
            }

            $opts[CURLOPT_URL] = $url;
            curl_setopt_array($ch, $opts);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            // Connection failed → try next host
            if ($response === false) {
                AppLogger::debug('api', "Proxmox API connection failed to {$host}", ['path' => $path, 'error' => $error]);
                $lastError = new \RuntimeException('Proxmox API request failed: ' . $error);
                continue;
            }

            $data = json_decode($response, true);
            if ($data === null) {
                AppLogger::debug('api', "Proxmox API invalid JSON from {$host}", ['path' => $path]);
                $lastError = new \RuntimeException('Invalid JSON response from Proxmox API');
                continue;
            }

            if ($httpCode >= 400) {
                $msg = $data['errors'] ?? $data['message'] ?? 'Unknown error';
                if (is_array($msg)) {
                    $msg = json_encode($msg);
                }
                throw new \RuntimeException('Proxmox API error (' . $httpCode . '): ' . $msg);
            }

            AppLogger::debug('api', "Proxmox API response OK from {$host}", ['path' => $path, 'http_code' => $httpCode]);

            return $data;
        }

        throw $lastError ?? new \RuntimeException('No Proxmox hosts configured');
    }

    public function get(string $path, array $params = [], array $options = []): array
    {
        return $this->request('GET', $path, $params, $options);
    }

    public function post(string $path, array $params = [], array $options = []): array
    {
        return $this->request('POST', $path, $params, $options);
    }

    public function put(string $path, array $params = [], array $options = []): array
    {
        return $this->request('PUT', $path, $params, $options);
    }

    public function delete(string $path, array $params = [], array $options = []): array
    {
        return $this->request('DELETE', $path, $params, $options);
    }

    /** Short timeout options for non-critical health/status checks */
    private const QUICK_OPTS = ['connect_timeout' => 2, 'timeout' => 4];

    // --- Nodes ---

    public function getNodes(): array
    {
        return $this->get('/nodes');
    }

    public function getNodeStatus(string $node, array $options = []): array
    {
        return $this->get("/nodes/{$node}/status", [], $options);
    }

    public function getNodeRRDData(string $node, string $timeframe = 'hour', array $options = []): array
    {
        return $this->get("/nodes/{$node}/rrddata", ['timeframe' => $timeframe], $options);
    }

    public function getGuestRRDData(string $node, string $type, int $vmid, string $timeframe = 'hour', array $options = []): array
    {
        return $this->get("/nodes/{$node}/{$type}/{$vmid}/rrddata", ['timeframe' => $timeframe], $options);
    }

    // --- Cluster Resources ---

    public function getClusterResources(?string $type = null): array
    {
        $params = [];
        if ($type !== null) {
            $params['type'] = $type;
        }
        return $this->get('/cluster/resources', $params);
    }

    public function getNextVmid(): int
    {
        $result = $this->get('/cluster/nextid');
        return (int)$result['data'];
    }

    // --- Templates ---

    public function getTemplates(): array
    {
        $resources = $this->getClusterResources('vm');
        $templates = [];
        foreach ($resources['data'] ?? [] as $item) {
            if (!empty($item['template'])) {
                $templates[] = $item;
            }
        }
        return $templates;
    }

    // --- Guests ---

    public function getGuests(): array
    {
        $resources = $this->getClusterResources('vm');
        $guests = [];
        foreach ($resources['data'] ?? [] as $item) {
            if (empty($item['template'])) {
                $guests[] = $item;
            }
        }
        return $guests;
    }

    public function getVMs(string $node): array
    {
        return $this->get("/nodes/{$node}/qemu");
    }

    public function getCTs(string $node): array
    {
        return $this->get("/nodes/{$node}/lxc");
    }

    // --- Guest Configuration ---

    public function getGuestConfig(string $node, string $type, int $vmid, array $options = []): array
    {
        return $this->get("/nodes/{$node}/{$type}/{$vmid}/config", [], $options);
    }

    public function setGuestConfig(string $node, string $type, int $vmid, array $config): array
    {
        return $this->put("/nodes/{$node}/{$type}/{$vmid}/config", $config);
    }

    // --- Clone ---

    public function cloneGuest(string $node, string $type, int $vmid, array $params): array
    {
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/clone", $params);
    }

    // --- Resize Disk ---

    public function resizeDisk(string $node, string $type, int $vmid, string $disk, string $size): array
    {
        return $this->put("/nodes/{$node}/{$type}/{$vmid}/resize", [
            'disk' => $disk,
            'size' => $size,
        ]);
    }

    // --- Power Actions ---

    public function startGuest(string $node, string $type, int $vmid): array
    {
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/status/start");
    }

    public function stopGuest(string $node, string $type, int $vmid): array
    {
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/status/stop");
    }

    public function shutdownGuest(string $node, string $type, int $vmid): array
    {
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/status/shutdown");
    }

    public function rebootGuest(string $node, string $type, int $vmid): array
    {
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/status/reboot");
    }

    public function resetGuest(string $node, string $type, int $vmid): array
    {
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/status/reset");
    }

    // --- Tasks ---

    public function getNodeTasks(string $node, array $params = []): array
    {
        return $this->get("/nodes/{$node}/tasks", $params);
    }

    public function getTaskStatus(string $node, string $upid): array
    {
        return $this->get("/nodes/{$node}/tasks/{$upid}/status");
    }

    public function getTaskLog(string $node, string $upid, int $start = 0, int $limit = 500): array
    {
        return $this->get("/nodes/{$node}/tasks/{$upid}/log", [
            'start' => $start,
            'limit' => $limit,
        ]);
    }

    public function stopTask(string $node, string $upid): array
    {
        return $this->delete("/nodes/{$node}/tasks/{$upid}");
    }

    // --- Storage ---

    public function getStorages(string $node, ?string $content = null): array
    {
        $params = [];
        if ($content !== null) {
            $params['content'] = $content;
        }
        return $this->get("/nodes/{$node}/storage", $params);
    }

    public function getStorageContent(string $node, string $storage, ?string $content = null): array
    {
        $params = [];
        if ($content !== null) {
            $params['content'] = $content;
        }
        return $this->get("/nodes/{$node}/storage/{$storage}/content", $params);
    }

    public function deleteStorageVolume(string $node, string $volume): array
    {
        $storage = explode(':', $volume)[0];
        return $this->delete("/nodes/{$node}/storage/{$storage}/content/{$volume}");
    }

    // --- Networks ---

    public function getNetworks(string $node, ?string $type = null): array
    {
        $params = [];
        if ($type !== null) {
            $params['type'] = $type;
        }
        return $this->get("/nodes/{$node}/network", $params);
    }

    public function getLxcInterfaces(string $node, int $vmid): array
    {
        return $this->get("/nodes/{$node}/lxc/{$vmid}/interfaces");
    }

    public function getQemuAgentNetworks(string $node, int $vmid): array
    {
        return $this->get("/nodes/{$node}/qemu/{$vmid}/agent/network-get-interfaces");
    }

    // --- Cluster Options ---

    public function getClusterOptions(): array
    {
        return $this->get('/cluster/options');
    }

    public function setClusterOptions(array $params): array
    {
        return $this->put('/cluster/options', $params);
    }

    // --- HA ---

    public function getClusterStatus(): array
    {
        return $this->get('/cluster/status');
    }

    public function getHAStatus(): array
    {
        return $this->get('/cluster/ha/status/current');
    }

    public function getHAResources(): array
    {
        return $this->get('/cluster/ha/resources');
    }

    public function addHAResource(string $sid, string $state = 'started', string $group = ''): array
    {
        $params = ['sid' => $sid, 'state' => $state];
        if ($group !== '') {
            $params['group'] = $group;
        }
        return $this->post('/cluster/ha/resources', $params);
    }

    public function updateHAResource(string $sid, array $data): array
    {
        return $this->put('/cluster/ha/resources/' . rawurlencode($sid), $data);
    }

    public function removeHAResource(string $sid): array
    {
        return $this->delete('/cluster/ha/resources/' . rawurlencode($sid));
    }

    public function getHAGroups(): array
    {
        return $this->get('/cluster/ha/groups');
    }

    // --- Apt / Updates ---

    public function refreshAptIndex(string $node): array
    {
        return $this->post("/nodes/{$node}/apt/update");
    }

    public function getAptUpdates(string $node): array
    {
        return $this->get("/nodes/{$node}/apt/updates");
    }

    // --- Delete ---

    public function deleteGuest(string $node, string $type, int $vmid): array
    {
        return $this->delete("/nodes/{$node}/{$type}/{$vmid}");
    }

    // --- Snapshots ---

    public function getSnapshots(string $node, string $type, int $vmid): array
    {
        return $this->get("/nodes/{$node}/{$type}/{$vmid}/snapshot");
    }

    public function createSnapshot(string $node, string $type, int $vmid, string $snapname, string $description = '', bool $vmstate = false): array
    {
        $params = ['snapname' => $snapname];
        if ($description) $params['description'] = $description;
        if ($type === 'qemu' && $vmstate) $params['vmstate'] = 1;
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/snapshot", $params);
    }

    public function deleteSnapshot(string $node, string $type, int $vmid, string $snapname): array
    {
        return $this->delete("/nodes/{$node}/{$type}/{$vmid}/snapshot/{$snapname}");
    }

    public function rollbackSnapshot(string $node, string $type, int $vmid, string $snapname): array
    {
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/snapshot/{$snapname}/rollback");
    }

    // --- QEMU Guest Agent ---

    public function agentExec(string $node, int $vmid, string $command, array $inputData = []): array
    {
        $params = ['command' => $command];
        if (!empty($inputData)) {
            $params['input-data'] = implode("\n", $inputData);
        }
        return $this->post("/nodes/{$node}/qemu/{$vmid}/agent/exec", $params);
    }

    public function agentExecStatus(string $node, int $vmid, int $pid): array
    {
        return $this->get("/nodes/{$node}/qemu/{$vmid}/agent/exec-status", ['pid' => $pid]);
    }

    // --- CEPH ---

    public function getCephStatus(string $node, array $options = []): array
    {
        return $this->get("/nodes/{$node}/ceph/status", [], $options);
    }

    public function getCephOsd(string $node, array $options = []): array
    {
        return $this->get("/nodes/{$node}/ceph/osd", [], $options);
    }

    public function getCephMon(string $node, array $options = []): array
    {
        return $this->get("/nodes/{$node}/ceph/mon", [], $options);
    }

    public function getCephPools(string $node, array $options = []): array
    {
        return $this->get("/nodes/{$node}/ceph/pools", [], $options);
    }

    // --- Migration ---

    public function migrateGuest(string $node, string $type, int $vmid, string $target, bool $online = true): array
    {
        $params = ['target' => $target];
        if ($type === 'lxc') {
            // LXC live migration is not supported; use restart=1 (stop → migrate → start on target)
            $params['restart'] = 1;
        } elseif ($online) {
            $params['online'] = 1;
        }
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/migrate", $params);
    }
}
