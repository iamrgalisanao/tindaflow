import { afterEach, describe, expect, it } from 'vitest';
import { deploymentId } from './deployment';

function setMeta(content) {
    document.querySelectorAll('meta[name="tindaflow-deployment"]').forEach((el) => el.remove());
    if (content !== undefined) {
        const meta = document.createElement('meta');
        meta.name = 'tindaflow-deployment';
        meta.content = content;
        document.head.appendChild(meta);
    }
}

afterEach(() => setMeta(undefined));

describe('deploymentId', () => {
    it('reads the fingerprint app.blade.php rendered into the page', () => {
        setMeta('A1B2C3D4');

        expect(deploymentId()).toBe('A1B2C3D4');
    });

    it('is null when the tag is absent (a fresh, unseeded install)', () => {
        expect(deploymentId()).toBeNull();
    });

    it('is null rather than an empty string if the tag is present but blank', () => {
        setMeta('');

        expect(deploymentId()).toBeNull();
    });
});
