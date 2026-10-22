<?php

$settings = [];
$add = function (string $key, string $label, mixed $default, array $rules, array $options = []) use (&$settings) {
    $settings[$key] = ['label' => $label, 'group' => explode('.', $key)[0], 'default' => $default, 'rules' => $rules, 'type' => is_bool($default) ? 'boolean' : (is_int($default) ? 'integer' : 'string'), 'public' => true, 'editable' => true, 'options' => $options];
};
foreach (['name_ar' => ['اسم النظام بالعربية', 'نظام المخالصات'], 'name_en' => ['اسم النظام بالإنجليزية', 'GHAMDAN SYS'], 'short_name' => ['الاسم المختصر', 'HR'], 'description' => ['وصف النظام', 'إدارة المخالصات والموظفين والسلف'], 'owner' => ['الشركة المالكة', ''], 'footer' => ['نص التذييل', ''], 'copyright' => ['حقوق النشر', 'جميع الحقوق محفوظة']] as $key => [$label, $default]) {
    $add('branding.'.$key, $label, $default, ['nullable', 'string', 'max:250']);
}
$add('branding.website', 'الموقع الرسمي', '', ['nullable', 'url:http,https', 'max:500']);
foreach (['branding.name_ar', 'branding.name_en', 'branding.short_name'] as $key) {
    $settings[$key]['rules'] = ['required', 'string', 'max:150'];
}
foreach (['logo' => 'الشعار الرئيسي', 'sidebar_logo' => 'شعار القائمة', 'favicon' => 'أيقونة المتصفح', 'print_logo' => 'شعار الطباعة'] as $key => $label) {
    $add('branding.'.$key, $label, null, ['nullable', 'uuid']);
}
foreach (['primary' => '#2563eb', 'secondary' => '#4f46e5', 'accent' => '#0891b2', 'background' => '#f1f5f9', 'card' => '#ffffff', 'text' => '#0f172a', 'success' => '#15803d', 'warning' => '#b45309', 'error' => '#b91c1c'] as $key => $default) {
    $add('appearance.'.$key, ['primary' => 'اللون الأساسي', 'secondary' => 'اللون الثانوي', 'accent' => 'لون التمييز', 'background' => 'الخلفية', 'card' => 'البطاقات', 'text' => 'النصوص', 'success' => 'النجاح', 'warning' => 'التحذير', 'error' => 'الخطأ'][$key], $default, ['required', 'regex:/^#[0-9a-fA-F]{6}$/']);
}
$add('appearance.font_ar', 'الخط العربي', 'Tahoma', ['required', 'in:Tahoma,Arial,Segoe UI'], ['Tahoma', 'Arial', 'Segoe UI']);
foreach (['dark_background' => ['خلفية الوضع الداكن', '#101827'], 'dark_card' => ['بطاقات الوضع الداكن', '#1d293b'], 'dark_text' => ['نصوص الوضع الداكن', '#edf2fa']] as $key => [$label,$default]) {
    $add('appearance.'.$key, $label, $default, ['required', 'regex:/^#[0-9a-fA-F]{6}$/']);
}
$add('appearance.font_en', 'الخط الإنجليزي', 'Segoe UI', ['required', 'in:Segoe UI,Arial,Georgia'], ['Segoe UI', 'Arial', 'Georgia']);
$add('appearance.radius', 'استدارة الحواف', 16, ['required', 'integer', 'between:0,32']);
$add('appearance.mode', 'وضع المظهر', 'light', ['required', 'in:light,dark'], ['light', 'dark']);
$add('appearance.table_density', 'كثافة الجداول', 'comfortable', ['required', 'in:compact,comfortable'], ['compact', 'comfortable']);
$add('appearance.logo_size', 'حجم الشعار', 44, ['required', 'integer', 'between:24,120']);
$add('appearance.sidebar', 'شكل القائمة', 'dark', ['required', 'in:dark,light'], ['dark', 'light']);
$add('display.locale', 'لغة العرض والتنسيق', 'ar-SA', ['required', 'in:ar-SA,en-GB'], ['ar-SA', 'en-GB']);
$add('display.date_format', 'تنسيق التاريخ', 'dd/MM/yyyy', ['required', 'in:dd/MM/yyyy,yyyy-MM-dd,MM/dd/yyyy'], ['dd/MM/yyyy', 'yyyy-MM-dd', 'MM/dd/yyyy']);
$add('display.numbering', 'شكل الأرقام', 'arab', ['required', 'in:arab,latn'], ['arab', 'latn']);
$add('display.currency', 'عملة العرض', 'SAR', ['required', 'in:SAR'], ['SAR']);
$add('display.timezone', 'المنطقة الزمنية للعرض', 'Asia/Riyadh', ['required', 'timezone']);
$add('display.rows', 'عدد الصفوف الافتراضي', 20, ['required', 'integer', 'between:5,100']);
$add('display.money_decimals', 'منازل عرض المال', 2, ['required', 'integer', 'between:0,4']);
$add('documents.settlement_prefix', 'بادئة مخالصات النهاية', 'SET', ['required', 'regex:/^[A-Z][A-Z0-9]{1,9}$/']);
$add('documents.leave_prefix', 'بادئة تصفيات الإجازة', 'LEV', ['required', 'regex:/^[A-Z][A-Z0-9]{1,9}$/']);
$add('documents.advance_prefix', 'بادئة أرقام السلف التلقائية', 'ADV', ['required', 'regex:/^[A-Z][A-Z0-9]{1,9}$/']);
$add('reports.organization', 'الجهة الظاهرة في التقارير', '', ['nullable', 'string', 'max:250']);
$add('reports.paper', 'ورق الطباعة', 'A4', ['required', 'in:A4,Letter'], ['A4', 'Letter']);
$add('reports.orientation', 'اتجاه طباعة التصفيات', 'portrait', ['required', 'in:portrait,landscape'], ['portrait', 'landscape']);
$add('reports.show_logo', 'إظهار الشعار في التقارير', true, ['required', 'boolean']);
$add('reports.footer', 'تذييل التقارير', '', ['nullable', 'string', 'max:250']);

return ['scope' => 'installation', 'cache_seconds' => 300, 'frontend_url' => env('FRONTEND_URL', 'http://127.0.0.1:5173'), 'settings' => $settings];
