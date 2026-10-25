import { useState } from 'react';
import { FieldError, inputClass, primaryButton, secondaryButton } from './menuParts';

/**
 * Add an option group to a meal, or edit one. A group's kind (dishes or add-ons) is chosen when it is created and never changes.
 * The group that prices the meal always takes exactly one pick, so its range is fixed.
 */
export default function MealGroupForm({ group = null, busy, error, onSubmit, onCancel }) {
    const editing = group !== null;
    const [name, setName] = useState(group?.name ?? '');
    const [kind, setKind] = useState(group?.kind ?? 'CHOICE');
    const [min, setMin] = useState(String(group?.min_select ?? 1));
    const [max, setMax] = useState(String(group?.max_select ?? 1));
    const [popup, setPopup] = useState(group?.popup_after_choice ?? false);
    const rangeLocked = group?.prices_the_meal === true;
    const idPrefix = editing ? `group_${group.id}` : 'new_group';

    function changeKind(next) {
        setKind(next);
        // Add-ons are optional by default; a group of dishes asks for one.
        setMin(next === 'ADDON' ? '0' : '1');
        setMax('1');
    }

    function submit(event) {
        event.preventDefault();
        const body = { name: name.trim(), min_select: Number(min), max_select: Number(max), popup_after_choice: popup };
        onSubmit(editing ? body : { ...body, kind });
    }

    return (
        <form onSubmit={submit} className="space-y-3 rounded-md border border-slate-700 bg-slate-950/60 p-3" noValidate>
            <div className="grid gap-3 sm:grid-cols-2">
                <div>
                    <label htmlFor={`${idPrefix}_name`} className="mb-1 block text-xs text-slate-400">
                        Step name
                    </label>
                    <input id={`${idPrefix}_name`} type="text" autoFocus value={name} placeholder="e.g. Side, Extra rice" onChange={(event) => setName(event.target.value)} className={inputClass(false)} />
                </div>
                {!editing && (
                    <div>
                        <label htmlFor={`${idPrefix}_kind`} className="mb-1 block text-xs text-slate-400">
                            What does this step offer?
                        </label>
                        <select id={`${idPrefix}_kind`} value={kind} onChange={(event) => changeKind(event.target.value)} className={inputClass(false)}>
                            <option value="CHOICE">Dishes to choose from</option>
                            <option value="ADDON">Optional add-ons (extras with their own price)</option>
                        </select>
                    </div>
                )}
                <div>
                    <label htmlFor={`${idPrefix}_min`} className="mb-1 block text-xs text-slate-400">
                        Fewest picks
                    </label>
                    <input id={`${idPrefix}_min`} type="number" min="0" max="99" disabled={rangeLocked} value={min} onChange={(event) => setMin(event.target.value)} className={inputClass(false)} />
                </div>
                <div>
                    <label htmlFor={`${idPrefix}_max`} className="mb-1 block text-xs text-slate-400">
                        Most picks
                    </label>
                    <input id={`${idPrefix}_max`} type="number" min="1" max="99" disabled={rangeLocked} value={max} onChange={(event) => setMax(event.target.value)} className={inputClass(false)} />
                </div>
            </div>
            {rangeLocked && <p className="text-xs text-slate-500">This step sets the price of the meal, so the cashier always picks exactly one.</p>}
            <label className="flex min-h-11 items-center gap-2 text-sm text-slate-300 lg:min-h-0">
                <input type="checkbox" checked={popup} onChange={(event) => setPopup(event.target.checked)} className="h-4 w-4 accent-emerald-500" />
                Open this step automatically right after the dish is chosen (the extra-rice pop-up)
            </label>
            <FieldError message={error} />
            <div className="flex gap-2">
                <button type="submit" disabled={busy || name.trim() === ''} className={primaryButton}>
                    {busy ? 'Saving…' : editing ? 'Save step' : 'Add step'}
                </button>
                <button type="button" onClick={onCancel} className={secondaryButton}>
                    Cancel
                </button>
            </div>
        </form>
    );
}
