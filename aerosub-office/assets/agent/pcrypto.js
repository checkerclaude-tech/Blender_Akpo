/* Personal agent: encryption in the phone's browser (PRD N12). The same rules as the laptop's app/crypto.py; the shared test
 * vectors in agent-box/personal/tests/crypto_vectors.json are checked against this file (office-test/ui/personal-crypto-check.cjs).
 *
 *   secret   20 bytes, typed or scanned as a 32-character code
 *   key      HKDF-SHA256(secret, salt "aerosub-personal-v1", info "message-key") -> AES-256-GCM, kept as a NON-extractable key
 *   envelope "v1." + base64url(nonce) + "." + base64url(ciphertext + tag), with "p2l" / "l2p" as the authenticated direction
 *            "v2." + tag + "." + base64url(nonce) + "." + base64url(ciphertext + tag): the same, but it names the browser with an
 *            11-character random tag (given by the laptop at pairing) and the authenticated text is direction + "." + tag
 * The office server only ever carries envelopes. Nothing here is sent anywhere but as an envelope. */
(() => {
  'use strict';
  const ALPHA = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
  const SALT = 'aerosub-personal-v1', INFO = 'message-key';
  const te = new TextEncoder(), td = new TextDecoder('utf-8', { fatal: true });
  const subtle = () => (globalThis.crypto && globalThis.crypto.subtle) || null;

  const b64u = bytes => { let s = ''; for (const b of bytes) s += String.fromCharCode(b); return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, ''); };
  const unb64u = t => { const s = t.replace(/-/g, '+').replace(/_/g, '/'); const bin = atob(s + '='.repeat((4 - (s.length % 4)) % 4)); return Uint8Array.from(bin, c => c.charCodeAt(0)); };
  const hex = bytes => Array.from(bytes, b => b.toString(16).padStart(2, '0')).join('');
  const unhex = h => Uint8Array.from(h.match(/../g) || [], x => parseInt(x, 16));

  /** The 32-character code to 20 bytes. Forgives case, spaces, dashes and the look-alikes O, I and L. Throws if it is not a code. */
  const codeToSecret = code => {
    const t = String(code || '').replace(/[\s-]/g, '').toUpperCase().replace(/O/g, '0').replace(/[IL]/g, '1');
    if (t.length !== 32 || [...t].some(c => !ALPHA.includes(c))) throw new Error('That code is not valid');
    let bits = ''; for (const c of t) bits += ALPHA.indexOf(c).toString(2).padStart(5, '0');
    return Uint8Array.from({ length: 20 }, (_, i) => parseInt(bits.slice(i * 8, i * 8 + 8), 2));
  };
  const secretToCode = secret => {
    let bits = ''; for (const b of secret) bits += b.toString(2).padStart(8, '0');
    let raw = ''; for (let i = 0; i < 160; i += 5) raw += ALPHA[parseInt(bits.slice(i, i + 5), 2)];
    return raw.match(/.{8}/g).join('-');
  };

  /** extractable is only for the tests; the app always keeps the key non-extractable. */
  const deriveKey = async (secret, extractable = false) => {
    const base = await subtle().importKey('raw', secret, 'HKDF', false, ['deriveKey']);
    return subtle().deriveKey({ name: 'HKDF', hash: 'SHA-256', salt: te.encode(SALT), info: te.encode(INFO) }, base, { name: 'AES-GCM', length: 256 }, extractable, ['encrypt', 'decrypt']);
  };

  const V1 = /^v1\.([A-Za-z0-9_-]{16})\.([A-Za-z0-9_-]{16,})$/;
  const V2 = /^v2\.([A-Za-z0-9_-]{11})\.([A-Za-z0-9_-]{16})\.([A-Za-z0-9_-]{16,})$/;
  const TAG = /^[A-Za-z0-9_-]{11}$/;
  /** What is authenticated besides the text: the direction, and for v2 also the tag, so a message cannot be moved to another browser's tag. */
  const aad = (direction, tag) => te.encode(tag ? direction + '.' + tag : direction);

  /** With a tag the envelope is v2 (every browser paired since multi-device); without one it is the old v1. */
  const seal = async (key, direction, message, nonce, tag) => {
    if (tag != null && !TAG.test(tag)) throw new Error('a tag is 11 URL-safe characters');
    nonce = nonce || globalThis.crypto.getRandomValues(new Uint8Array(12));
    const plain = te.encode(JSON.stringify(message));
    if (plain.length > 24000) throw new Error('That message is too long');
    const ct = new Uint8Array(await subtle().encrypt({ name: 'AES-GCM', iv: nonce, additionalData: aad(direction, tag) }, key, plain));
    return (tag ? 'v2.' + tag + '.' : 'v1.') + b64u(nonce) + '.' + b64u(ct);
  };

  /** The label the reply that completes pairing travels under, derived from the one-time code (the laptop derives the same one). The browser
   *  has not learned its real tag yet, so it collects from this one until the rekey, which carries the real tag, arrives. */
  const pairingTag = async secret => {
    const base = await subtle().importKey('raw', secret, 'HKDF', false, ['deriveBits']);
    return b64u(new Uint8Array(await subtle().deriveBits({ name: 'HKDF', hash: 'SHA-256', salt: te.encode(SALT), info: te.encode('pairing-tag') }, base, 64)));
  };

  /** The one-time value inside an envelope of either format (used to recognise a copy of a message already seen), or '' if it is not one. */
  const nonceOf = envelope => { const v2 = V2.exec(envelope || ''), v1 = v2 ? null : V1.exec(envelope || ''); return v2 ? v2[2] : v1 ? v1[1] : ''; };

  /** The tag of a v2 envelope, or null for v1 and for anything else. The relay reads the same place to hand each browser its own messages. */
  const tagOf = envelope => { const m = V2.exec(envelope || ''); return m ? m[1] : null; };

  const open = async (key, direction, envelope) => {
    const v2 = V2.exec(envelope || ''), v1 = v2 ? null : V1.exec(envelope || '');
    if (!v2 && !v1) throw new Error('not an envelope');
    const [tag, nonce, body] = v2 ? [v2[1], v2[2], v2[3]] : [null, v1[1], v1[2]];
    let obj;
    try { obj = JSON.parse(td.decode(await subtle().decrypt({ name: 'AES-GCM', iv: unb64u(nonce), additionalData: aad(direction, tag) }, key, unb64u(body)))); }
    catch { throw new Error('cannot be opened with this key'); }
    if (!obj || typeof obj !== 'object' || Array.isArray(obj)) throw new Error('not a message');
    return obj;
  };

  const api = { supported: () => !!subtle(), codeToSecret, secretToCode, deriveKey, seal, open, tagOf, nonceOf, pairingTag, b64u, unb64u, hex, unhex, P2L: 'p2l', L2P: 'l2p' };
  if (typeof window !== 'undefined' && window.AA) window.AA.pcrypto = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
