// An INDEPENDENT check of vectors.v1.json: Node's own SHA-256, Ed25519 and a few lines of JSON canonicalization that share no
// code with the PHP kernel. If the PHP serializer or signer were wrong in a way that is only consistent with itself, this fails.
//   node tests/Fixtures/license-contract/verify-with-node.mjs
// Node built-ins only; nothing to install. The file holds no signed tokens (they look like credentials to secret scanners):
// Node rebuilds each token from the PUBLIC test seed and compares its SHA-256 with the digest the PHP side recorded.
import { createHash, createPrivateKey, createPublicKey, sign, verify } from 'node:crypto';
import { readFileSync } from 'node:fs';

const vectors = JSON.parse(readFileSync(new URL('./vectors.v1.json', import.meta.url), 'utf8'));

// RFC 8785 for the contract's subset: ASCII keys sorted, no whitespace, integers, strings via JSON.stringify.
function canonical(value) {
    if (Array.isArray(value)) return `[${value.map(canonical).join(',')}]`;
    if (value !== null && typeof value === 'object') {
        return `{${Object.keys(value).sort().map((key) => `${JSON.stringify(key)}:${canonical(value[key])}`).join(',')}}`;
    }
    return JSON.stringify(value);
}

const b64url = (buffer) => buffer.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const sha256 = (text) => createHash('sha256').update(text).digest('hex');

// Ed25519 key from the public test seed, via the fixed PKCS#8 / SPKI prefixes for raw 32-byte keys.
const seed = createHash('sha256').update(vectors.seed_label).digest();
const privateKey = createPrivateKey({ key: Buffer.concat([Buffer.from('302e020100300506032b657004220420', 'hex'), seed]), format: 'der', type: 'pkcs8' });
const publicKey = createPublicKey(privateKey);
const rawPublic = publicKey.export({ format: 'der', type: 'spki' }).subarray(-32);

let failures = 0;
const check = (ok, message) => {
    if (!ok) {
        failures++;
        console.error(`FAIL ${message}`);
    }
};

const tokenFor = (bytes) => `${b64url(Buffer.from(bytes, 'utf8'))}.${b64url(sign(null, Buffer.from(bytes, 'utf8'), privateKey))}`;

check(rawPublic.toString('base64') === vectors.public_key_base64, 'the seed-derived public key matches the file');

for (const item of vectors.canonicalization) {
    check(canonical(item.input) === item.canonical, `canonicalization of ${JSON.stringify(item.input)}`);
}

for (const item of vectors.accepted) {
    check(canonical(item.payload) === item.canonical, `${item.name}: independent canonical form matches`);
    const token = tokenFor(item.canonical);
    check(sha256(token) === item.token_sha256, `${item.name}: Node reproduces the same signed token (SHA-256 of it matches)`);
    const [payload, signature] = token.split('.');
    check(verify(null, Buffer.from(payload.replace(/-/g, '+').replace(/_/g, '/'), 'base64'), publicKey, Buffer.from(signature.replace(/-/g, '+').replace(/_/g, '/'), 'base64')), `${item.name}: Ed25519 signature verifies`);
}

for (const item of vectors.rejected) {
    if (item.recipe.build === 'swap_payload') {
        const original = tokenFor(canonical(vectors.base_payload)).split('.')[1];
        const forged = Buffer.from(canonical({ ...vectors.base_payload, ...item.recipe.overrides }), 'utf8');
        check(!verify(null, forged, publicKey, Buffer.from(original.replace(/-/g, '+').replace(/_/g, '/'), 'base64')), `${item.name}: signature must NOT verify`);
    }
}

console.log(failures === 0 ? 'independent check passed' : `${failures} failure(s)`);
process.exit(failures === 0 ? 0 : 1);
