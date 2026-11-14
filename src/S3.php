<?php

declare(strict_types=1);

namespace ZarsPanel;

/** Upload to any S3-compatible storage (AWS S3, Wasabi, Backblaze B2, MinIO, DigitalOcean Spaces ...) with AWS signature v4, using curl. */
final class S3
{
    /** Signature v4 "Authorization" value (checked against the published AWS example in the tests). */
    public static function authorization(string $method, string $path, string $query, array $headers, string $payloadHash, string $amzDate, string $region, string $access, string $secret, string $service = 's3'): string
    {
        $headers = array_change_key_case($headers);
        ksort($headers);
        $canonHeaders = '';
        foreach ($headers as $k => $v) {
            $canonHeaders .= $k . ':' . trim(preg_replace('/\s+/', ' ', (string) $v)) . "\n";
        }
        $signed = implode(';', array_keys($headers));
        $canonical = implode("\n", [strtoupper($method), $path, $query, $canonHeaders, $signed, $payloadHash]);
        $date = substr($amzDate, 0, 8);
        $scope = "$date/$region/$service/aws4_request";
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $amzDate, $scope, hash('sha256', $canonical)]);
        $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', $service, hash_hmac('sha256', $region, hash_hmac('sha256', $date, 'AWS4' . $secret, true), true), true), true);

        return "AWS4-HMAC-SHA256 Credential=$access/$scope, SignedHeaders=$signed, Signature=" . hash_hmac('sha256', $toSign, $k);
    }

    public static function encodePath(string $key): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $key)));
    }

    /** @param array{endpoint: string, bucket: string, region: string, access: string, secret: string, prefix?: string} $c */
    public static function put(array $c, string $file, string $key): void
    {
        $host = preg_replace('#^https?://#', '', rtrim($c['endpoint'], '/'));
        $path = '/' . rawurlencode($c['bucket']) . '/' . self::encodePath(trim(($c['prefix'] ?? '') . '/' . $key, '/'));
        $amz = gmdate('Ymd\THis\Z');
        $hash = 'UNSIGNED-PAYLOAD';
        $auth = self::authorization('PUT', $path, '', ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => $amz], $hash, $amz, $c['region'] ?: 'us-east-1', $c['access'], $c['secret']);
        $r = Exec::run(['curl', '-fsS', '-X', 'PUT', '-T', $file, '-H', "x-amz-date: $amz", '-H', "x-amz-content-sha256: $hash", '-H', "Authorization: $auth", 'https://' . $host . $path], null, 3600);
        if ($r['code'] !== 0) {
            throw new PanelError('The upload to the cloud storage failed: ' . mb_substr(trim($r['out']), 0, 200), 502);
        }
    }

    public static function config(): ?array
    {
        $raw = Db::setting('backup.s3');
        $c = $raw ? json_decode((string) Secrets::open($raw), true) : null;

        return is_array($c) && !empty($c['endpoint']) && !empty($c['bucket']) && !empty($c['access']) && !empty($c['secret']) ? $c : null;
    }

    public static function save(array $in): void
    {
        $old = self::config() ?? [];
        $endpoint = trim((string) ($in['endpoint'] ?? ''));
        if ($endpoint !== '' && !preg_match('#^(https://)?[a-z0-9.-]+(:\d+)?$#i', $endpoint)) {
            throw new PanelError('The endpoint looks like s3.amazonaws.com or s3.eu-central-1.wasabisys.com', 422);
        }
        $c = ['endpoint' => $endpoint ?: ($old['endpoint'] ?? ''), 'bucket' => trim((string) ($in['bucket'] ?? ($old['bucket'] ?? ''))), 'region' => trim((string) ($in['region'] ?? ($old['region'] ?? ''))) ?: 'us-east-1',
            'access' => trim((string) ($in['access'] ?? '')) ?: ($old['access'] ?? ''), 'secret' => trim((string) ($in['secret'] ?? '')) ?: ($old['secret'] ?? ''), 'prefix' => trim((string) ($in['prefix'] ?? ($old['prefix'] ?? '')), '/ ')];
        if (!preg_match('/^[a-z0-9][a-z0-9.-]{1,61}$/', $c['bucket']) && $c['bucket'] !== '') {
            throw new PanelError('The bucket name is not valid.', 422);
        }
        Db::put('backup.s3', Secrets::seal(json_encode($c)));
    }
}
