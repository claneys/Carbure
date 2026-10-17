// TOTP codes of the authenticator apps (RFC 6238: SHA-1, 30 s, 6 digits), for the tests
// of the two-factor authentication
const crypto = require('crypto');

/**
 * TOTP code of a base32 secret, as the authenticator apps compute it
 * @param {string} secret - Secret (base32, spaces allowed)
 * @param {number} offset - Seconds added to now (the next step: +30)
 * @returns {string}
 */
function totp(secret, offset = 0) {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const bits = secret.replace(/\s/g, '').toUpperCase().split('').map(c => alphabet.indexOf(c).toString(2).padStart(5, '0')).join('');
    const key = Buffer.from(bits.match(/.{8}/g).map(byte => parseInt(byte, 2)));
    const counter = Buffer.alloc(8);
    counter.writeBigUInt64BE(BigInt(Math.floor((Date.now() / 1000 + offset) / 30)));
    const hash = crypto.createHmac('sha1', key).update(counter).digest();
    const start = hash[hash.length - 1] & 0x0f;
    return String((hash.readUInt32BE(start) & 0x7fffffff) % 1_000_000).padStart(6, '0');
}

module.exports = { totp };
