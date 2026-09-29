<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Module;
use App\Models\Role;
use App\Models\SystemSetting;
use Illuminate\View\View;

/** System settings + the role -> module access matrix (Module 1.1) — both real tables now. */
class SettingsController extends Controller
{
    public function index(): View
    {
        $settings = SystemSetting::query()
            ->orderBy('setting_key')
            ->get()
            ->map(fn ($s) => [
                'key' => $s->setting_key,
                'value' => $s->setting_value,
                'description' => $s->description(),
            ])
            ->all();

        $roles = Role::with(['rolePermissions.module'])->orderBy('role_id')->get()->map(function ($role) {
            if ($role->role_name === 'Visitor') {
                return ['role_name' => $role->role_name, 'access' => 'Own profile, own schedules and QR (mobile app only)'];
            }

            $full = $role->rolePermissions->filter(fn ($p) => $p->can_create || $p->can_edit)->pluck('module.module_name')->filter()->all();
            $viewOnly = $role->rolePermissions->filter(fn ($p) => $p->can_view && ! $p->can_create && ! $p->can_edit)->pluck('module.module_name')->filter()->all();

            $parts = [];
            if ($full) {
                $parts[] = implode(', ', $full) . ' (view/create/edit)';
            }
            if ($viewOnly) {
                $parts[] = implode(', ', $viewOnly) . ' (view only)';
            }

            return [
                'role_name' => $role->role_name,
                'access' => $parts ? implode('; ', $parts) : 'No module access configured yet',
            ];
        })->all();

        $summary = [
            ['label' => 'Configured Settings', 'value' => (string) count($settings), 'icon' => 'cog', 'accent' => 'info'],
            ['label' => 'Roles Defined', 'value' => (string) count($roles), 'icon' => 'users', 'accent' => 'success'],
        ];

        return view('admin.settings.index', compact('settings', 'roles', 'summary'));
    }
}
