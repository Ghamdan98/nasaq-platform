@php($brand = $brand ?? ($data['branding'] ?? app(\App\Services\SystemSettingsService::class)->reportBranding()))
<style>
h1,h2,.net { color: {{ $brand['settings']['appearance.primary'] }}; }
.header { border-color: {{ $brand['settings']['appearance.primary'] }}; }
th { background: #f1f5f9; color: {{ $brand['settings']['appearance.primary'] }}; }
</style>
