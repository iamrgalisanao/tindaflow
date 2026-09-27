import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

// Frontend unit tests (npm test): the money arithmetic and the retry-safety rules that must never regress, plus the
// session-expired dialog. Plain jsdom; no browser and no running server. Separate from vite.config.js so the build is untouched.
export default defineConfig({
    plugins: [react()],
    test: {
        environment: 'jsdom',
        include: ['resources/js/**/*.test.{js,jsx}'],
    },
});
