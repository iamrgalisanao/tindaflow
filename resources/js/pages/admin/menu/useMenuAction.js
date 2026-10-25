import { useCallback, useRef, useState } from 'react';
import { fieldErrors, request } from '../catalog/catalogApi';

/**
 * Runs one menu change and then reloads the screen's data, the way the Menu groups screen does. A validation error on a field is
 * handed back as `fieldError` (shown next to the form that caused it); any other failure becomes the page `failure`.
 */
export function useMenuAction(reload, setFailure, setToast) {
    const [busy, setBusy] = useState(false);
    const [fieldError, setFieldError] = useState(null);
    const sequence = useRef(0);

    const run = useCallback(
        async (path, options, { done, onSuccess } = {}) => {
            const current = ++sequence.current;
            setBusy(true);
            setFieldError(null);
            const result = await request(path, options);
            if (current === sequence.current) {
                setBusy(false);
            }
            if (!result.ok) {
                const errors = fieldErrors(result);
                const message = Object.values(errors)[0];
                if (message) {
                    setFieldError(message);
                } else {
                    setFailure(result);
                }
                return false;
            }
            onSuccess?.(result.body);
            if (done) {
                setToast(done);
            }
            await reload();
            return true;
        },
        [reload, setFailure, setToast],
    );

    return { run, busy, fieldError, clearFieldError: () => setFieldError(null) };
}
