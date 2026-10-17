/**
 * ZIP files, written and read in the browser (backup of Administration > Backup):
 * the API only exchanges JSON, the PHP image having no zip extension.
 *
 * Written: one entry per file, compressed with deflate (CompressionStream of the
 * browser), stored as is without it. Read: the entries stored or compressed with
 * deflate, as written by Carbure or by the archive tools of the systems (no
 * encryption, no ZIP64).
 *
 * @module zip
 */

/** CRC-32 table (polynomial 0xEDB88320) */
const CRC_TABLE = (() => {
    const table = new Uint32Array(256);
    for (let n = 0; n < 256; n++) {
        let c = n;
        for (let k = 0; k < 8; k++) {
            c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1;
        }
        table[n] = c >>> 0;
    }
    return table;
})();

/** Compression methods of the ZIP format */
const STORED = 0;
const DEFLATE = 8;

/** Bit 11 of the flags: names in UTF-8 */
const UTF8_NAMES = 0x0800;

/** Largest content read from an entry (bytes) */
export const ZIP_MAX_ENTRY_SIZE = 200 * 1024 * 1024;

/** Largest content read from all the entries of a ZIP (bytes) */
export const ZIP_MAX_TOTAL_SIZE = 400 * 1024 * 1024;

/**
 * CRC-32 of bytes
 * @param {Uint8Array} bytes - Content
 * @returns {number} The CRC (unsigned)
 */
export function crc32(bytes) {
    let crc = 0xFFFFFFFF;
    for (let i = 0; i < bytes.length; i++) {
        crc = CRC_TABLE[(crc ^ bytes[i]) & 0xFF] ^ (crc >>> 8);
    }
    return (crc ^ 0xFFFFFFFF) >>> 0;
}

/**
 * Bytes passed through a stream of the browser (compression or decompression)
 * @param {Uint8Array} bytes - Input
 * @param {TransformStream} stream - CompressionStream or DecompressionStream
 * @returns {Promise<Uint8Array>} Output
 */
async function transform(bytes, stream) {
    return new Uint8Array(await new Response(new Blob([bytes]).stream().pipeThrough(stream)).arrayBuffer());
}

/**
 * Inflates raw deflate data, stopped as soon as the output exceeds a size: the size
 * written in the ZIP is not trusted (a small file could inflate to gigabytes)
 * @param {Uint8Array} bytes - Compressed data
 * @param {number} limit - Largest output (bytes)
 * @param {string} name - Name of the entry, for the error
 * @returns {Promise<Uint8Array>} Output
 * @throws {Error} If the output exceeds the limit
 */
async function inflate(bytes, limit, name) {
    const reader = new Blob([bytes]).stream().pipeThrough(new DecompressionStream('deflate-raw')).getReader();
    const chunks = [];
    let total = 0;
    for (;;) {
        const { done, value } = await reader.read();
        if (done) {
            return concat(chunks);
        }
        total += value.length;
        if (total > limit) {
            await reader.cancel().catch(() => {});
            throw new Error(`${name}: file too large`);
        }
        chunks.push(value);
    }
}

/**
 * Compresses with raw deflate when the browser can, stores otherwise
 * @param {Uint8Array} bytes - Content
 * @returns {Promise<{method: number, data: Uint8Array}>}
 */
async function compress(bytes) {
    if (typeof CompressionStream === 'function') {
        try {
            return { method: DEFLATE, data: await transform(bytes, new CompressionStream('deflate-raw')) };
        } catch (e) {
            // deflate-raw unknown to this browser: stored
        }
    }
    return { method: STORED, data: bytes };
}

/**
 * Date and time in the MS-DOS format of the ZIP entries
 * @param {Date} date - Date
 * @returns {{time: number, date: number}}
 */
function dosDateTime(date) {
    return {
        time: (date.getHours() << 11) | (date.getMinutes() << 5) | Math.floor(date.getSeconds() / 2),
        date: ((Math.max(date.getFullYear(), 1980) - 1980) << 9) | ((date.getMonth() + 1) << 5) | date.getDate()
    };
}

/**
 * Writes a ZIP file
 * @param {Array<{name: string, data: Uint8Array|string}>} files - Files (text written in UTF-8)
 * @param {Date} [date] - Date of the entries (now by default)
 * @returns {Promise<Uint8Array>} The ZIP file
 */
export async function createZip(files, date = new Date()) {
    const encoder = new TextEncoder();
    const when = dosDateTime(date);
    const locals = [];
    const centrals = [];
    let offset = 0;

    for (const file of files) {
        const name = encoder.encode(file.name);
        const content = typeof file.data === 'string' ? encoder.encode(file.data) : file.data;
        const { method, data } = await compress(content);
        const fields = { method, name, crc: crc32(content), compressed: data.length, size: content.length, ...when };

        const local = header(0x04034b50, fields, offset, false);
        locals.push(local, data);
        centrals.push(header(0x02014b50, fields, offset, true));
        offset += local.length + data.length;
    }

    const centralSize = centrals.reduce((sum, part) => sum + part.length, 0);
    const end = new DataView(new ArrayBuffer(22));
    end.setUint32(0, 0x06054b50, true);
    end.setUint16(8, files.length, true);
    end.setUint16(10, files.length, true);
    end.setUint32(12, centralSize, true);
    end.setUint32(16, offset, true);

    return concat([...locals, ...centrals, new Uint8Array(end.buffer)]);
}

/**
 * Local header (followed by the data) or entry of the central directory
 * @param {number} signature - 0x04034b50 (local) or 0x02014b50 (central)
 * @param {Object} fields - method, name (bytes), crc, compressed, size, time, date
 * @param {number} offset - Position of the local header in the file
 * @param {boolean} central - Entry of the central directory
 * @returns {Uint8Array}
 */
function header(signature, fields, offset, central) {
    const shift = central ? 2 : 0;
    const size = (central ? 46 : 30) + fields.name.length;
    const view = new DataView(new ArrayBuffer(size));
    view.setUint32(0, signature, true);
    if (central) {
        view.setUint16(4, 20, true);
    }
    view.setUint16(4 + shift, 20, true);
    view.setUint16(6 + shift, UTF8_NAMES, true);
    view.setUint16(8 + shift, fields.method, true);
    view.setUint16(10 + shift, fields.time, true);
    view.setUint16(12 + shift, fields.date, true);
    view.setUint32(14 + shift, fields.crc, true);
    view.setUint32(18 + shift, fields.compressed, true);
    view.setUint32(22 + shift, fields.size, true);
    view.setUint16(26 + shift, fields.name.length, true);
    if (central) {
        view.setUint32(42, offset, true);
    }
    const bytes = new Uint8Array(view.buffer);
    bytes.set(fields.name, size - fields.name.length);
    return bytes;
}

/**
 * Joins byte arrays
 * @param {Array<Uint8Array>} parts - Parts
 * @returns {Uint8Array}
 */
function concat(parts) {
    const result = new Uint8Array(parts.reduce((sum, part) => sum + part.length, 0));
    let position = 0;
    for (const part of parts) {
        result.set(part, position);
        position += part.length;
    }
    return result;
}

/**
 * Whether bytes start like a ZIP file (PK\x03\x04, or PK\x05\x06 when empty)
 * @param {Uint8Array} bytes - Content
 * @returns {boolean}
 */
export function isZip(bytes) {
    return bytes.length >= 4 && bytes[0] === 0x50 && bytes[1] === 0x4B
        && ((bytes[2] === 3 && bytes[3] === 4) || (bytes[2] === 5 && bytes[3] === 6));
}

/**
 * Position of the end of central directory record
 * @param {DataView} view - The file
 * @returns {number}
 * @throws {Error} If not found (not a ZIP file)
 */
function findEnd(view) {
    const last = Math.max(0, view.byteLength - 22 - 0xFFFF);
    for (let i = view.byteLength - 22; i >= last; i--) {
        if (view.getUint32(i, true) === 0x06054b50) {
            return i;
        }
    }
    throw new Error('Not a ZIP file');
}

/**
 * Reads the files of a ZIP file (folders skipped)
 * @param {ArrayBuffer|Uint8Array} buffer - The ZIP file
 * @returns {Promise<Array<{name: string, data: Uint8Array}>>} The files
 * @throws {Error} If not a ZIP file, or an entry is encrypted, ZIP64, compressed otherwise than with deflate,
 *   damaged (size or CRC-32 that differs) or too large (ZIP_MAX_ENTRY_SIZE, ZIP_MAX_TOTAL_SIZE in all)
 */
export async function readZip(buffer) {
    const bytes = buffer instanceof Uint8Array ? buffer : new Uint8Array(buffer);
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const end = findEnd(view);
    const count = view.getUint16(end + 10, true);
    let position = view.getUint32(end + 16, true);
    const decoder = new TextDecoder();
    const files = [];
    let total = 0;

    for (let i = 0; i < count; i++) {
        if (view.getUint32(position, true) !== 0x02014b50) {
            throw new Error('Invalid ZIP file');
        }
        const entry = {
            flags: view.getUint16(position + 8, true),
            method: view.getUint16(position + 10, true),
            crc: view.getUint32(position + 16, true),
            compressed: view.getUint32(position + 20, true),
            size: view.getUint32(position + 24, true),
            offset: view.getUint32(position + 42, true)
        };
        const nameLength = view.getUint16(position + 28, true);
        const name = decoder.decode(bytes.subarray(position + 46, position + 46 + nameLength));
        position += 46 + nameLength + view.getUint16(position + 30, true) + view.getUint16(position + 32, true);
        if (!name.endsWith('/')) {
            const data = await readEntry(bytes, view, name, entry, Math.min(ZIP_MAX_ENTRY_SIZE, ZIP_MAX_TOTAL_SIZE - total));
            total += data.length;
            files.push({ name, data });
        }
    }
    return files;
}

/**
 * Content of an entry of a ZIP file
 * @param {Uint8Array} bytes - The ZIP file
 * @param {DataView} view - The ZIP file
 * @param {string} name - Name of the entry
 * @param {Object} entry - flags, method, crc, compressed, size, offset (central directory)
 * @param {number} limit - Largest content (bytes)
 * @returns {Promise<Uint8Array>}
 * @throws {Error} If the entry cannot be read, is too large or damaged
 */
async function readEntry(bytes, view, name, entry, limit) {
    if (entry.flags & 1) {
        throw new Error(`${name}: encrypted entries are not supported`);
    }
    if (entry.compressed === 0xFFFFFFFF || entry.size === 0xFFFFFFFF || entry.size > limit) {
        throw new Error(`${name}: file too large`);
    }
    // Never more than the size written in the ZIP (checked above)
    const data = await entryData(bytes, view, name, entry, entry.size);
    if (data.length !== entry.size || crc32(data) !== entry.crc) {
        throw new Error(`${name}: damaged file`);
    }
    return data;
}

/**
 * Stored or inflated content of an entry
 * @param {Uint8Array} bytes - The ZIP file
 * @param {DataView} view - The ZIP file
 * @param {string} name - Name of the entry
 * @param {Object} entry - method, compressed, offset (central directory)
 * @param {number} limit - Largest content (bytes)
 * @returns {Promise<Uint8Array>}
 * @throws {Error} If the entry cannot be read
 */
async function entryData(bytes, view, name, entry, limit) {
    if (view.getUint32(entry.offset, true) !== 0x04034b50) {
        throw new Error('Invalid ZIP file');
    }
    const start = entry.offset + 30 + view.getUint16(entry.offset + 26, true) + view.getUint16(entry.offset + 28, true);
    const data = bytes.subarray(start, start + entry.compressed);
    if (entry.method === STORED) {
        return data;
    }
    if (entry.method === DEFLATE) {
        return inflate(data, limit, name);
    }
    throw new Error(`${name}: compression method ${entry.method} not supported`);
}
