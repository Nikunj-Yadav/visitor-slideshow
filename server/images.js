// Server-side image validation and cleaning.
// Step 1: look at the first bytes ("magic numbers") - the filename and the
//         browser-declared type are never trusted.
// Step 2: let `sharp` fully decode the image. A corrupt or fake image throws.
// Step 3: re-encode as JPEG. This removes hidden metadata (GPS location!),
//         fixes phone rotation and shrinks huge photos for the TV.
const sharp = require('sharp');

const TYPES = {
  'image/jpeg': (b) => b.length > 3 && b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff,
  'image/png': (b) => b.length > 8 && b.subarray(0, 8).equals(Buffer.from([0x89, 0x50, 0x4e, 0x47, 0x0d, 0x0a, 0x1a, 0x0a])),
  'image/webp': (b) => b.length > 12 && b.toString('ascii', 0, 4) === 'RIFF' && b.toString('ascii', 8, 12) === 'WEBP',
};

function sniffMime(buf) {
  return Object.keys(TYPES).find((m) => TYPES[m](buf)) || null;
}

/** Returns { ok:true, data:Buffer } or { ok:false, reason:string } */
async function validateAndClean(buf, declaredMime) {
  const real = sniffMime(buf);
  if (!real) return { ok: false, reason: 'Not a JPG, PNG or WebP image.' };
  if (real !== declaredMime) return { ok: false, reason: 'File type does not match its content.' };
  try {
    const data = await sharp(buf, { failOn: 'error', limitInputPixels: 60e6 })
      .rotate()
      .resize({ width: 2560, height: 2560, fit: 'inside', withoutEnlargement: true })
      .flatten({ background: '#ffffff' })
      .jpeg({ quality: 85 })
      .toBuffer();
    return { ok: true, data };
  } catch (e) {
    return { ok: false, reason: 'Image is damaged or cannot be read.' };
  }
}

module.exports = { sniffMime, validateAndClean };
