import { defineConfig } from 'vitest/config'

// Entorno jsdom para tener localStorage/fetch globales en los tests del widget.
export default defineConfig({
  test: {
    environment: 'jsdom',
    include: [ 'src/**/*.test.ts' ],
  },
})
