import { describe, expect, it } from 'vitest';
import manifest from '@/app/manifest';

describe('mobile web app manifest', () => {
  it('provides general-purpose 192px and 512px icons for broad install compatibility', () => {
    const icons = manifest().icons ?? [];

    expect(icons).toEqual(
      expect.arrayContaining([
        expect.objectContaining({ sizes: '192x192', purpose: expect.stringContaining('any') }),
        expect.objectContaining({ sizes: '512x512', purpose: expect.stringContaining('any') }),
      ])
    );
  });
});
