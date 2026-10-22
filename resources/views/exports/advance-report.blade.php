<!doctype html><html lang="ar" dir="rtl"><head><meta charset="utf-8"><style>body{font-family:dejavusans;font-size:10pt}table{width:100%;border-collapse:collapse}td,th{border:1px solid #ccd3df;padding:6px;text-align:right}th{background:#edf3ff}h1{color:#173e75}</style></head><body>
@php($brand = app(\App\Services\SystemSettingsService::class)->reportBranding())
@include('exports.branding-style')
@if($brand['logo_path'])<img src="{{ $brand['logo_path'] }}" style="height:16mm" alt="logo">@endif
<div>{{ $brand['name'] }} — {{ $brand['organization'] }}</div>
<h1>كشف السلف والتسويات المالية</h1><p>{{ ['outstanding'=>'السلف القائمة','settled'=>'السلف المسددة','terminated_debt'=>'مديونيات عند انتهاء الخدمة','settlement_deductions'=>'خصومات المخالصات المرحلة','no_deduction'=>'مخالصات دون خصم السلف','pending'=>'بانتظار المراجعة والاعتماد','statement'=>'كشف حساب الموظف'][$kind] ?? $kind }}</p>
<table><thead><tr>@foreach($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead><tbody>@foreach($rows as $row)<tr>@foreach($row as $value)<td>{{ $value }}</td>@endforeach</tr>@endforeach</tbody></table>
</body></html>
