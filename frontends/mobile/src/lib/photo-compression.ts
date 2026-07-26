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

  const bitmap = await createImageBitmap(file);
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
