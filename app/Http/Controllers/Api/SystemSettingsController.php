<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\SystemSetting;
use App\Services\SystemSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class SystemSettingsController extends Controller
{
    public function __construct(private SystemSettingsService $settings) {}

    public function publicSettings()
    {
        return response()->json($this->settings->publicSnapshot())->header('Cache-Control', 'no-store');
    }

    public function index()
    {
        return response()->json(array_merge($this->settings->snapshot(), ['registry' => collect(config('system.settings'))->map(fn ($entry) => array_diff_key($entry, ['rules' => true])), 'defaults' => $this->settings->defaults()]))->header('Cache-Control', 'no-store');
    }

    private function input(Request $request, bool $reset = false): array
    {
        return $request->validate(['settings' => [$reset ? 'sometimes' : 'required', 'array', 'max:100'], 'revision' => 'required|integer|min:0', 'reason' => 'required|string|min:5|max:1000', ...($reset ? ['confirmation' => 'required|in:RESET'] : [])]);
    }

    public function update(Request $request)
    {
        $data = $this->input($request);

        return response()->json($this->settings->update($data['settings'], $request->user(), $data['revision'], $data['reason']));
    }

    public function reset(Request $request)
    {
        $data = $this->input($request, true);

        return response()->json($this->settings->update($this->settings->defaults(), $request->user(), $data['revision'], $data['reason'], 'system_settings_reset'));
    }

    public function export()
    {
        return response()->json(['schema' => 1, 'exported_at' => now()->toIso8601String(), 'settings' => $this->settings->snapshot()['settings']])->header('Content-Disposition', 'attachment; filename="safe-system-settings.json"')->header('Cache-Control', 'no-store');
    }

    public function restore(Request $request)
    {
        $request->validate(['schema' => 'required|in:1', 'settings' => 'required|array', 'revision' => 'required|integer|min:0', 'reason' => 'required|string|min:5|max:1000', 'confirmation' => 'required|in:RESTORE']);

        return response()->json($this->settings->update($request->input('settings'), $request->user(), $request->integer('revision'), $request->input('reason'), 'system_settings_restored'));
    }

    public function audit()
    {
        return response()->json(AuditLog::with('user:id,name')->where('entity_type', SystemSetting::class)->where('new_values_json->scope', config('system.scope'))->latest('id')->paginate(15))->header('Cache-Control', 'no-store');
    }

    public function upload(Request $request)
    {
        $request->validate(['file' => 'required|image|mimes:png,jpg,jpeg,webp|max:2048|dimensions:min_width=8,min_height=8,max_width=2048,max_height=2048']);
        $image = imagecreatefromstring($request->file('file')->getContent());
        abort_unless($image, 422, 'الصورة غير صالحة.');
        ob_start();
        imagepng($image);
        $contents = ob_get_clean();
        imagedestroy($image);
        $id = (string) Str::uuid();
        $path = 'branding/'.$id.'.png';
        abort_unless(Storage::disk('local')->put($path, $contents), 500, 'تعذر حفظ الشعار. بقي الشعار الحالي دون تغيير.');
        try {
            DB::table('system_branding_assets')->insert(['id' => $id, 'scope' => config('system.scope'), 'path' => $path, 'sha256' => hash('sha256', $contents), 'created_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now()]);
            AuditLog::create(['user_id' => $request->user()->id, 'action' => 'branding_asset_uploaded', 'entity_type' => SystemSetting::class, 'entity_id' => 0, 'new_values_json' => ['scope' => config('system.scope'), 'asset_id' => $id], 'ip_address' => $request->ip()]);
        } catch (\Throwable $error) {
            Storage::disk('local')->delete($path);
            throw $error;
        }

        return response()->json(['id' => $id, 'url' => '/api/system/branding/'.$id], 201);
    }

    public function asset(string $id)
    {
        abort_unless(Str::isUuid($id), 404);
        $path = $this->settings->assetPath($id);
        abort_unless($path, 404);

        return response()->file($path, ['Content-Type' => 'image/png', 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'public, max-age=86400']);
    }

    public function health()
    {
        $database = false;
        $storage = false;
        try {
            DB::select('SELECT 1');
            $database = true;
        } catch (\Throwable) {
        }
        try {
            $path = 'health/'.Str::uuid();
            $storage = Storage::disk('local')->put($path, 'ok') && Storage::disk('local')->get($path) === 'ok';
            Storage::disk('local')->delete($path);
        } catch (\Throwable) {
        }

        return response()->json(['database' => $database ? 'connected' : 'not_connected', 'storage' => $storage ? 'connected' : 'not_connected', 'cache' => $this->cacheHealth(), 'mail' => config('mail.default') === 'log' ? 'log_only' : 'configured_not_tested'])->header('Cache-Control', 'no-store');
    }

    private function cacheHealth(): string
    {
        try {
            $key = 'settings-health-'.Str::uuid();
            Cache::put($key, true, 10);
            $ok = Cache::get($key) === true;
            Cache::forget($key);

            return $ok ? 'connected' : 'not_connected';
        } catch (\Throwable) {
            return 'not_connected';
        }
    }
}
