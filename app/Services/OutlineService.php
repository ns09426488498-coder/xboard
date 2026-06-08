<?php

namespace App\Services;

use App\Models\Server;
use App\Models\User;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OutlineService
{
    public static function parseManagerConfig(?string $value): array
    {
        $value = trim((string) $value);
        if ($value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (is_array($decoded)) {
            return array_filter([
                'api_url' => $decoded['apiUrl'] ?? $decoded['api_url'] ?? null,
                'cert_sha256' => $decoded['certSha256'] ?? $decoded['cert_sha256'] ?? null,
            ], fn($item) => filled($item));
        }

        return filter_var($value, FILTER_VALIDATE_URL) ? ['api_url' => $value] : [];
    }

    public static function resolveApiUrl(Server $server): string
    {
        $settings = data_get($server, 'protocol_settings', []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $candidates = [
            $settings['api_url'] ?? null,
            $server->host,
        ];

        foreach ($candidates as $candidate) {
            $parsed = self::parseManagerConfig($candidate);
            if (!empty($parsed['api_url'])) {
                return rtrim($parsed['api_url'], '/');
            }
        }

        return '';
    }

    public function isReachable(Server $server): bool
    {
        try {
            $apiUrl = self::resolveApiUrl($server);
            if ($apiUrl === '') {
                return false;
            }
            $this->clientFromConfig(
                $apiUrl,
                (bool) data_get($server, 'protocol_settings.verify_tls', false),
                3,
                2
            )->get('access-keys');
            return true;
        } catch (\Throwable $e) {
            Log::warning('Outline management API unreachable', [
                'server_id' => $server->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    public function getOrCreateAccessKey(Server $server, User $user): ?array
    {
        if (!$this->shouldKeepAccessKey($server, $user)) {
            return null;
        }

        $existing = DB::table('v2_outline_access_keys')
            ->where('server_id', $server->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->access_url) {
            try {
                if (filled($existing->api_url ?? null) && rtrim((string) $existing->api_url, '/') !== self::resolveApiUrl($server)) {
                    if (!$this->deleteAccessKeyRecord($existing)) {
                        return null;
                    }
                } else {
                    return $this->syncAccessKeyRecord($server, $user, $existing);
                }
            } catch (ClientException $e) {
                if ($e->getResponse()?->getStatusCode() !== 404) {
                    Log::warning('Outline access key sync failed', [
                        'server_id' => $server->id,
                        'user_id' => $user->id,
                        'access_key_id' => $existing->access_key_id,
                        'error' => $e->getMessage(),
                    ]);
                    return null;
                }

                DB::table('v2_outline_access_keys')->where('id', $existing->id)->delete();
            } catch (\Throwable $e) {
                Log::warning('Outline access key sync failed', [
                    'server_id' => $server->id,
                    'user_id' => $user->id,
                    'access_key_id' => $existing->access_key_id,
                    'error' => $e->getMessage(),
                ]);
                return null;
            }
        }

        try {
            $key = $this->createRemoteKey($server);
            $name = $this->buildAccessKeyName($server, $user);
            $this->renameRemoteKey($server, $key['id'], $name);
            $limit = $this->applyDataLimit($server, $user, $key['id']);
            $usage = $this->getAccessKeyUsage($server, (string) $key['id']);

            $record = [
                'server_id' => $server->id,
                'user_id' => $user->id,
                'access_key_id' => (string) $key['id'],
                'name' => $name,
                'access_url' => (string) $key['accessUrl'],
                'api_url' => self::resolveApiUrl($server),
                'cert_sha256' => (string) data_get($server, 'protocol_settings.cert_sha256'),
                'method' => $key['method'] ?? null,
                'password' => $key['password'] ?? null,
                'port' => $key['port'] ?? null,
                'data_limit_bytes' => $limit,
                'remote_data_usage_bytes' => $usage,
                'last_synced_at' => time(),
                'created_at' => time(),
                'updated_at' => time(),
            ];

            DB::table('v2_outline_access_keys')->updateOrInsert(
                ['server_id' => $server->id, 'user_id' => $user->id],
                $record
            );

            return $record;
        } catch (\Throwable $e) {
            Log::warning('Outline access key provisioning failed', [
                'server_id' => $server->id,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    public static function accessUrlWithName(string $accessUrl, string $name): string
    {
        $withoutFragment = explode('#', $accessUrl, 2)[0];
        return $withoutFragment . '#' . rawurlencode($name);
    }

    public function deleteAccessKeysForUser(User|int $user, bool $failOnError = false): int
    {
        $userId = $user instanceof User ? $user->id : $user;
        $deleted = 0;
        $failed = 0;

        DB::table('v2_outline_access_keys')
            ->where('user_id', $userId)
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$deleted, &$failed) {
                foreach ($records as $record) {
                    if ($this->deleteAccessKeyRecord($record)) {
                        $deleted++;
                        continue;
                    }
                    $failed++;
                }
            });

        if ($failOnError && $failed > 0) {
            throw new \RuntimeException("Failed to delete {$failed} Outline access keys");
        }

        return $deleted;
    }

    public function deleteAccessKeysForServer(Server|int $server, bool $failOnError = false): int
    {
        $serverId = $server instanceof Server ? $server->id : $server;
        $deleted = 0;
        $failed = 0;

        DB::table('v2_outline_access_keys')
            ->where('server_id', $serverId)
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$deleted, &$failed) {
                foreach ($records as $record) {
                    if ($this->deleteAccessKeyRecord($record)) {
                        $deleted++;
                        continue;
                    }
                    $failed++;
                }
            });

        if ($failOnError && $failed > 0) {
            throw new \RuntimeException("Failed to delete {$failed} Outline access keys");
        }

        return $deleted;
    }

    public function deleteAccessKeyRecord(object $record): bool
    {
        try {
            $this->clientForRecord($record)->delete("access-keys/{$record->access_key_id}");
        } catch (ClientException $e) {
            if ($e->getResponse()?->getStatusCode() !== 404) {
                Log::warning('Outline access key deletion failed', [
                    'server_id' => $record->server_id,
                    'user_id' => $record->user_id,
                    'access_key_id' => $record->access_key_id,
                    'error' => $e->getMessage(),
                ]);
                return false;
            }
        } catch (\Throwable $e) {
            Log::warning('Outline access key deletion failed', [
                'server_id' => $record->server_id,
                'user_id' => $record->user_id,
                'access_key_id' => $record->access_key_id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }

        DB::table('v2_outline_access_keys')->where('id', $record->id)->delete();
        return true;
    }

    public function shouldKeepAccessKey(?Server $server, ?User $user): bool
    {
        if (!$server || !$user || $server->type !== Server::TYPE_OUTLINE) {
            return false;
        }

        if ($user->banned || !$user->transfer_enable || $user->getRemainingTraffic() <= 0) {
            return false;
        }

        if ($user->expired_at !== null && $user->expired_at <= time()) {
            return false;
        }

        if (!$server->show) {
            return false;
        }

        if ($server->transfer_enable && ((int) $server->u + (int) $server->d) >= (int) $server->transfer_enable) {
            return false;
        }

        return in_array((string) $user->group_id, $server->group_ids ?? [], true);
    }

    public static function asShadowsocksServer(?string $accessUrl, array $server): ?array
    {
        $parsed = self::parseAccessUrl($accessUrl);
        if (!$parsed) {
            return null;
        }

        $protocolSettings = data_get($server, 'protocol_settings', []);
        if (!is_array($protocolSettings)) {
            $protocolSettings = [];
        }

        $server['host'] = $parsed['host'];
        $server['port'] = $parsed['port'];
        $server['password'] = $parsed['password'];
        $server['protocol_settings'] = array_merge($protocolSettings, [
            'cipher' => $parsed['method'],
        ]);

        return $server;
    }

    public static function parseAccessUrl(?string $accessUrl): ?array
    {
        if (!$accessUrl || !str_starts_with($accessUrl, 'ss://')) {
            return null;
        }

        $parts = parse_url($accessUrl);
        if (!$parts) {
            return null;
        }

        if (!empty($parts['host']) && !empty($parts['port'])) {
            $userInfo = $parts['user'] ?? '';
            $password = $parts['pass'] ?? null;
            $method = null;

            if ($password !== null) {
                $method = rawurldecode($userInfo);
                $password = rawurldecode($password);
            } else {
                $decoded = self::base64UrlDecode($userInfo);
                if (!$decoded || !str_contains($decoded, ':')) {
                    return null;
                }
                [$method, $password] = explode(':', $decoded, 2);
            }

            return [
                'method' => $method,
                'password' => $password,
                'host' => $parts['host'],
                'port' => (int) $parts['port'],
                'query' => $parts['query'] ?? null,
            ];
        }

        $payload = substr(explode('#', $accessUrl, 2)[0], 5);
        $decoded = self::base64UrlDecode($payload);
        if (!$decoded) {
            return null;
        }

        $decodedParts = parse_url('ss://' . $decoded);
        if (
            !$decodedParts
            || empty($decodedParts['user'])
            || !isset($decodedParts['pass'])
            || empty($decodedParts['host'])
            || empty($decodedParts['port'])
        ) {
            return null;
        }

        return [
            'method' => rawurldecode($decodedParts['user']),
            'password' => rawurldecode($decodedParts['pass']),
            'host' => $decodedParts['host'],
            'port' => (int) $decodedParts['port'],
            'query' => $decodedParts['query'] ?? null,
        ];
    }

    private function createRemoteKey(Server $server): array
    {
        $response = $this->client($server)->post('access-keys');
        return $this->decodeResponse($response->getBody()->getContents());
    }

    private function renameRemoteKey(Server $server, string $accessKeyId, string $name): void
    {
        $this->client($server)->put("access-keys/{$accessKeyId}/name", [
            'json' => ['name' => $name],
        ]);
    }

    public function syncAccessKeyLimit(Server $server, User $user, string $accessKeyId): void
    {
        $this->applyDataLimit($server, $user, $accessKeyId);
    }

    public function syncAccessKeyRecord(Server $server, User $user, object $record): array
    {
        $name = $this->buildAccessKeyName($server, $user);
        if ((string) $record->name !== $name) {
            $this->renameRemoteKey($server, (string) $record->access_key_id, $name);
        }

        $limit = $this->applyDataLimit($server, $user, (string) $record->access_key_id);
        $usage = $this->getAccessKeyUsage($server, (string) $record->access_key_id);

        $updates = [
            'name' => $name,
            'api_url' => self::resolveApiUrl($server),
            'cert_sha256' => (string) data_get($server, 'protocol_settings.cert_sha256'),
            'data_limit_bytes' => $limit,
            'remote_data_usage_bytes' => $usage,
            'last_synced_at' => time(),
            'updated_at' => time(),
        ];

        DB::table('v2_outline_access_keys')->where('id', $record->id)->update($updates);

        return array_merge((array) $record, $updates);
    }

    private function applyDataLimit(Server $server, User $user, string $accessKeyId): int
    {
        $mode = data_get($server, 'protocol_settings.data_limit_mode', 'user_remaining') ?: 'user_remaining';
        $quota = match ($mode) {
            'user_total' => (int) $user->transfer_enable,
            'user_remaining' => max(0, (int) $user->transfer_enable - (int) $user->u - (int) $user->d),
            default => 0,
        };

        if ($quota <= 0) {
            $this->client($server)->put("access-keys/{$accessKeyId}/data-limit", [
                'json' => ['limit' => ['bytes' => 1]],
            ]);
            return 1;
        }

        $limit = $quota + $this->getAccessKeyUsage($server, $accessKeyId);

        $this->client($server)->put("access-keys/{$accessKeyId}/data-limit", [
            'json' => ['limit' => ['bytes' => $limit]],
        ]);

        return $limit;
    }

    private function client(Server $server): Client
    {
        $apiUrl = self::resolveApiUrl($server);
        if ($apiUrl === '') {
            throw new \InvalidArgumentException('Outline api_url is empty');
        }

        return $this->clientFromConfig(
            $apiUrl,
            (bool) data_get($server, 'protocol_settings.verify_tls', false)
        );
    }

    private function clientForRecord(object $record): Client
    {
        $apiUrl = trim((string) ($record->api_url ?? ''));
        if ($apiUrl !== '') {
            return $this->clientFromConfig($apiUrl, false);
        }

        $server = Server::find($record->server_id);
        if (!$server) {
            throw new \RuntimeException('Outline api_url snapshot is empty');
        }

        return $this->client($server);
    }

    private function clientFromConfig(
        string $apiUrl,
        bool $verifyTls = false,
        int $timeout = 8,
        int $connectTimeout = 5
    ): Client
    {
        return new Client([
            'base_uri' => rtrim($apiUrl, '/') . '/',
            'timeout' => $timeout,
            'connect_timeout' => $connectTimeout,
            'http_errors' => true,
            // Outline management API normally uses a self-signed cert. The secret
            // management URL is still required; cert pinning can be added later.
            'verify' => $verifyTls,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    private function getAccessKeyUsage(Server $server, string $accessKeyId): int
    {
        try {
            $response = $this->client($server)->get('metrics/transfer');
            $data = $this->decodeResponse($response->getBody()->getContents());
            return (int) data_get($data, "bytesTransferredByUserId.{$accessKeyId}", 0);
        } catch (\Throwable $e) {
            Log::warning('Outline access key usage fetch failed', [
                'server_id' => $server->id,
                'access_key_id' => $accessKeyId,
                'error' => $e->getMessage(),
            ]);
            return 0;
        }
    }

    private function buildAccessKeyName(Server $server, User $user): string
    {
        $pattern = data_get($server, 'protocol_settings.key_name_pattern', 'xboard-user-{id}');
        return str_replace(
            ['{id}', '{email}', '{uuid}', '{server}'],
            [(string) $user->id, (string) $user->email, (string) $user->uuid, (string) $server->name],
            $pattern
        );
    }

    private function decodeResponse(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid Outline API JSON response');
        }
        return $data;
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);
        return $decoded === false ? null : $decoded;
    }
}
