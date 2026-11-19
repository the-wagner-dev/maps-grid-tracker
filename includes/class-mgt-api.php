<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Minimal DataForSEO v3 client: Google Maps SERP (standard queue + live) and account data.
 * Docs: https://docs.dataforseo.com/v3/serp/google/maps/overview/
 */
class MGT_API
{
    const MAX_TASKS_PER_POST = 100;

    private string $login;
    private string $password;
    private string $base;

    public function __construct(array $settings)
    {
        $this->login = (string) $settings['api_login'];
        $this->password = (string) $settings['api_password'];
        $this->base = !empty($settings['sandbox']) ? 'https://sandbox.dataforseo.com' : 'https://api.dataforseo.com';
    }

    /** @return array|WP_Error Decoded response body. */
    private function request(string $method, string $path, ?array $body = null, int $timeout = 30)
    {
        $args = [
            'method'  => $method,
            'timeout' => $timeout,
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password),
                'Content-Type'  => 'application/json',
            ],
        ];
        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }
        $response = wp_remote_request($this->base . $path, $args);
        if (is_wp_error($response)) {
            return $response;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return new WP_Error('mgt_api', sprintf('DataForSEO: HTTP %d, invalid response', $code));
        }
        if ((int) ($data['status_code'] ?? 0) !== 20000) {
            return new WP_Error('mgt_api', sprintf('DataForSEO %s: %s', $data['status_code'] ?? $code, $data['status_message'] ?? 'error'));
        }
        return $data;
    }

    /** @return array|WP_Error ['balance' => float, 'login' => string] */
    public function account()
    {
        $data = $this->request('GET', '/v3/appendix/user_data', null, 15);
        if (is_wp_error($data)) {
            return $data;
        }
        $result = $data['tasks'][0]['result'][0] ?? [];
        return [
            'login'   => (string) ($result['login'] ?? ''),
            'balance' => (float) ($result['money']['balance'] ?? 0),
        ];
    }

    /**
     * Post up to 100 Maps tasks.
     * @param array $tasks Each: keyword, language_code, location_coordinate, depth, tag.
     * @return array|WP_Error List of ['tag', 'id', 'status_code', 'status_message', 'cost'].
     */
    public function post_tasks(array $tasks)
    {
        $data = $this->request('POST', '/v3/serp/google/maps/task_post', array_values($tasks), 60);
        if (is_wp_error($data)) {
            return $data;
        }
        $out = [];
        foreach ((array) ($data['tasks'] ?? []) as $task) {
            $out[] = [
                'tag'            => (string) ($task['data']['tag'] ?? ''),
                'id'             => (string) ($task['id'] ?? ''),
                'status_code'    => (int) ($task['status_code'] ?? 0),
                'status_message' => (string) ($task['status_message'] ?? ''),
                'cost'           => (float) ($task['cost'] ?? 0),
            ];
        }
        return $out;
    }

    /**
     * @return array|WP_Error ['state' => 'done'|'pending'|'error', 'items' => array, 'message' => string]
     */
    public function get_task(string $id)
    {
        $data = $this->request('GET', '/v3/serp/google/maps/task_get/advanced/' . rawurlencode($id), null, 30);
        if (is_wp_error($data)) {
            return $data;
        }
        return self::task_state($data['tasks'][0] ?? []);
    }

    /**
     * Fetch several task results in parallel.
     * @param string[] $ids
     * @return array id => state array (as get_task) or WP_Error
     */
    public function get_tasks(array $ids): array
    {
        $requests = [];
        foreach ($ids as $id) {
            $requests[$id] = [
                'url'     => $this->base . '/v3/serp/google/maps/task_get/advanced/' . rawurlencode($id),
                'headers' => ['Authorization' => 'Basic ' . base64_encode($this->login . ':' . $this->password)],
                'type'    => 'GET',
            ];
        }
        $out = [];
        try {
            $responses = \WpOrg\Requests\Requests::request_multiple($requests, ['timeout' => 30, 'connect_timeout' => 10]);
        } catch (\Throwable $e) {
            return array_fill_keys($ids, new WP_Error('mgt_api', $e->getMessage()));
        }
        foreach ($responses as $id => $response) {
            if (!$response instanceof \WpOrg\Requests\Response || !$response->success) {
                $out[$id] = new WP_Error('mgt_api', 'DataForSEO: request failed');
                continue;
            }
            $data = json_decode((string) $response->body, true);
            if (!is_array($data) || (int) ($data['status_code'] ?? 0) !== 20000) {
                $out[$id] = new WP_Error('mgt_api', sprintf('DataForSEO %s: %s', $data['status_code'] ?? $response->status_code, $data['status_message'] ?? 'error'));
                continue;
            }
            $out[$id] = self::task_state($data['tasks'][0] ?? []);
        }
        return $out;
    }

    /** Live request (more expensive), used to look up the business. */
    public function live(string $keyword, string $coordinate, string $language, int $depth = 20)
    {
        $data = $this->request('POST', '/v3/serp/google/maps/live/advanced', [[
            'keyword'             => $keyword,
            'location_coordinate' => $coordinate,
            'language_code'       => $language,
            'depth'               => $depth,
        ]], 90);
        if (is_wp_error($data)) {
            return $data;
        }
        $state = self::task_state($data['tasks'][0] ?? []);
        $state['cost'] = (float) ($data['cost'] ?? 0);
        return $state;
    }

    private static function task_state(array $task): array
    {
        $code = (int) ($task['status_code'] ?? 0);
        $message = (string) ($task['status_message'] ?? '');
        if ($code === 20000) {
            $items = [];
            foreach ((array) ($task['result'][0]['items'] ?? []) as $item) {
                if (($item['type'] ?? '') === 'maps_search') {
                    $items[] = $item;
                }
            }
            return ['state' => 'done', 'items' => $items, 'message' => $message];
        }
        if ($code === 40102) { // No Search Results: a valid answer with nothing found.
            return ['state' => 'done', 'items' => [], 'message' => $message];
        }
        // 40601 Task Handed, 40602 Task In Queue: not ready yet.
        if (in_array($code, [40601, 40602], true)) {
            return ['state' => 'pending', 'items' => [], 'message' => $message];
        }
        return ['state' => 'error', 'items' => [], 'message' => trim($code . ' ' . $message)];
    }
}
