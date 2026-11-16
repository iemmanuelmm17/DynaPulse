<?php

declare(strict_types=1);

namespace DynaPulse\Http\Controller;

use DynaPulse\Http\Context;
use DynaPulse\Http\HttpException;
use DynaPulse\Http\Request;
use DynaPulse\Http\Response;
use DynaPulse\NetSapiens\NsGateway;
use DynaPulse\Repository\CallRepository;
use DynaPulse\Service\Signer;
use DynaPulse\Support\Config;
use DynaPulse\Support\Logger;

/**
 * Proxies the NetSapiens recording so managers never need the API key and the
 * audio URL from an email stops working after its signed lifetime.
 */
final class AudioController extends BaseController
{
    public function __construct(
        \DynaPulse\Http\View $view,
        private CallRepository $calls,
        private NsGateway $ns,
        private Signer $signer,
        private Config $config,
    ) {
        parent::__construct($view);
    }

    public function stream(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        $call = $this->calls->find($id);

        if ($call === null) {
            throw new HttpException(404, 'That call could not be found.');
        }

        $this->authorize($request, $call);

        $url = (string) ($call['recording_url'] ?? '');
        if ($url === '' || (int) $call['has_recording'] !== 1) {
            throw new HttpException(404, 'No recording is available for this call.');
        }

        $cachePath = null;
        if ((bool) $this->config->get('security.recording_cache', false)) {
            $directory = (string) $this->config->get('paths.recordings');
            $extension = pathinfo((string) (parse_url($url, PHP_URL_PATH) ?: ''), PATHINFO_EXTENSION) ?: 'wav';
            $cachePath = $directory . DIRECTORY_SEPARATOR . (int) $call['domain_id'] . '-' . $id . '.' . preg_replace('/[^a-z0-9]/', '', strtolower($extension));
        }

        if ($cachePath !== null && is_file($cachePath)) {
            return $this->serveLocal($cachePath, $request, (string) ($call['call_id'] ?? 'recording'));
        }

        $headers = $this->ns->headersForRecordingUrl($url);
        $download = $request->query('download') === '1';
        $filename = 'dynapulse_call_' . preg_replace('/[^A-Za-z0-9-]/', '', (string) ($call['call_id'] ?? 'recording')) . '.wav';

        return Response::stream(function () use ($url, $headers, $request, $cachePath, $download, $filename): void {
            $this->proxy($url, $headers, $request, $cachePath, $download, $filename);
        });
    }

    private function authorize(Request $request, array $call): void
    {
        $user = Context::$user;

        if ($user !== null) {
            if ($user->isAdmin()) {
                return;
            }

            $scope = Context::domainId();
            if ($scope !== null && (int) $call['domain_id'] === $scope) {
                return;
            }
        }

        if ((bool) $this->config->get('security.public_audio_links', true) && $this->signer->enabled()) {
            $params = [];
            foreach (['call', 'exp', 'sig'] as $key) {
                $value = $request->query($key);
                if (is_string($value)) {
                    $params[$key] = $value;
                }
            }

            if ($this->signer->verify($params) && (int) ($params['call'] ?? 0) === (int) $call['id']) {
                return;
            }
        }

        if ($user === null) {
            throw new HttpException(401);
        }

        throw new HttpException(403, 'You do not have access to that recording.');
    }

    /** @param array<string, string> $headers */
    private function proxy(string $url, array $headers, Request $request, ?string $cachePath, bool $download, string $filename): void
    {
        $handle = curl_init($url);
        if ($handle === false) {
            http_response_code(502);
            echo 'Cannot open the recording.';

            return;
        }

        $headerLines = [
            'Accept: */*',
            'User-Agent: DynaPulse/1.0',
        ];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        $range = $request->header('range');
        $sentHeaders = false;
        $status = 200;
        $responseHeaders = [];
        $tempFile = null;
        $tempPath = null;

        if ($cachePath !== null) {
            $directory = dirname($cachePath);
            if (is_dir($directory) || @mkdir($directory, 0775, true)) {
                $tempPath = $cachePath . '.part';
                $tempFile = @fopen($tempPath, 'wb') ?: null;
            }
        }

        $options = [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_SSL_VERIFYPEER => (bool) $this->config->get('ns.verify_ssl', true),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$status, &$responseHeaders): int {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                    $status = (int) $m[1];
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }

                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$sentHeaders, &$status, &$responseHeaders, $tempFile, $download, $filename): int {
                if (!$sentHeaders) {
                    $sentHeaders = true;
                    $this->emitHeaders($status, $responseHeaders, $download ? $filename : null);
                }

                if ($tempFile !== null && $status === 200) {
                    fwrite($tempFile, $chunk);
                }

                echo $chunk;
                flush();

                return strlen($chunk);
            },
        ];

        if ($range !== null && $range !== '') {
            $options[CURLOPT_RANGE] = str_replace('bytes=', '', $range);
        }

        if (PHP_SAPI === 'cli') {
            $options[CURLOPT_TIMEOUT] = 30;
        }

        curl_setopt_array($handle, $options);
        $ok = curl_exec($handle);
        $error = $ok === false ? curl_error($handle) : null;
        $finalStatus = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);

        if ($tempFile !== null) {
            fclose($tempFile);
        }

        if (!$sentHeaders) {
            $this->emitHeaders($finalStatus ?: 502, $responseHeaders, null);
        }

        if ($error !== null) {
            Logger::warning('recording.proxy_failed', ['url_host' => parse_url($url, PHP_URL_HOST), 'error' => $error]);
            if ($tempPath !== null) {
                @unlink($tempPath);
            }

            return;
        }

        if ($tempPath !== null) {
            if ($finalStatus === 200 && is_file($tempPath) && filesize($tempPath) > 1024) {
                @rename($tempPath, (string) $cachePath);
            } else {
                @unlink($tempPath);
            }
        }
    }

    /** @param array<string, string> $upstream */
    private function emitHeaders(int $status, array $upstream, ?string $downloadName): void
    {
        if (!headers_sent()) {
            http_response_code($status);

            $contentType = $upstream['content-type'] ?? 'audio/wav';
            if (!str_starts_with($contentType, 'audio/') && $contentType !== 'application/octet-stream') {
                $contentType = 'audio/wav';
            }

            header('Content-Type: ' . $contentType, true);
            header('Cache-Control: private, max-age=0, no-store', true);
            header('Accept-Ranges: bytes', true);

            foreach (['content-length', 'content-range', 'last-modified', 'etag'] as $name) {
                if (isset($upstream[$name])) {
                    header($name . ': ' . $upstream[$name], true);
                }
            }

            if ($downloadName !== null) {
                header('Content-Disposition: attachment; filename="' . $downloadName . '"', true);
            }
        }
    }

    private function serveLocal(string $path, Request $request, string $name): Response
    {
        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        $range = $request->header('range');

        if ($range !== null && preg_match('/bytes=(\d*)-(\d*)/', $range, $m)) {
            $start = $m[1] !== '' ? (int) $m[1] : 0;
            $end = $m[2] !== '' ? (int) $m[2] : $size - 1;
            $end = min($end, $size - 1);
            if ($start > $end) {
                $start = 0;
                $end = $size - 1;
            } else {
                $status = 206;
            }
        }

        $length = $end - $start + 1;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new HttpException(404);
        }
        fseek($handle, $start);

        $response = Response::stream(function () use ($handle, $length): void {
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, min(262144, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
            fclose($handle);
        })
            ->withStatus($status)
            ->withHeader('Content-Type', 'audio/wav')
            ->withHeader('Content-Length', (string) $length)
            ->withHeader('Accept-Ranges', 'bytes')
            ->withHeader('Cache-Control', 'private, max-age=3600');

        if ($status === 206) {
            $response = $response->withHeader('Content-Range', sprintf('bytes %d-%d/%d', $start, $end, $size));
        }

        return $response;
    }
}
