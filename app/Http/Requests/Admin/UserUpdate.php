<?php

namespace App\Http\Requests\Admin;

use App\Services\Plugin\HookManager;
use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            'id' => 'required|integer',
            'email' => 'email:strict',
            'password' => 'nullable|min:8',
            'transfer_enable' => 'numeric',
            'expired_at' => 'nullable|integer',
            'banned' => 'bool',
            'plan_id' => 'nullable|integer',
            'commission_rate' => 'nullable|integer|min:0|max:100',
            'discount' => 'nullable|integer|min:0|max:100',
            'is_admin' => 'boolean',
            'is_staff' => 'boolean',
            'u' => 'integer',
            'd' => 'integer',
            'balance' => 'numeric',
            'commission_type' => 'integer',
            'commission_balance' => 'numeric',
            'remarks' => 'nullable',
            'speed_limit' => 'nullable|integer',
            'device_limit' => 'nullable|integer'
        ];

        return HookManager::filter('admin.user.update.rules', $rules, $this);
    }

    public function messages()
    {
        $messages = [
            'email.required' => '邮箱不能为空',
            'email.email' => '邮箱格式不正确',
            'transfer_enable.numeric' => '流量格式不正确',
            'expired_at.integer' => '到期时间格式不正确',
            'banned.in' => '封禁状态格式不正确',
            'is_admin.required' => '管理员状态不能为空',
            'is_admin.in' => '管理员状态格式不正确',
            'is_staff.required' => '员工状态不能为空',
            'is_staff.in' => '员工状态格式不正确',
            'plan_id.integer' => '订阅计划格式不正确',
            'commission_rate.integer' => '返佣比例格式不正确',
            'commission_rate.nullable' => '返佣比例格式不正确',
            'commission_rate.min' => '返佣比例最小为0',
            'commission_rate.max' => '返佣比例最大为100',
            'discount.integer' => '专属折扣比例格式不正确',
            'discount.nullable' => '专属折扣比例格式不正确',
            'discount.min' => '专属折扣比例最小为0',
            'discount.max' => '专属折扣比例最大为100',
            'u.integer' => '上行流量格式不正确',
            'd.integer' => '下行流量格式不正确',
            'balance.numeric' => '余额格式不正确',
            'commission_balance.numeric' => '佣金格式不正确',
            'password.min' => '密码长度最少 8 位',
            'speed_limit.integer' => '限速格式不正确',
            'device_limit.integer' => '设备数量格式不正确'
        ];

        return HookManager::filter('admin.user.update.messages', $messages, $this);
    }
}
