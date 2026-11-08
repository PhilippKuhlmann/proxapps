<?php

namespace App\Services\Proxmox;

/**
 * TLS helpers: Proxmox ships certificates signed by its own cluster CA whose SAN usually
 * does not contain the address people connect to. Instead of disabling verification we pin
 * the server's public key (trust on first use, confirmed by the user in the UI). curl checks
 * the pin inside the handshake, before any HTTP bytes (and therefore the API token) are sent.
 */
class Tls
{
    public static function normalizeUrl(string $raw): string
    {
        $url = rtrim(trim($raw), '/');
        if (! preg_match('~^https?://~i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? ($parts['host'] ?? '') : '';
        if ($host === '' || preg_match('~[\s/]~', $host)) {
            throw new ProxmoxException('Ungültige Adresse.');
        }
        $host = trim($host, '[]');
        if (str_contains($host, ':')) {
            $host = '['.$host.']';
        }
        $port = (int) ($parts['port'] ?? 8006);

        return 'https://'.strtolower($host).':'.$port;
    }

    /**
     * Connect without verification and capture the presented leaf certificate.
     *
     * @return array{url: string, fingerprint: string, pin: string}
     */
    public static function inspect(string $url, float $timeout = 8.0): array
    {
        $url = static::normalizeUrl($url);
        $parts = parse_url($url);
        $host = trim((string) $parts['host'], '[]');
        $port = (int) $parts['port'];
        $target = str_contains($host, ':') ? "[{$host}]" : $host;

        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'SNI_enabled' => true,
            'peer_name' => $host,
        ]]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client("ssl://{$target}:{$port}", $errno, $errstr, $timeout,
            STREAM_CLIENT_CONNECT, $context);
        if ($stream === false) {
            $reason = trim((string) $errstr) !== '' ? trim($errstr) : 'Zeitüberschreitung';
            throw new ProxmoxException("Keine Verbindung zu {$host}:{$port} ({$reason})");
        }

        try {
            $params = stream_context_get_params($stream);
            $cert = $params['options']['ssl']['peer_certificate'] ?? null;
            if (! $cert) {
                throw new ProxmoxException("Der Server {$host}:{$port} hat kein Zertifikat geliefert.");
            }

            return ['url' => $url] + static::certificateInfo($cert);
        } finally {
            fclose($stream);
        }
    }

    /**
     * Fingerprint (SHA-256 of the DER certificate) and curl pin (SHA-256 of the DER SubjectPublicKeyInfo).
     *
     * @return array{fingerprint: string, pin: string}
     */
    public static function certificateInfo(\OpenSSLCertificate|string $cert): array
    {
        $pem = '';
        if (! openssl_x509_export($cert, $pem)) {
            throw new ProxmoxException('Das Zertifikat des Servers konnte nicht gelesen werden.');
        }
        $der = static::pemToDer($pem);

        $key = openssl_pkey_get_public($cert);
        $details = $key ? openssl_pkey_get_details($key) : false;
        if (! $details || empty($details['key'])) {
            throw new ProxmoxException('Der öffentliche Schlüssel des Servers konnte nicht gelesen werden.');
        }
        $spki = static::pemToDer($details['key']);

        return [
            'fingerprint' => static::formatFingerprint(hash('sha256', $der)),
            'pin' => 'sha256//'.base64_encode(hash('sha256', $spki, true)),
        ];
    }

    public static function pemToDer(string $pem): string
    {
        $body = preg_replace('~-----(BEGIN|END)[^-]+-----~', '', $pem);

        return (string) base64_decode(preg_replace('~\s+~', '', (string) $body), true);
    }

    public static function normalizeFingerprint(string $fp): string
    {
        return (string) preg_replace('~[^0-9A-F]~', '', strtoupper($fp));
    }

    public static function formatFingerprint(string $fp): string
    {
        $fp = static::normalizeFingerprint($fp);

        return $fp === '' ? '' : implode(':', str_split($fp, 2));
    }
}
