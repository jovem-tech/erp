import { afterEach } from 'vitest';
import { cleanup } from '@testing-library/react';
import '@testing-library/jest-dom/vitest';

// `globals: false` no vitest.config.ts desativa o auto-cleanup automático do
// Testing Library entre testes (ele depende de um `afterEach` global). Sem
// isso, o DOM de um teste de componente vaza para o próximo no mesmo arquivo.
afterEach(() => {
  cleanup();
});
