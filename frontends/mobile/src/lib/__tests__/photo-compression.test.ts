import { describe, expect, it } from 'vitest';
import { pickQualityForSize } from '@/lib/photo-compression';

describe('pickQualityForSize', () => {
  const maxBytes = 2 * 1024 * 1024;

  it('escolhe a primeira qualidade cujo tamanho cabe no limite', () => {
    const sizes = [3 * 1024 * 1024, 2.5 * 1024 * 1024, 1.8 * 1024 * 1024, 1 * 1024 * 1024];
    expect(pickQualityForSize(sizes, maxBytes)).toBe(2);
  });

  it('escolhe a primeira qualidade quando ela já cabe', () => {
    const sizes = [1.5 * 1024 * 1024, 1 * 1024 * 1024];
    expect(pickQualityForSize(sizes, maxBytes)).toBe(0);
  });

  it('usa a última (menor) qualidade quando nenhuma cabe no limite', () => {
    const sizes = [5 * 1024 * 1024, 4 * 1024 * 1024, 3 * 1024 * 1024];
    expect(pickQualityForSize(sizes, maxBytes)).toBe(2);
  });

  it('aceita um tamanho exatamente igual ao limite', () => {
    expect(pickQualityForSize([maxBytes], maxBytes)).toBe(0);
  });
});
