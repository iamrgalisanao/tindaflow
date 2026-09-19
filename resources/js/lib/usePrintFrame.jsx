import { useCallback, useRef, useState } from 'react';

/**
 * Prints a server-rendered document (an invoice) through the browser's print dialog without showing it: the
 * HTML goes into an off-screen frame that is sandboxed the way the invoice panel's preview is -- no scripts
 * run in it, and `allow-same-origin` is only there so the frame can be printed. The document is never
 * inserted into the app's own page.
 *
 * Each call to `print(html)` mounts a fresh frame (a new key), so printing the same document twice reloads
 * it and the dialog opens again. Render `frame` once anywhere in the component tree.
 */
export function usePrintFrame() {
    const [job, setJob] = useState(null);
    const frameRef = useRef(null);

    const print = useCallback((html) => setJob({ html, id: crypto.randomUUID() }), []);

    function printLoadedFrame() {
        const target = frameRef.current?.contentWindow;
        target?.focus();
        target?.print();
    }

    const frame = job ? (
        <iframe
            key={job.id}
            ref={frameRef}
            title="Invoice to print"
            aria-hidden="true"
            tabIndex={-1}
            srcDoc={job.html}
            sandbox="allow-same-origin allow-modals"
            onLoad={printLoadedFrame}
            style={{ position: 'fixed', right: 0, bottom: 0, width: 0, height: 0, border: 0 }}
        />
    ) : null;

    return { print, frame };
}
