<?php

namespace App\Console\Commands;

use App\Models\Server;
use App\Models\StatServer;
use App\Models\User;
use App\Services\OutlineService;
use App\Utils\CacheKey;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncOutlineAccessKeys extends Command
{
    protected $signature = 'sync:outline-access-keys';

    protected $description = 'Delete Outline access keys for users or nodes that are no longer allowed';
    private const ACTIVE_WINDOW_SECONDS = 300;
    private const HISTORY_TTL_SECONDS = 3600;

    public function handle(OutlineService $outlineService): int
    {
        $checked = 0;
        $deleted = 0;
        $synced = 0;
        $failed = 0;
        $serverTrafficDeltas = [];
        $serverActiveUsers = [];

        DB::table('v2_outline_access_keys')
            ->orderBy('id')
            ->chunkById(100, function ($records) use ($outlineService, &$checked, &$deleted, &$synced, &$failed, &$serverTrafficDeltas, &$serverActiveUsers) {
                foreach ($records as $record) {
                    $checked++;

                    $server = Server::find($record->server_id);
                    $user = User::find($record->user_id);

                    try {
                        if ($outlineService->shouldKeepAccessKey($server, $user)) {
                            $updatedRecord = $outlineService->syncAccessKeyRecord($server, $user, $record);
                            $previousUsage = (int) ($record->remote_data_usage_bytes ?? 0);
                            $currentUsage = (int) ($updatedRecord['remote_data_usage_bytes'] ?? 0);
                            $delta = max(0, $currentUsage - $previousUsage);

                            if ($delta > 0) {
                                $serverTrafficDeltas[$record->server_id] = ($serverTrafficDeltas[$record->server_id] ?? 0) + $delta;
                            }

                            if ($this->isAccessKeyActive((int) $record->id, $currentUsage, $now = time())) {
                                $serverActiveUsers[$record->server_id] = ($serverActiveUsers[$record->server_id] ?? 0) + 1;
                            } else {
                                $serverActiveUsers[$record->server_id] = $serverActiveUsers[$record->server_id] ?? 0;
                            }
                            $synced++;
                            continue;
                        }

                        if ($outlineService->deleteAccessKeyRecord($record)) {
                            $deleted++;
                        } else {
                            $failed++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning('Outline access key sync job failed for one record', [
                            'record_id' => $record->id,
                            'server_id' => $record->server_id,
                            'user_id' => $record->user_id,
                            'access_key_id' => $record->access_key_id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            });

        $this->syncOutlineServerStats($serverTrafficDeltas, $serverActiveUsers);

        $this->info("Checked {$checked} Outline access keys, synced {$synced}, deleted {$deleted} stale keys, failed {$failed}.");
        return self::SUCCESS;
    }

    private function syncOutlineServerStats(array $serverTrafficDeltas, array $serverActiveUsers): void
    {
        $recordAt = strtotime(date('Y-m-d'));
        $now = time();
        $cacheTime = max(300, (int) admin_setting('server_push_interval', 60) * 3);
        $remoteUsageTotals = DB::table('v2_outline_access_keys')
            ->selectRaw('server_id, SUM(COALESCE(remote_data_usage_bytes, 0)) as total_usage')
            ->groupBy('server_id')
            ->pluck('total_usage', 'server_id');

        $outlineServerIds = Server::where('type', Server::TYPE_OUTLINE)->pluck('id')->all();
        foreach ($outlineServerIds as $serverId) {
            $activeUsers = (int) ($serverActiveUsers[$serverId] ?? 0);
            $lastPushCacheKey = CacheKey::get('SERVER_OUTLINE_LAST_PUSH_AT', $serverId);

            Cache::put(CacheKey::get('SERVER_OUTLINE_ONLINE_USER', $serverId), $activeUsers, $cacheTime);
            Cache::put(CacheKey::get('SERVER_OUTLINE_LAST_CHECK_AT', $serverId), $now, $cacheTime);

            if ($activeUsers > 0) {
                Cache::put($lastPushCacheKey, $now, $cacheTime);
            } else {
                Cache::forget($lastPushCacheKey);
            }

            $delta = (int) ($serverTrafficDeltas[$serverId] ?? 0);
            $remoteTotal = (int) ($remoteUsageTotals[$serverId] ?? 0);
            $recordedTotal = (int) DB::table('v2_server')
                ->where('id', $serverId)
                ->selectRaw('COALESCE(u, 0) + COALESCE(d, 0) as total')
                ->value('total');

            if ($remoteTotal > $recordedTotal) {
                $delta += ($remoteTotal - $recordedTotal);
            }

            if ($delta <= 0) {
                continue;
            }

            DB::table('v2_server')
                ->where('id', $serverId)
                ->incrementEach(
                    ['d' => $delta],
                    ['updated_at' => Carbon::now()]
                );

            StatServer::upsert(
                [
                    'record_at' => $recordAt,
                    'server_id' => $serverId,
                    'server_type' => 'outline',
                    'record_type' => 'd',
                    'u' => 0,
                    'd' => $delta,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
                ['server_id', 'server_type', 'record_at', 'record_type'],
                [
                    'u' => DB::raw('u + VALUES(u)'),
                    'd' => DB::raw('d + VALUES(d)'),
                    'updated_at' => $now,
                ]
            );
        }
    }

    private function isAccessKeyActive(int $recordId, int $currentUsage, int $now): bool
    {
        $cacheKey = "outline-usage-history:{$recordId}";
        $history = Cache::get($cacheKey, []);
        if (!is_array($history)) {
            $history = [];
        }

        $history[] = [
            'timestamp' => $now,
            'usage' => $currentUsage,
        ];

        $history = array_values(array_filter($history, function ($item) use ($now) {
            return is_array($item)
                && isset($item['timestamp'], $item['usage'])
                && ($now - (int) $item['timestamp']) <= self::HISTORY_TTL_SECONDS;
        }));

        usort($history, fn($a, $b) => (int) $a['timestamp'] <=> (int) $b['timestamp']);

        Cache::put($cacheKey, $history, self::HISTORY_TTL_SECONDS);

        $baseline = null;
        foreach ($history as $snapshot) {
            if (($now - (int) $snapshot['timestamp']) >= self::ACTIVE_WINDOW_SECONDS) {
                $baseline = (int) $snapshot['usage'];
                break;
            }
        }

        if ($baseline === null) {
            return false;
        }

        return $currentUsage > $baseline;
    }
}
