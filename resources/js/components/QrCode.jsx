import { useMemo } from 'react';
import QRCode from 'qrcode';

/**
 * A QR code drawn as an inline SVG from the matrix the library computes, so nothing is injected as markup and it prints crisply at
 * any size. `value` is the text the code carries (an employee code such as TSC00001, which a scanner types into the till's lookup
 * box). Four modules of quiet zone surround it, as the QR specification asks, so a scanner can find its edges on a printed card.
 */
export default function QrCode({ value, size = 96, label }) {
    const matrix = useMemo(() => {
        try {
            return QRCode.create(String(value), { errorCorrectionLevel: 'M' }).modules;
        } catch {
            return null;
        }
    }, [value]);

    if (matrix === null) {
        return null;
    }

    const quiet = 4;
    const total = matrix.size + quiet * 2;
    let path = '';
    for (let row = 0; row < matrix.size; row++) {
        for (let column = 0; column < matrix.size; column++) {
            if (matrix.data[row * matrix.size + column]) {
                path += `M${column + quiet} ${row + quiet}h1v1h-1z`;
            }
        }
    }

    return (
        <svg
            role="img"
            aria-label={label ?? `QR code for ${value}`}
            viewBox={`0 0 ${total} ${total}`}
            width={size}
            height={size}
            shapeRendering="crispEdges"
            className="shrink-0 bg-white"
        >
            <path d={path} fill="#000" />
        </svg>
    );
}
