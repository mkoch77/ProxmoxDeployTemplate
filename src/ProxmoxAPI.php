<?php

namespace App;

class ProxmoxAPI
{
    private string $host;
    private int $port;
    private string $tokenId;
    private string $tokenSecret;
    private bool $verifySSL;

    public function __construct(
        string $host,
        int $port,
        string $tokenId,
        string $tokenSecret,
        bool $verifySSL = false
    ) {
        $this->host = $host;
        $this->port = $port;
        $this->tokenId = $tokenId;
        $this->tokenSecret = $tokenSecret;
        $this->verifySSL = $verifySSL;
    }

    // --- Low-level HTTP ---

    private function baseUrl(): string
    {
        return "https://{$this->host}:{$this->port}/api2/json";
    }

    private function request(string $method, string $path, array $params = []): array
    {
        $url = $this->baseUrl() . $path;
        $ch = curl_init();

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => $this->verifySSL,
            CURLOPT_SSL_VERIFYHOST => $this->verifySSL ? 2 : 0,
            CURLOPT_TIMEOUT        => 120,
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

        if ($response === false) {
            throw new \RuntimeException('Proxmox API request failed: ' . $error);
        }

        $data = json_decode($response, true);
        if ($data === null) {
            throw new \RuntimeException('Invalid JSON response from Proxmox API');
        }

        if ($httpCode >= 400) {
            $msg = $data['errors'] ?? $data['message'] ?? 'Unknown error';
            if (is_array($msg)) {
                $msg = json_encode($msg);
            }
            throw new \RuntimeException('Proxmox API error (' . $httpCode . '): ' . $msg);
        }

        return $data;
    }

    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    public function post(string $path, array $params = []): array
    {
        return $this->request('POST', $path, $params);
    }

    public function put(string $path, array $params = []): array
    {
        return $this->request('PUT', $path, $params);
    }

    public function delete(string $path, array $params = []): array
    {
        return $this->request('DELETE', $path, $params);
    }

    // --- Nodes ---

    public function getNodes(): array
    {
        return $this->get('/nodes');
    }

    public function getNodeStatus(string $node): array
    {
        return $this->get("/nodes/{$node}/status");
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

    public function getGuestConfig(string $node, string $type, int $vmid): array
    {
        return $this->get("/nodes/{$node}/{$type}/{$vmid}/config");
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

    // --- Storage ---

    public function getStorages(string $node, ?string $content = null): array
    {
        $params = [];
        if ($content !== null) {
            $params['content'] = $content;
        }
        return $this->get("/nodes/{$node}/storage", $params);
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

    // --- HA ---

    public function getHAStatus(): array
    {
        return $this->get('/cluster/ha/status/current');
    }

    public function getHAResources(): array
    {
        return $this->get('/cluster/ha/resources');
    }

    // --- Migration ---

    public function migrateGuest(string $node, string $type, int $vmid, string $target, bool $online = true): array
    {
        $params = ['target' => $target];
        if ($online) {
            $params['online'] = 1;
        }
        return $this->post("/nodes/{$node}/{$type}/{$vmid}/migrate", $params);
    }
}
