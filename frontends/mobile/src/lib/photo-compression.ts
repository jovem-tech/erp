export const MAX_OPERATIONAL_PHOTO_SOURCE_BYTES = 20 * 1024 * 1024;

const OPERATIONAL_PHOTO_TYPES = new Set([
  'image/jpeg',
  'image/png',
  'image/webp',
  'image/avif',
  'image/heic',
  'image/heif',
]);
const OPERATIONAL_PHOTO_EXTENSIONS = new Set(['jpg', 'jpeg', 'png', 'webp', 'avif', 'heic', 'heif']);

export function isOperationalPhotoFile(file: File): boolean {
  const mime = file.type.toLowerCase();
  const extension = file.name.split('.').pop()?.toLowerCase() ?? '';

  return OPERATIONAL_PHOTO_TYPES.has(mime)
    || ((mime === '' || mime === 'application/octet-stream') && OPERATIONAL_PHOTO_EXTENSIONS.has(extension));
}

export const DEFAULT_COMPRESSION_OPTIONS = {
  maxDimension: 1920,
  maxBytes: 2 * 1024 * 1024,
  qualitySteps: [0.9, 0.78, 0.65, 0.52],
};

export type CompressionOptions = {
  maxDimension?: number;
  maxBytes?: number;
  qualitySteps?: number[];
};

/**
 * Dado o tamanho (em bytes) do blob gerado para cada qualidade em
 * `qualitySteps` (mesma ordem, decrescente), escolhe o índice da primeira
 * qualidade que cabe em `maxBytes`. Se nenhuma couber, usa a última
 * (menor qualidade disponível) mesmo assim — deixa o backend rejeitar com
 * 422 se realmente não couber, o que não é esperado na prática com JPEG a
 * 1920px e qualidade 0.52.
 */
export function pickQualityForSize(sizes: number[], maxBytes: number): number {
  const index = sizes.findIndex((size) => size <= maxBytes);
  return index === -1 ? sizes.length - 1 : index;
}

function computeTargetSize(width: number, height: number, maxDimension: number): { width: number; height: number } {
  if (width <= maxDimension && height <= maxDimension) {
    return { width, height };
  }

  const scale = maxDimension / Math.max(width, height);
  return { width: Math.round(width * scale), height: Math.round(height * scale) };
}

function canvasToBlob(canvas: HTMLCanvasElement, quality: number): Promise<Blob | null> {
  return new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', quality));
}

function withJpegExtension(filename: string): string {
  const withoutExtension = filename.replace(/\.[^./\\]+$/, '');
  return `${withoutExtension || 'foto'}.jpg`;
}

export async function compressImageFile(file: File, options: CompressionOptions = {}): Promise<File> {
  const maxDimension = options.maxDimension ?? DEFAULT_COMPRESSION_OPTIONS.maxDimension;
  const maxBytes = options.maxBytes ?? DEFAULT_COMPRESSION_OPTIONS.maxBytes;
  const qualitySteps = options.qualitySteps ?? DEFAULT_COMPRESSION_OPTIONS.qualitySteps;

  let bitmap: ImageBitmap;
  try {
    bitmap = await createImageBitmap(file);
  } catch (error) {
    // Safari and older Chromium builds may not decode HEIC/HEIF (or AVIF)
    // locally. Preserve the validated source so the backend can normalize it.
    if (isOperationalPhotoFile(file)) {
      return file;
    }
    throw error;
  }
  const { width, height } = computeTargetSize(bitmap.width, bitmap.height, maxDimension);

  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const context = canvas.getContext('2d');

  if (!context) {
    bitmap.close();
    return file;
  }

  context.drawImage(bitmap, 0, 0, width, height);
  bitmap.close();

  const blobs: Array<Blob | null> = [];
  for (const quality of qualitySteps) {
    // eslint-disable-next-line no-await-in-loop -- só 4 iterações, sequencial por simplicidade
    blobs.push(await canvasToBlob(canvas, quality));
  }

  const sizes = blobs.map((blob) => blob?.size ?? Infinity);
  const chosenIndex = pickQualityForSize(sizes, maxBytes);
  const chosenBlob = blobs[chosenIndex] ?? file;

  return new File([chosenBlob], withJpegExtension(file.name), { type: 'image/jpeg' });
}
