const options = [
  { value: 'final_settlement', label: 'مخالصة نهاية خدمة', description: 'حساب الاستحقاقات النهائية للموظف' },
  { value: 'leave_settlement', label: 'تصفية إجازة', description: 'تصفية الرصيد المعتمد للإجازة' },
]

export default function TransactionTypeSelector({ value, onChange, disabled = false }) {
  return (
    <div className="rounded-[22px] border border-slate-200 bg-slate-50 p-2 shadow-sm">
      <div className="grid grid-cols-2 gap-2">
        {options.map((option) => {
          const active = option.value === value

          return (
            <button
              key={option.value}
              type="button"
              disabled={disabled}
              aria-pressed={active}
              onClick={() => onChange(option.value)}
              className={`rounded-2xl px-4 py-3 text-right transition ${
                active
                  ? 'bg-white text-blue-700 shadow-sm ring-1 ring-blue-200'
                  : 'bg-transparent text-slate-600 hover:bg-white/60'
              }`}
            >
              <div className="text-sm font-bold">{option.label}</div>
              <div className={`mt-1 text-[11px] ${active ? 'text-slate-500' : 'text-slate-400'}`}>
                {option.description}
              </div>
            </button>
          )
        })}
      </div>
    </div>
  )
}
