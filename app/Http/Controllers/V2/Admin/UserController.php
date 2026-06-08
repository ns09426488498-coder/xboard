<?php

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserGenerate;
use App\Http\Requests\Admin\UserSendMail;
use App\Http\Requests\Admin\UserUpdate;
use App\Jobs\SendEmailJob;
use App\Models\Plan;
use App\Models\User;
use App\Services\AuthService;
use App\Services\NodeSyncService;
use App\Services\OutlineService;
use App\Services\Plugin\HookManager;
use App\Services\UserService;
use App\Traits\QueryOperators;
use App\Utils\Helper;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class UserController extends Controller
{
    use QueryOperators;

    public function resetSecret(Request $request)
    {
        $user = User::find($request->input('id'));
        if (!$user)
            return $this->fail([400202, 'User not found']);
        $user->token = Helper::guid();
        $user->uuid = Helper::guid(true);
        $result = $user->save();

        if ($result) {
            HookManager::call('admin.user.secret.reset', [
                'user' => $user,
                'request' => $request,
            ]);
        }

        return $this->success($result);
    }

    // Apply filters and sorts to the query builder.
    private function applyFiltersAndSorts(Request $request, Builder|QueryBuilder $builder): void
    {
        $this->applyFilters($request, $builder);
        $this->applySorting($request, $builder);
    }

    // Apply filters to the query builder.
    private function applyFilters(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('filter')) {
            return;
        }

        collect($request->input('filter'))->each(function ($filter) use ($builder) {
            $field = $filter['id'];
            $value = $filter['value'];
            $logic = strtolower($filter['logic'] ?? 'and');

            if ($logic === 'or') {
                $builder->orWhere(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            } else {
                $builder->where(function ($query) use ($field, $value) {
                    $this->buildFilterQuery($query, $field, $value);
                });
            }
        });
    }

    // Build one filter query condition.
    private function buildFilterQuery(Builder|QueryBuilder $query, string $field, mixed $value): void
    {
        // 婵犮垼娉涚€氼噣骞冩繝鍥х閻庡湱濮崇划鎾绘煛鐏炶鍔ユい?
        if (str_contains($field, '.')) {
            if (!method_exists($query, 'whereHas')) {
                return;
            }
            [$relation, $relationField] = explode('.', $field);
            $query->whereHas($relation, function ($q) use ($relationField, $value) {
                if (is_array($value)) {
                    $q->whereIn($relationField, $value);
                } else if (is_string($value) && str_contains($value, ':')) {
                    [$operator, $filterValue] = explode(':', $value, 2);
                    $this->applyQueryCondition($q, $relationField, $operator, $filterValue);
                } else {
                    $q->where($relationField, 'like', "%{$value}%");
                }
            });
            return;
        }

        // 婵犮垼娉涚€氼噣骞冩繝鍥ф瀬闁规鍠氶惌瀣煕婵犲懎浜规繛?'in' 闂佺懓鐏濈粔宕囩礊?
        if (is_array($value)) {
            $query->whereIn($field === 'group_ids' ? 'group_id' : $field, $value);
            return;
        }

        // 婵犮垼娉涚€氼噣骞冩繝鍥ф槬閺夌偞澹嗛懝楣冨级閳哄倸濮夐柣锝堝吹缁參鏁傞悙顒傛殸闁哄鏅涘ú锕傚箮?
        if (!is_string($value) || !str_contains($value, ':')) {
            $query->where($field, 'like', "%{$value}%");
            return;
        }

        [$operator, $filterValue] = explode(':', $value, 2);

        // 闁哄鍎愰崜姘暦閺屻儱鏋侀柡澶庢硶閹界喖鎮楀☉娆樻畼妞ゆ垳鐒︾粙澶愮叓椤擄紕顦伴梻渚囧亜閸婂摜绱炵€ｎ喗鍎嶉柛鏇ㄥ弨椤箓鏌?
        if (is_numeric($filterValue)) {
            $filterValue = strpos($filterValue, '.') !== false
                ? (float) $filterValue
                : (int) $filterValue;
        }

        // 婵犮垼娉涚€氼噣骞冩繝鍕闁挎稑瀚弳顒勬倵濞戞瑯娈曟い?
        $queryField = match ($field) {
            'total_used' => DB::raw('(u + d)'),
            default => $field
        };

        $this->applyQueryCondition($query, $queryField, $operator, $filterValue);
    }

    // Apply sorting rules to the query builder.
    private function applySorting(Request $request, Builder|QueryBuilder $builder): void
    {
        if (!$request->has('sort')) {
            return;
        }

        collect($request->input('sort'))->each(function ($sort) use ($builder) {
            $field = $sort['id'];
            $direction = $sort['desc'] ? 'DESC' : 'ASC';
            $builder->orderBy($field, $direction);
        });
    }

    // Resolve bulk operation scope and normalize user_ids.
    private function resolveScope(Request $request): array
    {
        $scope = $request->input('scope');
        $userIds = $request->input('user_ids');

        $hasSelection = is_array($userIds) && count(array_filter($userIds, static fn($v) => is_numeric($v))) > 0;
        $hasFilter = $request->has('filter') && !empty($request->input('filter'));

        if (!in_array($scope, ['selected', 'filtered', 'all'], true)) {
            if ($hasSelection) {
                $scope = 'selected';
            } elseif ($hasFilter) {
                $scope = 'filtered';
            } else {
                $scope = 'all';
            }
        }

        $normalizedIds = [];
        if ($scope === 'selected') {
            $normalizedIds = is_array($userIds) ? $userIds : [];
            $normalizedIds = array_values(array_unique(array_map(static function ($v) {
                return is_numeric($v) ? (int) $v : null;
            }, $normalizedIds)));
            $normalizedIds = array_values(array_filter($normalizedIds, static fn($v) => is_int($v)));
        }

        return [
            'scope' => $scope,
            'user_ids' => $normalizedIds,
        ];
    }

    // Fetch paginated user list (filters + sorting).
    public function fetch(Request $request)
    {
        $current = $request->input('current', 1);
        $pageSize = $request->input('pageSize', 10);

        $userModel = User::query()
            ->with(['plan:id,name', 'invite_user:id,email', 'group:id,name'])
            ->select((new User())->getTable() . '.*')
            ->selectRaw('(u + d) as total_used');

        $userModel = HookManager::filter('admin.user.fetch.query', $userModel, $request);

        $this->applyFiltersAndSorts($request, $userModel);

        $users = $userModel->orderBy('id', 'desc')
            ->paginate($pageSize, ['*'], 'page', $current);

        $users->getCollection()->transform(function ($user): array {
            return self::transformUserData($user);
        });

        return $this->paginate($users);
    }

    // Transform user fields for API response.
    public static function transformUserData(User $user): array
    {
        $model = $user;
        $user = $user->toArray();
        $user['balance'] = $user['balance'] / 100;
        $user['commission_balance'] = $user['commission_balance'] / 100;
        $user['subscribe_url'] = Helper::getSubscribeUrl($user['token']);
        return HookManager::filter('admin.user.transform', $user, $model);
    }

    public function getUserInfoById(Request $request)
    {
        $request->validate([
            'id' => 'required|numeric'
        ], [
            'id.required' => 'User ID is required'
        ]);
        $user = User::find($request->input('id'))->load('invite_user');
        $user = HookManager::filter('admin.user.detail', $user, $request);
        return $this->success($user);
    }

    public function update(UserUpdate $request)
    {
        $params = $request->validated();

        $user = User::find($request->input('id'));
        if (!$user) {
            return $this->fail([400202, 'User not found']);
        }
        if (isset($params['email'])) {
            if (User::byEmail($params['email'])->first() && $user->email !== $params['email']) {
                return $this->fail([400201, 'Email is already in use']);
            }
        }
        // 婵犮垼娉涚€氼噣骞冩繝鍕ㄥ亾闂堟稒顥犻柣?
        if (isset($params['password'])) {
            $params['password'] = password_hash($params['password'], PASSWORD_DEFAULT);
            $params['password_algo'] = NULL;
        } else {
            unset($params['password']);
        }
        // 婵犮垼娉涚€氼噣骞冩繝鍕闁靛繈鍊栭～澶愭偣娴ｆ祴鍋撻崘鑼喊
        if (isset($params['plan_id'])) {
            $plan = Plan::find($params['plan_id']);
            if (!$plan) {
                return $this->fail([400202, 'Plan not found']);
            }
            $params['group_id'] = $plan->group_id;
        }
        // 婵犮垼娉涚€氼噣骞冩繝鍥ㄧ劷闁逞屽墰閹风姵顦版惔銏℃闂?
        if ($request->input('invite_user_email') && $inviteUser = User::byEmail($request->input('invite_user_email'))->first()) {
            $params['invite_user_id'] = $inviteUser->id;
        } else {
            $params['invite_user_id'] = null;
        }

        if (isset($params['banned']) && (int) $params['banned'] === 1) {
            $authService = new AuthService($user);
            $authService->removeAllSessions();
            app(OutlineService::class)->deleteAccessKeysForUser($user);
        }
        if (isset($params['balance'])) {
            $params['balance'] = $params['balance'] * 100;
        }
        if (isset($params['commission_balance'])) {
            $params['commission_balance'] = $params['commission_balance'] * 100;
        }

        $params = HookManager::filter('admin.user.update.params', $params, $request, $user);

        HookManager::call('admin.user.update.before', [
            'user' => $user,
            'params' => $params,
            'request' => $request,
        ]);

        try {
            $user->update($params);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, 'Save failed']);
        }

        HookManager::call('admin.user.update.after', [
            'user' => $user->refresh(),
            'params' => $params,
            'request' => $request,
        ]);

        return $this->success(true);
    }

    // Export users to CSV.
    public function dumpCSV(Request $request)
    {
        ini_set('memory_limit', '-1');
        gc_enable(); // 闂佸憡鍑归崹鎶藉极閵堝鍨傞柛鎰╁妽缁ㄣ垽鏌涢妷褍浠﹂柡?

        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids is required']);
            }
        }

        // 婵炴潙鍚嬮敋閻庡灚鐓″濠氬Ψ椤垵娈戦梺鎸庣⊕閻喗绻涢崶顒佸仺闁宠櫣鐗玹h婵☆偅婢樼€氼剚鎱ㄩ悙瀛樺缂佸樊婀禷n闂佺绻愮壕顓㈡焾閹绢喗鏅€光偓閸曨亞绱氶梺绋跨箰绾?1闂傚倸鍋嗛崳锝夈€?
        $query = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'asc')
            ->select([
                'email',
                'balance',
                'commission_balance',
                'transfer_enable',
                'u',
                'd',
                'expired_at',
                'token',
                'plan_id'
            ]);

        if ($scope === 'selected') {
            $query->whereIn('id', $userIds);
        } elseif ($scope === 'filtered') {
            $this->applyFiltersAndSorts($request, $query);
        } // all: ignore filter/sort

        $filename = 'users_' . date('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            // 闂佺懓鐏氶幐鍝ユ閹寸偞缍囬柟鎯у暱濮ｅ绻?
            $output = fopen('php://output', 'w');

            // 濠电儑缍€椤曆勬叏濠碘垼M闂佸搫绉村ú鈺咁敊閸ヮ剚鏅€光偓閳ь剟鍨惧Ο鑽も攳婵犲﹦顫el濠殿喗绻愮徊鍧楀灳濮椻偓瀵即宕滆娴犳稑鈽夐幙鍐ㄥ箺闁?
            fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

            // 闂佸憡鍔栭悷銉╁矗閸滅櫇V婵犮垼鍩栭幐鎶藉磿?
            fputcsv($output, [
                'Email',
                'Balance',
                'Commission',
                'Total Traffic',
                'Remaining Traffic',
                'Plan Expire Time',
                'Plan',
                'Subscribe URL'
            ]);

            // 闂佸憡甯掑Λ娆愮珶閹烘挸绶為柛鏇ㄥ幗閸婄偤鏌℃担鍝勵暭鐎规挷鐒︾粋鎺楀Ψ閵夈儲顏￠柣蹇撶箲閸ㄧ敻宕€电硶鍋撳☉娅吋绻涢崶顒佸仺?
            $query->chunk(500, function ($users) use ($output) {
                foreach ($users as $user) {
                    try {
                        $row = [
                            $user->email,
                            number_format($user->balance / 100, 2),
                            number_format($user->commission_balance / 100, 2),
                            Helper::trafficConvert($user->transfer_enable),
                            Helper::trafficConvert($user->transfer_enable - ($user->u + $user->d)),
                            $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : 'Never',
                            $user->plan ? $user->plan->name : 'No Plan',
                            Helper::getSubscribeUrl($user->token)
                        ];
                        fputcsv($output, $row);
                    } catch (\Exception $e) {
                        Log::error('CSV闁诲海鏁搁崢褔宕甸銏＄叆婵炲棙甯╅崵? ' . $e->getMessage(), [
                            'user_id' => $user->id,
                            'email' => $user->email
                        ]);
                        continue; // 缂傚倷缍€閸涱垱鏆版繝銏ｆ硾鐎氼噣骞冩繝鍐枖閻庯絺鏅濋閬嶆煛婢规嚎鍊ら崬鍓佹喐?
                    }
                }

                // 濠电偞鎸搁幊鎰板箖婵犲洤绀冮柛娑卞幘閹?
                gc_collect_cycles();
            });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"'
        ]);
    }

    public function generate(UserGenerate $request)
    {
        if ($request->input('email_prefix')) {
            // If generate_count is specified with email_prefix, generate multiple users with incremented emails
            if ($request->input('generate_count')) {
                return $this->multiGenerateWithPrefix($request);
            }
            
            // Single user generation with email_prefix
            $email = $request->input('email_prefix') . '@' . $request->input('email_suffix');

            if (User::byEmail($email)->exists()) {
                return $this->fail([400201, 'Email already exists in the system']);
            }

            $userService = app(UserService::class);
            $user = $userService->createUser([
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ]);

            if (!$user->save()) {
                return $this->fail([500, 'Generate failed']);
            }
            return $this->success(true);
        }

        if ($request->input('generate_count')) {
            return $this->multiGenerate($request);
        }
    }

    private function multiGenerate(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];

        for ($i = 0; $i < $request->input('generate_count'); $i++) {
            $email = Helper::randomChar(6) . '@' . $request->input('email_suffix');
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }



        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, 'Generate failed']);
        }

        // 闂佸憡甯囬崐鏍蓟閸ヮ剙鍙婃い鏍ㄧ閸庡﹪鎮楅悽闈涘付闁?CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Account', 'Password', 'Expire Time', 'UUID', 'Created At', 'Subscribe URL']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? 'Never' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 婵帗绋掗…鍫ヮ敇缂佹ɑ浜ら柡鍌涘缁€鈧?JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? 'Never' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => 'Batch generated successfully',
            'data' => $data,
        ]);
    }

    private function multiGenerateWithPrefix(Request $request)
    {
        $userService = app(UserService::class);
        $usersData = [];
        $emailPrefix = $request->input('email_prefix');
        $emailSuffix = $request->input('email_suffix');
        $generateCount = $request->input('generate_count');

        // Check if any of the emails with prefix already exist
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            if (User::where('email', $email)->exists()) {
                return $this->fail([400201, 'Email ' . $email . ' already exists in the system']);
            }
        }

        // Generate user data for batch creation
        for ($i = 1; $i <= $generateCount; $i++) {
            $email = $emailPrefix . '_' . $i . '@' . $emailSuffix;
            $usersData[] = [
                'email' => $email,
                'password' => $request->input('password') ?? $email,
                'plan_id' => $request->input('plan_id'),
                'expired_at' => $request->input('expired_at'),
            ];
        }

        try {
            DB::beginTransaction();
            $users = [];
            foreach ($usersData as $userData) {
                $user = $userService->createUser($userData);
                $user->save();
                $users[] = $user;
            }
            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return $this->fail([500, 'Generate failed']);
        }

        // 闂佸憡甯囬崐鏍蓟閸ヮ剙鍙婃い鏍ㄧ閸庡﹪鎮楅悽闈涘付闁?CSV
        if ($request->input('download_csv')) {
            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="users.csv"',
            ];
            $callback = function () use ($users, $request) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, ['Account', 'Password', 'Expire Time', 'UUID', 'Created At', 'Subscribe URL']);
                foreach ($users as $user) {
                    $user = $user->refresh();
                    $expireDate = $user['expired_at'] === NULL ? 'Never' : date('Y-m-d H:i:s', $user['expired_at']);
                    $createDate = date('Y-m-d H:i:s', $user['created_at']);
                    $password = $request->input('password') ?? $user['email'];
                    $subscribeUrl = Helper::getSubscribeUrl($user['token']);
                    fputcsv($handle, [$user['email'], $password, $expireDate, $user['uuid'], $createDate, $subscribeUrl]);
                }
                fclose($handle);
            };
            return response()->streamDownload($callback, 'users.csv', $headers);
        }

        // 婵帗绋掗…鍫ヮ敇缂佹ɑ浜ら柡鍌涘缁€鈧?JSON
        $data = collect($users)->map(function ($user) use ($request) {
            return [
                'email' => $user['email'],
                'password' => $request->input('password') ?? $user['email'],
                'expired_at' => $user['expired_at'] === NULL ? 'Never' : date('Y-m-d H:i:s', $user['expired_at']),
                'uuid' => $user['uuid'],
                'created_at' => date('Y-m-d H:i:s', $user['created_at']),
                'subscribe_url' => Helper::getSubscribeUrl($user['token']),
            ];
        });
        return response()->json([
            'code' => 0,
            'message' => 'Batch generated successfully',
            'data' => $data,
        ]);
    }

    public function sendMail(UserSendMail $request)
    {
        ini_set('memory_limit', '-1');
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids is required']);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()
            ->with('plan:id,name')
            ->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // filtered: apply filters/sort
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        $subject = $request->input('subject');
        $content = $request->input('content');
        $appName = admin_setting('app_name', 'XBoard');
        $appUrl = admin_setting('app_url');

        $chunkSize = 1000;

        $builder->chunk($chunkSize, function ($users) use ($subject, $content, $appName, $appUrl) {
            foreach ($users as $user) {
                $vars = [
                    'app.name' => $appName,
                    'app.url' => $appUrl,
                    'now' => now()->format('Y-m-d H:i:s'),
                    'user.id' => $user->id,
                    'user.email' => $user->email,
                    'user.uuid' => $user->uuid,
                    'user.plan_name' => $user->plan?->name ?? '',
                    'user.expired_at' => $user->expired_at ? date('Y-m-d H:i:s', $user->expired_at) : '',
                    'user.transfer_enable' => (int) ($user->transfer_enable ?? 0),
                    'user.transfer_used' => (int) (($user->u ?? 0) + ($user->d ?? 0)),
                    'user.transfer_left' => (int) (($user->transfer_enable ?? 0) - (($user->u ?? 0) + ($user->d ?? 0))),
                ];

                $templateValue = [
                    'name' => $appName,
                    'url' => $appUrl,
                    'content' => $content,
                    'vars' => $vars,
                    'content_mode' => 'text',
                ];

                dispatch(new SendEmailJob([
                    'email' => $user->email,
                    'subject' => $subject,
                    'template_name' => 'notify',
                    'template_value' => $templateValue
                ], 'send_email_mass'));
            }
        });

        return $this->success(true);
    }

    public function ban(Request $request)
    {
        $scopeInfo = $this->resolveScope($request);
        $scope = $scopeInfo['scope'];
        $userIds = $scopeInfo['user_ids'];

        if ($scope === 'selected') {
            if (empty($userIds)) {
                return $this->fail([422, 'user_ids is required']);
            }
        }

        $sortType = in_array($request->input('sort_type'), ['ASC', 'DESC']) ? $request->input('sort_type') : 'DESC';
        $sort = $request->input('sort') ? $request->input('sort') : 'created_at';

        $builder = User::query()->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            // filtered: keep current semantics
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        } // all: ignore filter/sort

        try {
            $builder->update([
                'banned' => 1
            ]);
            $this->cleanupOutlineKeysForScope($scope, $userIds, $request, $sort, $sortType);
        } catch (\Exception $e) {
            Log::error($e);
            return $this->fail([500, '婵犮垼娉涚€氼噣骞冩繝鍐ㄧ窞閺夊牜鍋夎']);
        }
        // Full refresh not implemented.
        return $this->success(true);
    }

    // Delete user and related data.
    public function destroy(Request $request)
    {
        $request->validate([
            'id' => 'required|exists:App\Models\User,id'
        ], [
            'id.required' => 'User ID is required',
            'id.exists' => 'User not found'
        ]);
        $user = User::find($request->input('id'));
        HookManager::call('admin.user.destroy.before', [
            'user' => $user,
            'request' => $request,
        ]);

        try {
            DB::beginTransaction();
            app(OutlineService::class)->deleteAccessKeysForUser($user, true);
            $user->orders()->delete();
            $user->codes()->delete();
            $user->stat()->delete();
            $user->tickets()->delete();
            $user->delete();
            DB::commit();

            HookManager::call('admin.user.destroy.after', [
                'user' => $user,
                'request' => $request,
            ]);

            return $this->success(true);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error($e);
            return $this->fail([500, 'Delete failed']);
        }
    }

    private function cleanupOutlineKeysForScope(string $scope, array $userIds, Request $request, string $sort, string $sortType): void
    {
        $builder = User::query()->orderBy('id', 'desc');

        if ($scope === 'filtered') {
            $builder->orderBy($sort, $sortType);
            $this->applyFiltersAndSorts($request, $builder);
        } elseif ($scope === 'selected') {
            $builder->whereIn('id', $userIds);
        }

        $outlineService = app(OutlineService::class);
        $builder->select(['id'])->chunkById(200, function ($users) use ($outlineService) {
            foreach ($users as $user) {
                $outlineService->deleteAccessKeysForUser($user->id);
            }
        });
    }
}
