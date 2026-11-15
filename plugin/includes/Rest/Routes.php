<?php
/**
 * @package SiteShifter
 */

namespace SiteShifter\Plugin\Rest;

defined('ABSPATH') || exit;

/**
 * Two ways in, one behaviour: REST (preferred) and admin-ajax (fallback when
 * a security plugin or host blocks /wp-json/). Both need manage_options and a
 * valid nonce (X-WP-Nonce for REST, `_ajax_nonce` for ajax).
 */
class Routes
{
    const NAMESPACE_ = 'siteshifter/v1';
    const CAPABILITY = 'manage_options';
    const AJAX_ACTION = 'siteshifter_job';
    const NONCE = 'siteshifter_job';
    const DOWNLOAD_ACTION = 'siteshifter_download';
    const UPLOAD_ACTION = 'siteshifter_upload';

    /** @var JobApi */
    private $api;

    public function __construct(JobApi $api)
    {
        $this->api = $api;
    }

    public function register()
    {
        add_action('rest_api_init', [$this, 'registerRest']);
        add_action('wp_ajax_' . self::AJAX_ACTION, [$this, 'ajax']); // logged-in only: no wp_ajax_nopriv_
        add_action('wp_ajax_' . self::DOWNLOAD_ACTION, [$this, 'download']);
        add_action('wp_ajax_' . self::UPLOAD_ACTION, [$this, 'uploadPiece']);
    }

    public static function can()
    {
        return current_user_can(self::CAPABILITY);
    }

    public function registerRest()
    {
        $idArg = ['id' => ['type' => 'string', 'required' => true, 'validate_callback' => ['SiteShifter\\Plugin\\Jobs\\JobStore', 'validId']]];
        $routes = [
            '/jobs' => ['POST', 'start', []],
            '/checks' => ['POST', 'checks', []],
            '/site-check' => ['POST', 'site_check', []],
            '/packages' => ['POST', 'packages', []],
            '/incoming' => ['POST', 'incoming', []],
            '/imports' => ['POST', 'imports', []],
            '/imports/(?P<id>[a-f0-9]{32})/undo' => ['POST', 'import_undo', $idArg],
            '/imports/(?P<id>[a-f0-9]{32})/discard' => ['POST', 'import_discard', $idArg],
            '/incoming/begin' => ['POST', 'upload_begin', []],
            '/incoming/(?P<id>[a-f0-9]{32})/end' => ['POST', 'upload_end', $idArg],
            '/incoming/(?P<id>[a-f0-9]{32})/delete' => ['POST', 'incoming_delete', $idArg],
            '/incoming/(?P<id>[a-f0-9]{32})/check' => ['POST', 'import_check', $idArg],
            '/packages/delete' => ['POST', 'delete_many', []],
            '/retention' => ['POST', 'retention', []],
            '/jobs/(?P<id>[a-f0-9]{32})' => ['GET', 'status', $idArg],
            '/jobs/(?P<id>[a-f0-9]{32})/step' => ['POST', 'step', $idArg],
            '/jobs/(?P<id>[a-f0-9]{32})/resume' => ['POST', 'resume', $idArg],
            '/jobs/(?P<id>[a-f0-9]{32})/estimate' => ['POST', 'estimate', $idArg],
            '/jobs/(?P<id>[a-f0-9]{32})/cancel' => ['POST', 'cancel', $idArg],
            '/jobs/(?P<id>[a-f0-9]{32})/download_link' => ['POST', 'download_link', $idArg],
            '/jobs/(?P<id>[a-f0-9]{32})/delete' => ['POST', 'delete', $idArg],
        ];
        register_rest_route(self::NAMESPACE_, '/incoming/(?P<id>[a-f0-9]{32})/piece', [
            'methods' => 'POST',
            'callback' => function (\WP_REST_Request $request) {
                $files = $request->get_file_params();
                $tmp = isset($files['piece']['tmp_name']) ? (string) $files['piece']['tmp_name'] : '';
                $body = $tmp !== '' && is_uploaded_file($tmp)
                    ? $this->api->upload((string) $request->get_param('id'), (int) $request->get_param('offset'), $tmp)
                    : ['ok' => false, 'code' => 'invalid', 'message' => 'No piece was uploaded (the server may limit upload size).'];
                $response = new \WP_REST_Response($body, $body['ok'] ? 200 : 400);
                $response->header('Cache-Control', 'no-store');

                return $response;
            },
            'permission_callback' => [self::class, 'can'],
            'args' => $idArg,
        ]);
        foreach ($routes as $path => $def) {
            list($method, $op, $args) = $def;
            register_rest_route(self::NAMESPACE_, $path, [
                'methods' => $method,
                'callback' => function (\WP_REST_Request $request) use ($op) {
                    $body = $this->api->handle($op, [
                        'id' => (string) $request->get_param('id'),
                        'type' => sanitize_key((string) $request->get_param('type')),
                        'params' => $this->params($request->get_param('params')),
                        'user' => get_current_user_id(),
                    ]);
                    $response = new \WP_REST_Response($body, $body['ok'] ? 200 : 400);
                    $response->header('Cache-Control', 'no-store');

                    return $response;
                },
                // Cookie auth additionally requires a valid X-WP-Nonce: WordPress core enforces it.
                'permission_callback' => [self::class, 'can'],
                'args' => $args,
            ]);
        }
    }

    /** POST admin-ajax.php?action=siteshifter_upload (multipart: id, offset, piece) */
    public function uploadPiece()
    {
        check_ajax_referer(self::NONCE, '_ajax_nonce');
        if (!self::can()) {
            wp_send_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Not allowed.'], 403);
        }
        $id = isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
        $offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $tmp = isset($_FILES['piece']['tmp_name']) ? (string) $_FILES['piece']['tmp_name'] : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        nocache_headers();
        $body = $tmp !== '' && is_uploaded_file($tmp)
            ? $this->api->upload($id, $offset, $tmp)
            : ['ok' => false, 'code' => 'invalid', 'message' => 'No piece was uploaded (the server may limit upload size).'];
        wp_send_json($body, $body['ok'] ? 200 : 400);
    }

    /** GET admin-ajax.php?action=siteshifter_download&job=<id>&token=<token> */
    public function download()
    {
        if (!self::can()) {
            wp_die(esc_html__('You do not have permission to download this file.', 'siteshifter'), 403);
        }
        $id = isset($_GET['job']) ? sanitize_key(wp_unslash($_GET['job'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- the token below is the credential
        $token = isset($_GET['token']) ? sanitize_key(wp_unslash($_GET['token'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $found = $this->api->downloadTarget($id, $token, get_current_user_id());
        if (!is_array($found)) {
            wp_die(esc_html($found), 403);
        }
        Download::stream($found['file'], Download::filename(get_option('home'), $found['created']));
        exit;
    }

    public function ajax()
    {
        check_ajax_referer(self::NONCE, '_ajax_nonce');
        if (!self::can()) {
            wp_send_json(['ok' => false, 'code' => 'forbidden', 'message' => 'Not allowed.'], 403);
        }
        $op = isset($_POST['op']) ? sanitize_key(wp_unslash($_POST['op'])) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked above
        $input = [
            'id' => isset($_POST['id']) ? sanitize_key(wp_unslash($_POST['id'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'type' => isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : '', // phpcs:ignore WordPress.Security.NonceVerification.Missing
            'user' => get_current_user_id(),
            'params' => $this->params(isset($_POST['params']) ? json_decode(wp_unslash($_POST['params']), true) : []), // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        ];
        nocache_headers();
        $body = $this->api->handle($op, $input);
        wp_send_json($body, $body['ok'] ? 200 : 400);
    }

    /** Only scalar values, depth 1: tasks validate their own keys. */
    private function params($value)
    {
        $out = [];
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                if (is_scalar($v) || $v === null) {
                    $out[sanitize_key((string) $k)] = is_string($v) ? sanitize_text_field($v) : $v;
                }
            }
        }

        return $out;
    }
}
