import annotations from './annotations.json';

const urls = import.meta.glob('./*.webp', { eager: true, query: '?url', import: 'default' });

/**
 * Every captured screenshot, keyed by id: { url, w, h, t } where `t` maps a target name to its box on the picture as
 * [left, top, width, height] in percent. Both the pictures and annotations.json are written by scripts/help-shots (run it
 * again whenever a screen changes), so a target name in a guide always refers to a real element at capture time.
 */
export const SHOTS = Object.fromEntries(
    Object.entries(annotations)
        .filter(([id]) => urls[`./${id}.webp`] !== undefined)
        .map(([id, meta]) => [id, { ...meta, url: urls[`./${id}.webp`] }]),
);
