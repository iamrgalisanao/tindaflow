/**
 * Reads the per-installation fingerprint app.blade.php renders as a <meta> tag (App\Support\DeploymentId). Anti-
 * redistribution forensics only -- never used to gate or block anything in this app. Null before the first store
 * exists (a fresh, unseeded install) or if the tag is ever removed from the page.
 */
export function deploymentId() {
    const value = document.querySelector('meta[name="tindaflow-deployment"]')?.content;

    return value ? value.trim() || null : null;
}
