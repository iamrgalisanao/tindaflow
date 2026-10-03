import { useLayoutEffect, useRef, useState } from 'react';
import { canonicalMoney, displayMoney, padMoney, parseTyped, positionAfter, significantBefore } from '../lib/moneyInput';

/**
 * A peso amount box: shows "₱1,000.00" as it is typed (thousands separators while typing, two decimals once the cashier
 * leaves the box) and tells the screen the plain text the API takes, "1000.00" -- so every screen that already holds money as
 * text keeps working unchanged. `value` is that plain text; `onChange` receives it. Pass `symbol={false}` where the screen
 * already draws a peso sign beside the box. Everything else (id, className, placeholder, aria-*, onFocus, ...) goes to the input.
 */
export default function MoneyInput({ value, onChange, onBlur, symbol = true, ...inputProps }) {
    const inputRef = useRef(null);
    const caret = useRef(null);
    const [typed, setTyped] = useState(() => parseTyped(value));

    // The screen can set the amount itself (a quick-cash button, a reset): follow it, but not while it only echoes what was typed.
    if (canonicalMoney(typed) !== String(value ?? '')) {
        setTyped(parseTyped(value));
    }

    const shown = displayMoney(typed, { symbol });

    useLayoutEffect(() => {
        const input = inputRef.current;
        if (caret.current !== null && input && document.activeElement === input) {
            const position = positionAfter(shown, caret.current, { symbol });
            input.setSelectionRange(position, position);
        }
        caret.current = null;
    });

    function handleChange(event) {
        const raw = event.target.value;
        caret.current = significantBefore(raw, event.target.selectionStart ?? raw.length);
        const next = parseTyped(raw);
        setTyped(next);
        onChange?.(canonicalMoney(next));
    }

    function handleBlur(event) {
        const padded = padMoney(typed);
        setTyped(padded);
        if (padded !== canonicalMoney(typed)) {
            onChange?.(padded);
        }
        onBlur?.(event);
    }

    return (
        <input
            ref={inputRef}
            type="text"
            inputMode="decimal"
            autoComplete="off"
            {...inputProps}
            value={shown}
            onChange={handleChange}
            onBlur={handleBlur}
        />
    );
}
