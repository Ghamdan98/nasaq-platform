<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SystemSettingsService
{
    public function defaults(): array
    {
        return array_map(fn ($definition) => $definition['default'], config('system.settings'));
    }

    private function cacheKey(): string
    {
        return 'system-settings:'.hash('sha256', base_path().'|'.config('database.default').'|'.config('database.connections.'.config('database.default').'.database').'|'.config('system.scope'));
    }

    public function snapshot(): array
    {
        if (! Schema::hasTable('system_settings')) {
            return ['revision' => 0, 'settings' => $this->defaults()];
        }

        return Cache::remember($this->cacheKey(), config('system.cache_seconds'), function () {
            $settings = $this->defaults();
            $revision = 0;
            foreach (SystemSetting::where('scope', config('system.scope'))->get() as $row) {
                if ($row->key === '_revision') {
                    $revision = (int) $row->value;
                } elseif (array_key_exists($row->key, $settings)) {
                    $settings[$row->key] = $row->value;
                }
            }

            return compact('revision', 'settings');
        });
    }

    public function get(string $key): mixed
    {
        return $this->snapshot()['settings'][$key] ?? config('system.settings.'.$key.'.default');
    }

    public function publicSnapshot(): array
    {
        $snapshot = $this->snapshot();
        $snapshot['settings'] = array_filter($snapshot['settings'], fn ($key) => config('system.settings')[$key]['public'] ?? false, ARRAY_FILTER_USE_KEY);

        return $snapshot;
    }

    public function validate(array $changes): array
    {
        $registry = config('system.settings');
        $errors = [];
        foreach ($changes as $key => $value) {
            if (! isset($registry[$key]) || ! $registry[$key]['editable']) {
                $errors['settings.'.$key] = ['إعداد غير معروف أو محمي.'];

                continue;
            }
            $validator = Validator::make(['value' => $value], ['value' => $registry[$key]['rules']]);
            if ($validator->fails()) {
                $errors['settings.'.$key] = [$registry[$key]['label'].': '.$validator->errors()->first()];

                continue;
            }
            if (in_array($key, ['branding.logo', 'branding.sidebar_logo', 'branding.favicon', 'branding.print_logo']) && $value && ! DB::table('system_branding_assets')->where('id', $value)->where('scope', config('system.scope'))->exists()) {
                $errors['settings.'.$key] = ['الشعار غير موجود أو لا يتبع هذه النسخة.'];
            }
            if ($registry[$key]['type'] === 'integer') {
                $changes[$key] = (int) $value;
            }
            if ($registry[$key]['type'] === 'boolean') {
                $changes[$key] = (bool) $value;
            }
        }
        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $changes;
    }

    public function update(array $changes, User $user, int $expectedRevision, string $reason, string $action = 'system_settings_updated'): array
    {
        Gate::forUser($user)->authorize('manage', SystemSetting::class);
        $changes = $this->validate($changes);
        DB::transaction(function () use ($changes, $user, $expectedRevision, $reason, $action) {
            SystemSetting::firstOrCreate(['scope' => config('system.scope'), 'key' => '_revision'], ['group' => 'system', 'value' => 0, 'value_type' => 'integer', 'is_public' => false, 'is_editable' => false]);
            $revision = SystemSetting::where('scope', config('system.scope'))->where('key', '_revision')->lockForUpdate()->firstOrFail();
            if ((int) $revision->value !== $expectedRevision) {
                throw ValidationException::withMessages(['revision' => 'تغيرت الإعدادات من جلسة أخرى. أعد تحميلها قبل الحفظ.']);
            }
            $before = [];
            foreach ($changes as $key => $value) {
                $definition = config('system.settings')[$key];
                $row = SystemSetting::where('scope', config('system.scope'))->where('key', $key)->first();
                $before[$key] = $row?->value ?? $definition['default'];
                SystemSetting::updateOrCreate(['scope' => config('system.scope'), 'key' => $key], ['group' => $definition['group'], 'value' => $value, 'value_type' => $definition['type'], 'is_public' => $definition['public'], 'is_editable' => $definition['editable'], 'updated_by' => $user->id]);
            }
            $revision->update(['value' => (int) $revision->value + 1, 'updated_by' => $user->id]);
            AuditLog::create(['user_id' => $user->id, 'action' => $action, 'entity_type' => SystemSetting::class, 'entity_id' => $revision->id, 'old_values_json' => $before, 'new_values_json' => ['scope' => config('system.scope'), 'settings' => $changes, 'reason' => $reason], 'ip_address' => request()->ip()]);
        });
        Cache::forget($this->cacheKey());

        return $this->snapshot();
    }

    public function assetPath(?string $id): ?string
    {
        if (! $id || ! Schema::hasTable('system_branding_assets')) {
            return null;
        }
        $path = DB::table('system_branding_assets')->where('id', $id)->where('scope', config('system.scope'))->value('path');

        return $path && str_starts_with($path, 'branding/') && Storage::disk('local')->exists($path) ? Storage::disk('local')->path($path) : null;
    }

    public function reportBranding(): array
    {
        $settings = $this->publicSnapshot()['settings'];

        return ['settings' => $settings, 'name' => $settings['branding.name_ar'], 'organization' => $settings['reports.organization'] ?: $settings['branding.owner'], 'logo_path' => $settings['reports.show_logo'] ? $this->assetPath($settings['branding.print_logo'] ?: $settings['branding.logo']) : null];
    }

    public function pageSize(): int
    {
        request()->validate(['per_page' => 'nullable|integer|min:1|max:100']);

        return request()->integer('per_page', (int) $this->get('display.rows'));
    }

    public function formatDate(mixed $value): string
    {
        if (! $value) {
            return '—';
        }
        $format = ['dd/MM/yyyy' => 'd/m/Y', 'yyyy-MM-dd' => 'Y-m-d', 'MM/dd/yyyy' => 'm/d/Y'][$this->get('display.date_format')];
        $text = Carbon::parse($value)->format($format);

        return $this->get('display.numbering') === 'arab' ? strtr($text, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']) : $text;
    }

    public function formatMoney(mixed $amount): string
    {
        $text = number_format((float) $amount, (int) $this->get('display.money_decimals'));
        if ($this->get('display.numbering') === 'arab') {
            $text = strtr($text, ['0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤', '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩']);
        }

        return $text.' '.($this->get('display.locale') === 'ar-SA' ? 'ر.س' : 'SAR');
    }
}
