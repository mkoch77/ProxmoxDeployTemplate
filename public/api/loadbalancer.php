<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Bootstrap;
use App\Auth;
use App\Request;
use App\Response;
use App\Helpers;
use App\Loadbalancer;
use App\AppLogger;

Bootstrap::init();

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        Auth::requirePermission('loadbalancer.view');
        try {
            $api = Helpers::createAPI();
            Response::success([
                'settings' => Loadbalancer::getSettings(),
                'latest_run' => Loadbalancer::getLatestRun(),
                'balance' => Loadbalancer::getClusterBalance($api),
            ]);
        } catch (\Exception $e) {
            Response::error($e->getMessage(), 500);
        }
        break;

    case 'POST':
        Request::validateCsrf();
        Auth::requirePermission('loadbalancer.manage');

        $action = $_GET['action'] ?? '';
        $body = Request::jsonBody();

        switch ($action) {
            case 'settings':
                try {
                    $data = [];
                    if (isset($body['enabled'])) $data['enabled'] = (int)(bool)$body['enabled'];
                    if (isset($body['automation_level'])) {
                        $level = $body['automation_level'];
                        if (!in_array($level, ['manual', 'partial', 'full'], true)) {
                            Response::error('Invalid automation level', 400);
                        }
                        $data['automation_level'] = $level;
                    }
                    if (isset($body['cpu_weight'])) $data['cpu_weight'] = max(0, min(100, (int)$body['cpu_weight']));
                    if (isset($body['ram_weight'])) $data['ram_weight'] = max(0, min(100, (int)$body['ram_weight']));
                    if (isset($body['threshold'])) $data['threshold'] = max(1, min(5, (int)$body['threshold']));
                    if (isset($body['interval_minutes'])) $data['interval_minutes'] = max(1, min(60, (int)$body['interval_minutes']));
                    if (isset($body['max_concurrent'])) $data['max_concurrent'] = max(1, min(10, (int)$body['max_concurrent']));

                    Loadbalancer::updateSettings($data);
                    $userId = Auth::check()['id'] ?? null;
                    AppLogger::info('config', 'Loadbalancer settings updated', $data, $userId);
                    Response::success(Loadbalancer::getSettings());
                } catch (\Exception $e) {
                    AppLogger::error('config', 'Loadbalancer settings update failed', ['error' => $e->getMessage()], Auth::check()['id'] ?? null);
                    Response::error($e->getMessage(), 500);
                }
                break;

            case 'run':
                try {
                    $api = Helpers::createAPI();
                    $userId = Auth::check()['id'] ?? null;
                    AppLogger::info('monitoring', 'Loadbalancer manual run triggered', null, $userId);
                    Response::success(Loadbalancer::evaluate($api, 'manual'));
                } catch (\Exception $e) {
                    AppLogger::error('monitoring', 'Loadbalancer manual run failed', ['error' => $e->getMessage()], Auth::check()['id'] ?? null);
                    Response::error($e->getMessage(), 500);
                }
                break;

            case 'apply':
                try {
                    $recId = (int)($body['recommendation_id'] ?? 0);
                    if ($recId <= 0) Response::error('Invalid recommendation ID', 400);
                    $api = Helpers::createAPI();
                    $userId = Auth::check()['id'] ?? null;
                    AppLogger::info('monitoring', 'Loadbalancer recommendation applied', ['recommendation_id' => $recId], $userId);
                    Response::success(Loadbalancer::applyRecommendation($api, $recId));
                } catch (\Exception $e) {
                    AppLogger::error('monitoring', 'Loadbalancer recommendation apply failed', ['recommendation_id' => $recId ?? 0, 'error' => $e->getMessage()], Auth::check()['id'] ?? null);
                    Response::error($e->getMessage(), 500);
                }
                break;

            case 'apply-all':
                try {
                    $runId = (int)($body['run_id'] ?? 0);
                    if ($runId <= 0) Response::error('Invalid run ID', 400);
                    $api = Helpers::createAPI();
                    $userId = Auth::check()['id'] ?? null;
                    AppLogger::info('monitoring', 'All loadbalancer recommendations applied', ['run_id' => $runId], $userId);
                    Response::success(Loadbalancer::applyAllRecommendations($api, $runId));
                } catch (\Exception $e) {
                    AppLogger::error('monitoring', 'Loadbalancer apply-all failed', ['run_id' => $runId ?? 0, 'error' => $e->getMessage()], Auth::check()['id'] ?? null);
                    Response::error($e->getMessage(), 500);
                }
                break;

            default:
                Response::error('Unknown action', 400);
        }
        break;

    default:
        Response::error('Method not allowed', 405);
}
