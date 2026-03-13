/**
 * Blossom (BUD-01) Authorization helper.
 *
 * Blossom servers require kind 24242 auth events (NOT NIP-98 kind 27235).
 * Required tags: ["t", "upload"], ["x", sha256hex], ["expiration", unix_ts].
 *
 * blossom.band rejects media containing GPS metadata, so images are
 * re-drawn through an off-screen Canvas before upload (which strips all
 * EXIF data).  The SHA-256 hash in the "x" tag is computed from the
 * *cleaned* bytes so it matches what the server receives.
 *
 * @see https://github.com/hzrd149/blossom/blob/master/buds/01.md
 */

/**
 * Maximum upload size for blossom.band free tier (20 MiB).
 */
export const BLOSSOM_MAX_UPLOAD_BYTES = 20 * 1024 * 1024;

/**
 * Compute the SHA-256 hex digest of a File (or Blob).
 * @param {File|Blob} file
 * @returns {Promise<string>} lowercase hex string
 */
export async function sha256Hex(file) {
    const buffer = await file.arrayBuffer();
    const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
    return Array.from(new Uint8Array(hashBuffer))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}

/**
 * Strip EXIF / GPS metadata from an image by re-drawing through Canvas.
 * Non-image files (videos, etc.) are returned unchanged.
 *
 * @param {File} file
 * @returns {Promise<File>}
 */
export async function stripExifFromImage(file) {
    const strippable = ['image/jpeg', 'image/png', 'image/webp'];
    if (!strippable.includes(file.type)) {
        return file;
    }

    return new Promise((resolve) => {
        const img = new Image();
        const url = URL.createObjectURL(file);

        img.onload = () => {
            URL.revokeObjectURL(url);
            const canvas = document.createElement('canvas');
            canvas.width = img.naturalWidth;
            canvas.height = img.naturalHeight;
            canvas.getContext('2d').drawImage(img, 0, 0);

            const quality = file.type === 'image/png' ? undefined : 0.92;
            canvas.toBlob(
                (blob) => {
                    if (!blob) { resolve(file); return; }
                    resolve(new File([blob], file.name, { type: file.type }));
                },
                file.type,
                quality,
            );
        };

        img.onerror = () => {
            URL.revokeObjectURL(url);
            resolve(file); // fallback – send original
        };

        img.src = url;
    });
}

/**
 * Prepare a file for Blossom upload.
 *
 * 1. Strips EXIF/GPS from images (Canvas re-draw).
 * 2. Computes SHA-256 of the *cleaned* file.
 * 3. Builds an unsigned kind-24242 auth event whose "x" tag matches.
 *
 * @param {string} pubkey – hex pubkey
 * @param {File}   file   – original file from the user
 * @returns {Promise<{event: object, file: File}>}
 */
export async function prepareBlossomUpload(pubkey, file) {
    const cleanFile  = await stripExifFromImage(file);
    const hash       = await sha256Hex(cleanFile);
    const expiration = String(Math.floor(Date.now() / 1000) + 300); // 5 min

    const event = {
        kind: 24242,
        created_at: Math.floor(Date.now() / 1000),
        pubkey,
        tags: [
            ['t', 'upload'],
            ['x', hash],
            ['expiration', expiration],
        ],
        content: `Upload ${file.name || 'file'}`,
    };

    return { event, file: cleanFile };
}

