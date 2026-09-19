import { useState } from 'react';
import SlideOver from '../SlideOver';
import { TAX_CLASSES, failureMessage, fieldErrors, normalizeMoney, request } from './catalogApi';

const UNIT_SUGGESTIONS = ['pc', 'kg', 'g', 'L', 'pack', 'box'];

function initialValues(product) {
    return {
        sku: product?.sku ?? '',
        barcode: product?.barcode ?? '',
        name: product?.name ?? '',
        description: product?.description ?? '',
        category_id: product?.category_id ?? '',
        brand_id: product?.brand_id ?? '',
        unit_of_measure: product?.unit_of_measure ?? '',
        cost: product?.cost ?? '',
        selling_price: product?.selling_price ?? '',
        tax_class: product?.tax_class ?? 'VATABLE',
        track_inventory: product?.track_inventory ?? true,
        reorder_level: String(product?.reorder_level ?? 0),
    };
}

function validate(values) {
    const errors = {};
    if (values.sku.trim() === '') {
        errors.sku = 'Enter a SKU.';
    }
    if (values.name.trim() === '') {
        errors.name = 'Enter a name.';
    }
    if (values.unit_of_measure.trim() === '') {
        errors.unit_of_measure = 'Enter a unit of measure.';
    }
    if (normalizeMoney(values.selling_price) === null) {
        errors.selling_price = 'Enter a price with two decimals, e.g. 55.00.';
    }
    if (values.cost.trim() !== '' && normalizeMoney(values.cost) === null) {
        errors.cost = 'Enter a cost with two decimals, e.g. 40.00.';
    }
    if (!/^\d+$/.test(values.reorder_level.trim())) {
        errors.reorder_level = 'Enter a whole number, 0 or more.';
    }
    return errors;
}

function Field({ id, label, required, hint, error, children, action, below }) {
    return (
        <div>
            <div className="mb-1 flex items-center justify-between">
                <label htmlFor={id} className="text-xs text-slate-400">
                    {label} {required && <span className="text-emerald-400">*</span>}
                </label>
                {action}
            </div>
            {children}
            {below}
            {error ? (
                <p id={`${id}_error`} role="alert" className="mt-1 font-mono text-[11px] text-rose-400">
                    &#9888; {error}
                </p>
            ) : (
                hint && <p className="mt-1 text-[11px] text-slate-500">{hint}</p>
            )}
        </div>
    );
}

const inputClass = (error, mono = false) =>
    `min-h-11 w-full rounded-md border bg-slate-950 px-3 py-1.5 text-sm text-slate-100 placeholder:text-slate-600 focus:outline-none focus:ring-1 lg:min-h-0 ${mono ? 'font-mono' : ''} ${
        error ? 'border-rose-500 focus:ring-rose-500' : 'border-slate-700 focus:border-emerald-500 focus:ring-emerald-500'
    }`;

/** The "+ New category" / "+ New brand" link in a field's label row. */
function InlineTrigger({ noun, open, onToggle }) {
    return (
        <button type="button" onClick={onToggle} aria-expanded={open} className="text-[11px] text-emerald-400 hover:underline">
            {open ? 'Cancel' : `+ New ${noun}`}
        </button>
    );
}

/** Creates the lookup row in place (categoryCreate / brandCreate) and hands it back to be selected. */
function InlineForm({ noun, path, onCreated, onCancel, onUnauthorized }) {
    const [name, setName] = useState('');
    const [error, setError] = useState(null);
    const [saving, setSaving] = useState(false);

    async function add() {
        if (name.trim() === '') {
            setError('Enter a name.');
            return;
        }
        setSaving(true);
        const result = await request(path, { method: 'POST', body: { name: name.trim() } });
        setSaving(false);
        if (!result.ok) {
            if (result.status === 401) {
                onUnauthorized();
                return;
            }
            setError(fieldErrors(result).name ?? failureMessage(result, `The ${noun} could not be added.`));
            return;
        }
        onCreated(result.body);
    }

    return (
        <div className="mt-2 rounded-md border border-slate-700 bg-slate-950 p-2">
            <div className="flex gap-2">
                <input
                    type="text"
                    aria-label={`New ${noun} name`}
                    autoFocus
                    value={name}
                    onChange={(event) => {
                        setName(event.target.value);
                        setError(null);
                    }}
                    onKeyDown={(event) => {
                        if (event.key === 'Enter') {
                            event.preventDefault();
                            add();
                        }
                    }}
                    className="min-h-11 min-w-0 flex-1 rounded border border-slate-700 bg-slate-900 px-2 py-1 text-sm text-slate-100 focus:border-emerald-500 focus:outline-none lg:min-h-0"
                />
                <button type="button" disabled={saving} onClick={add} className="min-h-11 rounded bg-emerald-500 px-3 text-xs font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0">
                    {saving ? 'Adding…' : 'Add'}
                </button>
                <button type="button" onClick={onCancel} className="min-h-11 rounded px-2 text-xs text-slate-400 hover:text-slate-200 lg:min-h-0">
                    Cancel
                </button>
            </div>
            {error && <p role="alert" className="mt-1 font-mono text-[11px] text-rose-400">&#9888; {error}</p>}
        </div>
    );
}

/**
 * Slide-over for productCreate / productUpdate. The client checks only shape (required fields, money
 * with two decimals); uniqueness of SKU/barcode and store ownership of category/brand are the
 * server's call and come back as VALIDATION_FAILED field errors, shown under their fields.
 */
export default function ProductFormPanel({
    product,
    categories,
    brands,
    onCategoryCreated,
    onBrandCreated,
    onSaved,
    onRequestDeactivate,
    onActivate,
    onClose,
    onUnauthorized,
}) {
    const editing = Boolean(product);
    const [values, setValues] = useState(() => initialValues(product));
    const [errors, setErrors] = useState({});
    const [formError, setFormError] = useState(null);
    const [saving, setSaving] = useState(false);
    const [inline, setInline] = useState(null); // 'category' | 'brand' | null

    function set(field, value) {
        setValues((prior) => ({ ...prior, [field]: value }));
        setErrors((prior) => ({ ...prior, [field]: undefined }));
    }

    function tidyMoney(field) {
        const normalized = normalizeMoney(values[field]);
        if (normalized !== null) {
            setValues((prior) => ({ ...prior, [field]: normalized }));
        }
    }

    async function submit(event) {
        event.preventDefault();
        const clientErrors = validate(values);
        if (Object.keys(clientErrors).length > 0) {
            setErrors(clientErrors);
            return;
        }
        setSaving(true);
        setFormError(null);
        const body = {
            sku: values.sku.trim(),
            barcode: values.barcode.trim() || null,
            name: values.name.trim(),
            description: values.description.trim() || null,
            category_id: values.category_id || null,
            brand_id: values.brand_id || null,
            unit_of_measure: values.unit_of_measure.trim(),
            cost: values.cost.trim() === '' ? null : normalizeMoney(values.cost),
            selling_price: normalizeMoney(values.selling_price),
            tax_class: values.tax_class,
            track_inventory: values.track_inventory,
            reorder_level: Number(values.reorder_level),
        };
        const result = await request(editing ? `/api/v1/products/${product.id}` : '/api/v1/products', {
            method: editing ? 'PATCH' : 'POST',
            body,
        });
        setSaving(false);
        if (result.ok) {
            onSaved(result.body, editing);
            return;
        }
        if (result.status === 401) {
            onUnauthorized();
            return;
        }
        const serverErrors = fieldErrors(result);
        if (Object.keys(serverErrors).length > 0) {
            setErrors(serverErrors);
            return;
        }
        setFormError(failureMessage(result, 'The product could not be saved.'));
    }

    const footer = (
        <div className="flex items-center justify-between border-t border-slate-800 bg-slate-950/80 px-5 py-3">
            <button type="button" onClick={onClose} className="min-h-11 rounded px-3 text-sm text-slate-300 hover:text-white lg:min-h-0">
                Cancel
            </button>
            <button
                type="submit"
                form="product_form"
                disabled={saving}
                className="min-h-11 rounded-md bg-emerald-500 px-5 py-2 text-sm font-semibold text-slate-950 hover:bg-emerald-400 disabled:opacity-50 lg:min-h-0"
            >
                {saving ? 'Saving…' : editing ? 'Save changes' : 'Create product'}
            </button>
        </div>
    );

    return (
        <SlideOver
            titleId="product_panel_title"
            title={editing ? 'Edit product' : 'New product'}
            badge={editing ? product.sku : null}
            onClose={onClose}
            footer={footer}
        >
            <>
                <form id="product_form" onSubmit={submit} noValidate className="flex-1 space-y-4 overflow-y-auto px-5 py-4">
                    {formError && (
                        <p role="alert" className="rounded-md border border-rose-800 bg-rose-950/40 px-3 py-2 text-sm text-slate-100">
                            {formError}
                        </p>
                    )}

                    <Field id="product_sku" label="SKU" required error={errors.sku}>
                        <input
                            id="product_sku"
                            type="text"
                            value={values.sku}
                            aria-invalid={Boolean(errors.sku)}
                            aria-describedby={errors.sku ? 'product_sku_error' : undefined}
                            onChange={(event) => set('sku', event.target.value)}
                            className={inputClass(errors.sku, true)}
                        />
                    </Field>

                    <Field id="product_barcode" label="Barcode" hint="Optional. Must be unique in your store." error={errors.barcode}>
                        <input
                            id="product_barcode"
                            type="text"
                            inputMode="numeric"
                            value={values.barcode}
                            aria-invalid={Boolean(errors.barcode)}
                            onChange={(event) => set('barcode', event.target.value)}
                            className={inputClass(errors.barcode, true)}
                        />
                    </Field>

                    <Field id="product_name" label="Name" required error={errors.name}>
                        <input
                            id="product_name"
                            type="text"
                            value={values.name}
                            aria-invalid={Boolean(errors.name)}
                            onChange={(event) => set('name', event.target.value)}
                            className={inputClass(errors.name)}
                        />
                    </Field>

                    <Field id="product_description" label="Description" hint="Optional." error={errors.description}>
                        <textarea
                            id="product_description"
                            rows={3}
                            value={values.description}
                            onChange={(event) => set('description', event.target.value)}
                            className={inputClass(errors.description)}
                        />
                    </Field>

                    <div>
                        <Field
                            id="product_category"
                            label="Category"
                            error={errors.category_id}
                            action={<InlineTrigger noun="category" open={inline === 'category'} onToggle={() => setInline(inline === 'category' ? null : 'category')} />}
                            below={
                                inline === 'category' && (
                                    <InlineForm
                                        noun="category"
                                        path="/api/v1/categories"
                                        onUnauthorized={onUnauthorized}
                                        onCancel={() => setInline(null)}
                                        onCreated={(created) => {
                                            onCategoryCreated(created);
                                            set('category_id', created.id);
                                            setInline(null);
                                        }}
                                    />
                                )
                            }
                        >
                            <select id="product_category" value={values.category_id} onChange={(event) => set('category_id', event.target.value)} className={inputClass(errors.category_id)}>
                                <option value="">None</option>
                                {categories.map((category) => (
                                    <option key={category.id} value={category.id}>
                                        {category.name}
                                    </option>
                                ))}
                            </select>
                        </Field>
                    </div>

                    <Field
                        id="product_brand"
                        label="Brand"
                        error={errors.brand_id}
                        action={<InlineTrigger noun="brand" open={inline === 'brand'} onToggle={() => setInline(inline === 'brand' ? null : 'brand')} />}
                        below={
                            inline === 'brand' && (
                                <InlineForm
                                    noun="brand"
                                    path="/api/v1/brands"
                                    onUnauthorized={onUnauthorized}
                                    onCancel={() => setInline(null)}
                                    onCreated={(created) => {
                                        onBrandCreated(created);
                                        set('brand_id', created.id);
                                        setInline(null);
                                    }}
                                />
                            )
                        }
                    >
                        <select id="product_brand" value={values.brand_id} onChange={(event) => set('brand_id', event.target.value)} className={inputClass(errors.brand_id)}>
                            <option value="">None</option>
                            {brands.map((brand) => (
                                <option key={brand.id} value={brand.id}>
                                    {brand.name}
                                </option>
                            ))}
                        </select>
                    </Field>

                    <Field id="product_unit" label="Unit of measure" required error={errors.unit_of_measure}>
                        <input
                            id="product_unit"
                            type="text"
                            value={values.unit_of_measure}
                            aria-invalid={Boolean(errors.unit_of_measure)}
                            onChange={(event) => set('unit_of_measure', event.target.value)}
                            className={inputClass(errors.unit_of_measure)}
                        />
                        <div className="mt-1.5 flex flex-wrap gap-1.5" role="group" aria-label="Unit suggestions">
                            {UNIT_SUGGESTIONS.map((unit) => (
                                <button
                                    key={unit}
                                    type="button"
                                    onClick={() => set('unit_of_measure', unit)}
                                    className={`rounded border px-2 py-0.5 font-mono text-[11px] ${
                                        values.unit_of_measure === unit ? 'border-emerald-500 text-emerald-400' : 'border-slate-700 text-slate-400 hover:bg-slate-800'
                                    }`}
                                >
                                    {unit}
                                </button>
                            ))}
                        </div>
                    </Field>

                    <div className="grid grid-cols-2 gap-3">
                        <Field id="product_cost" label="Cost" hint="Optional." error={errors.cost}>
                            <div className="relative">
                                <span aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-sm text-slate-500">₱</span>
                                <input
                                    id="product_cost"
                                    type="text"
                                    inputMode="decimal"
                                    value={values.cost}
                                    aria-invalid={Boolean(errors.cost)}
                                    onChange={(event) => set('cost', event.target.value)}
                                    onBlur={() => tidyMoney('cost')}
                                    className={`${inputClass(errors.cost, true)} pl-7 text-right`}
                                />
                            </div>
                        </Field>
                        <Field id="product_price" label="Selling price" required hint="Two decimals, e.g. 55.00" error={errors.selling_price}>
                            <div className="relative">
                                <span aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 font-mono text-sm text-slate-500">₱</span>
                                <input
                                    id="product_price"
                                    type="text"
                                    inputMode="decimal"
                                    value={values.selling_price}
                                    aria-invalid={Boolean(errors.selling_price)}
                                    onChange={(event) => set('selling_price', event.target.value)}
                                    onBlur={() => tidyMoney('selling_price')}
                                    className={`${inputClass(errors.selling_price, true)} pl-7 text-right`}
                                />
                            </div>
                        </Field>
                    </div>

                    <p className="rounded-md border border-slate-800 bg-slate-950/60 px-3 py-2 text-xs text-slate-400">
                        Edits change the current catalog only. Past sales keep the name and price they were sold at.
                    </p>

                    <fieldset>
                        <legend className="mb-1 text-xs text-slate-400">
                            Tax class <span className="text-emerald-400">*</span>
                        </legend>
                        {errors.tax_class && <p role="alert" className="mb-1 font-mono text-[11px] text-rose-400">&#9888; {errors.tax_class}</p>}
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {TAX_CLASSES.map((taxClass) => (
                                <label
                                    key={taxClass.id}
                                    className={`flex cursor-pointer items-start gap-2 rounded-md border p-2.5 ${
                                        values.tax_class === taxClass.id ? 'border-emerald-500 bg-emerald-950/20' : 'border-slate-700 bg-slate-950/40 hover:bg-slate-800/60'
                                    }`}
                                >
                                    <input
                                        type="radio"
                                        name="tax_class"
                                        value={taxClass.id}
                                        checked={values.tax_class === taxClass.id}
                                        onChange={() => set('tax_class', taxClass.id)}
                                        className="mt-0.5 accent-emerald-500"
                                    />
                                    <span>
                                        <span className="block font-mono text-xs font-semibold text-slate-100">{taxClass.label}</span>
                                        <span className="block text-[11px] text-slate-400">{taxClass.hint}</span>
                                    </span>
                                </label>
                            ))}
                        </div>
                    </fieldset>

                    <div className="grid grid-cols-2 items-end gap-3">
                        <label className="flex min-h-11 cursor-pointer items-center gap-2 text-sm text-slate-200 lg:min-h-0">
                            <input
                                type="checkbox"
                                role="switch"
                                checked={values.track_inventory}
                                onChange={(event) => set('track_inventory', event.target.checked)}
                                className="h-4 w-4 accent-emerald-500"
                            />
                            Track inventory
                        </label>
                        <Field id="product_reorder" label="Reorder level" error={errors.reorder_level}>
                            <input
                                id="product_reorder"
                                type="text"
                                inputMode="numeric"
                                value={values.reorder_level}
                                aria-invalid={Boolean(errors.reorder_level)}
                                onChange={(event) => set('reorder_level', event.target.value)}
                                className={`${inputClass(errors.reorder_level, true)} text-right`}
                            />
                        </Field>
                    </div>

                    {editing && (
                        <div className="border-t border-slate-800 pt-4">
                            <p className="font-mono text-[11px] font-semibold uppercase tracking-wider text-rose-400">
                                {product.active ? 'Retire this product' : 'Reactivate this product'}
                            </p>
                            <p className="mb-3 mt-1 text-xs text-slate-400">
                                {product.active
                                    ? 'Deactivated products can no longer be sold but stay in past sales. You can reactivate them later.'
                                    : 'This product is inactive and cannot be sold. Reactivating puts it back on sale.'}
                            </p>
                            {product.active ? (
                                <button
                                    type="button"
                                    onClick={() => onRequestDeactivate(product)}
                                    className="min-h-11 rounded border border-rose-700 px-3 py-1.5 text-xs font-medium text-rose-400 hover:bg-rose-950/40 lg:min-h-0"
                                >
                                    Deactivate product
                                </button>
                            ) : (
                                <button
                                    type="button"
                                    onClick={() => onActivate(product)}
                                    className="min-h-11 rounded border border-emerald-700 px-3 py-1.5 text-xs font-medium text-emerald-400 hover:bg-emerald-950/40 lg:min-h-0"
                                >
                                    Reactivate product
                                </button>
                            )}
                        </div>
                    )}
                </form>
            </>
        </SlideOver>
    );
}
