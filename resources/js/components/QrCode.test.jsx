import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import QrCode from './QrCode';

afterEach(cleanup);

describe('QrCode', () => {
    it('draws a labelled svg whose path is not empty', () => {
        render(<QrCode value="TSC00001" />);
        const svg = screen.getByRole('img', { name: 'QR code for TSC00001' });
        expect(svg.tagName.toLowerCase()).toBe('svg');
        expect(svg.querySelector('path')?.getAttribute('d')?.length ?? 0).toBeGreaterThan(50);
    });

    it('draws a different code for a different value, and the same one for the same value', () => {
        const draw = (value) => {
            const { container, unmount } = render(<QrCode value={value} />);
            const d = container.querySelector('path').getAttribute('d');
            unmount();
            return d;
        };
        expect(draw('TSC00001')).toBe(draw('TSC00001'));
        expect(draw('TSC00001')).not.toBe(draw('SUB00001'));
    });

    it('leaves a quiet zone of four modules around the code', () => {
        const { container } = render(<QrCode value="TSC00001" />);
        const [, , width] = container.querySelector('svg').getAttribute('viewBox').split(' ').map(Number);
        const first = container.querySelector('path').getAttribute('d').match(/^M(\d+) (\d+)/);
        expect(Number(first[1])).toBeGreaterThanOrEqual(4);
        expect(Number(first[2])).toBeGreaterThanOrEqual(4);
        expect(width).toBeGreaterThan(8);
    });
});
