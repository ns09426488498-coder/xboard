<?php

namespace App\Observers;

use App\Jobs\NodeUserSyncJob;
use App\Models\User;
use App\Services\OutlineService;
use App\Services\TrafficResetService;
use Illuminate\Support\Facades\Log;

class UserObserver
{
  public bool $afterCommit = true;

  public function __construct(
    private readonly TrafficResetService $trafficResetService,
    private readonly OutlineService $outlineService
  ) {
  }

  public function updated(User $user): void
  {
    // With $afterCommit = true, isDirty() is always false after commit.
    // Use wasChanged() to detect what was actually modified.
    $syncFields = ['group_id', 'uuid', 'speed_limit', 'device_limit', 'banned', 'expired_at', 'transfer_enable', 'u', 'd', 'plan_id'];
    $needsSync = $user->wasChanged($syncFields);
    $oldGroupId = $user->wasChanged('group_id') ? $user->getOriginal('group_id') : null;

    if ($this->shouldRotateOutlineKeys($user)) {
      $this->deleteOutlineKeysSafely($user);
    }

    if ($user->wasChanged(['plan_id', 'expired_at'])) {
      $this->recalculateNextResetAt($user);
    }

    if ($needsSync) {
      $this->dispatchNodeUserSyncSafely($user->id, 'updated', $oldGroupId);
    }
  }

  public function created(User $user): void
  {
    $this->recalculateNextResetAt($user);
    $this->dispatchNodeUserSyncSafely($user->id, 'created');
  }

  public function deleted(User $user): void
  {
    if ($user->group_id) {
      $this->dispatchNodeUserSyncSafely($user->id, 'deleted', $user->group_id);
    }
  }

  /**
   * 根据当前用户状态重新计算 next_reset_at
   */
  private function recalculateNextResetAt(User $user): void
  {
    $user->refresh();
    User::withoutEvents(function () use ($user) {
      $nextResetTime = $this->trafficResetService->calculateNextResetTime($user);
      $user->next_reset_at = $nextResetTime?->timestamp;
      $user->save();
    });
  }

  private function shouldRotateOutlineKeys(User $user): bool
  {
    if ($user->wasChanged(['uuid', 'token', 'plan_id', 'expired_at', 'transfer_enable'])) {
      return true;
    }

    foreach (['u', 'd'] as $field) {
      if (!$user->wasChanged($field)) {
        continue;
      }

      if ((int) $user->{$field} < (int) $user->getOriginal($field)) {
        return true;
      }
    }

    return false;
  }

  private function deleteOutlineKeysSafely(User $user): void
  {
    try {
      $this->outlineService->deleteAccessKeysForUser($user);
    } catch (\Throwable $e) {
      Log::warning('User update completed, but Outline key rotation failed', [
        'user_id' => $user->id,
        'error' => $e->getMessage(),
      ]);
    }
  }

  private function dispatchNodeUserSyncSafely(int $userId, string $action, ?int $oldGroupId = null): void
  {
    try {
      NodeUserSyncJob::dispatch($userId, $action, $oldGroupId);
    } catch (\Throwable $e) {
      Log::warning('NodeUserSyncJob queue dispatch failed, running synchronously', [
        'user_id' => $userId,
        'action' => $action,
        'old_group_id' => $oldGroupId,
        'error' => $e->getMessage(),
      ]);

      NodeUserSyncJob::dispatchSync($userId, $action, $oldGroupId);
    }
  }
}
