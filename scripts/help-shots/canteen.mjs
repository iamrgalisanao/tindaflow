/**
 * Helpers for the canteen help pictures (scripts 22 and up). Kept apart from lib.mjs so the retail scripts are untouched.
 */
import { execFileSync } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../..');

/** Runs a seeder against the SCRATCH database only; refuses to run against anything else (never the development data). */
export function seed(seeder) {
    if (process.env.DB_DATABASE !== 'tindaflow_help') {
        throw new Error('Refusing to seed: DB_DATABASE is not the scratch database tindaflow_help. Run these scripts through run.sh.');
    }
    execFileSync('php', ['artisan', 'db:seed', `--class=${seeder}`, '--force', '--no-interaction'], { cwd: ROOT, env: process.env, stdio: 'inherit' });
}
