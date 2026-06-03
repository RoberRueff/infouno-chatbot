import { defineConfig } from 'vite'
import preact from '@preact/preset-vite'

export default defineConfig({
  plugins: [ preact() ],
  build: {
    lib: {
      entry:    'src/index.ts',
      formats:  [ 'iife' ],
      name:     'InfounoWidget',
      fileName: () => 'widget.js',
    },
    rollupOptions: {
      output: {
        // Un único archivo auto-ejecutable, sin chunks externos
        inlineDynamicImports: true,
      },
    },
    minify:   'esbuild',
    target:   'es2020',
    outDir:   'dist',
    // Reporta si el bundle supera los 50KB gzip (guardrail de peso)
    reportCompressedSize: true,
    chunkSizeWarningLimit: 50,
  },
})
