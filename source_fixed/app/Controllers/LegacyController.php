<?php

namespace App\Controllers;

use CodeIgniter\HTTP\ResponseInterface;
use Throwable;

class LegacyController extends BaseController
{
    private string $legacyPublic;

    public function __construct()
    {
        $this->legacyPublic = rtrim(ROOTPATH . 'legacy/public', '/\\') . DIRECTORY_SEPARATOR;
    }

    public function root(): ResponseInterface
    {
        return $this->runLegacy('');
    }

    public function handle(string $path = ''): ResponseInterface
    {
        return $this->runLegacy($path);
    }

    private function runLegacy(string $path): ResponseInterface
    {
        $path = rawurldecode($path);
        $path = ltrim(str_replace('\\', '/', $path), '/');

        // Never allow path traversal or direct access outside legacy/public.
        if ($path !== '' && (str_contains($path, '..') || str_contains($path, "\0"))) {
            return $this->response->setStatusCode(400)->setBody('Bad Request');
        }

        $candidate = $path !== '' ? $this->legacyPublic . $path : '';
        if ($candidate !== '' && is_file($candidate) && strtolower(pathinfo($candidate, PATHINFO_EXTENSION)) === 'php') {
            $target = $candidate;
        } elseif ($candidate !== '' && is_dir($candidate) && is_file(rtrim($candidate, '/\\') . DIRECTORY_SEPARATOR . 'index.php')) {
            // Legacy admin URLs commonly use directory-style paths such as
            // /admin/products. Preserve the old DirectoryIndex behaviour.
            $target = rtrim($candidate, '/\\') . DIRECTORY_SEPARATOR . 'index.php';
        } else {
            // Clean storefront URLs (/, /produk/slug, /kategori/slug, etc.) remain
            // handled by the existing storefront router during the gradual CI4 migration.
            $target = $this->legacyPublic . 'index.php';
        }

        if (!is_file($target)) {
            return $this->response->setStatusCode(404)->setBody('Legacy target not found.');
        }

        if (!defined('VAPEKONG_CI4_BRIDGE')) {
            define('VAPEKONG_CI4_BRIDGE', true);
        }

        // Legacy pages still use native header()/http_response_code() and may exit
        // for downloads/webhooks. Output buffering keeps normal HTML compatible
        // with CI4's response lifecycle while preserving those special endpoints.
        ob_start();
        try {
            require $target;
            $body = (string) ob_get_clean();
            $status = http_response_code();
            if (is_int($status) && $status >= 100 && $status <= 599) {
                $this->response->setStatusCode($status);
            }
            return $this->response->setBody($body);
        } catch (Throwable $e) {
            if (ob_get_level() > 0) ob_end_clean();
            log_message('error', '[Legacy bridge] {type}: {message} in {file}:{line}', [
                'type' => get_class($e), 'message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine(),
            ]);
            if (ENVIRONMENT !== 'production') {
                throw $e;
            }
            return $this->response->setStatusCode(500)->setBody('Terjadi kesalahan sistem. Periksa writable/logs.');
        }
    }
}
