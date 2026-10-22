import { fileURLToPath, URL } from 'node:url'
import path from 'node:path'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'
import { viteStaticCopy } from 'vite-plugin-static-copy'

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    vue(),
    vueDevTools(),
    // Copy TinyMCE self-hosted assets (skins, plugins, themes) vào dist/tinymce
    // → TinyMCE có thể load qua `base_url: '/tinymce'` lúc chạy thật.
    viteStaticCopy({
      targets: [
        {
          src: 'node_modules/tinymce/icons/**',
          dest: 'tinymce/icons',
          rename: { stripBase: 3 },
        },
        {
          src: 'node_modules/tinymce/models/**',
          dest: 'tinymce/models',
          rename: { stripBase: 3 },
        },
        {
          src: 'node_modules/tinymce/plugins/**',
          dest: 'tinymce/plugins',
          rename: { stripBase: 3 },
        },
        {
          src: 'node_modules/tinymce/skins/**',
          dest: 'tinymce/skins',
          rename: { stripBase: 3 },
        },
        {
          src: 'node_modules/tinymce/themes/**',
          dest: 'tinymce/themes',
          rename: { stripBase: 3 },
        },
        {
          src: 'node_modules/tinymce/tinymce.min.js',
          dest: 'tinymce',
        },
      ],
    }),
    // Trong dev mode: Vite serve các file TinyMCE từ /tinymce/* thông qua alias.
    {
      name: 'tinymce-dev-assets',
      configureServer(server) {
        // Map URL /tinymce/* → node_modules/tinymce/*
        server.middlewares.use('/tinymce', (req, res, next) => {
          const subpath = req.url || ''
          const filename = path.basename(subpath.split('?')[0] || '')
          // Resolve trong node_modules
          const tinyDir = path.resolve(process.cwd(), 'node_modules/tinymce')
          // Construct target file
          let target: string
          if (filename && filename !== '' && filename !== '/') {
            target = path.join(tinyDir, subpath.replace(/^\//, ''))
          } else {
            target = path.join(tinyDir, 'tinymce.min.js')
          }
          // Serve file nếu tồn tại
          try {
            const fs = require('node:fs') as typeof import('node:fs')
            if (fs.existsSync(target) && fs.statSync(target).isFile()) {
              const ext = path.extname(target).toLowerCase()
              const mime: Record<string, string> = {
                '.js': 'application/javascript',
                '.css': 'text/css',
                '.json': 'application/json',
                '.svg': 'image/svg+xml',
                '.woff': 'font/woff',
                '.woff2': 'font/woff2',
                '.ttf': 'font/ttf',
              }
              res.setHeader('Content-Type', mime[ext] || 'application/octet-stream')
              res.setHeader('Cache-Control', 'public, max-age=3600')
              fs.createReadStream(target).pipe(res)
              return
            }
          } catch {
            // fallthrough
          }
          next()
        })
      },
    },
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    proxy: {
      // Keep the chat request off the single-threaded business API process.
      // The AI service can then call :8000 while :8002 waits for its reply.
      '/api/chatbot': {
        target: 'http://127.0.0.1:8002',
        changeOrigin: true,
      },
      // Proxy /api/* tới Laravel backend (php artisan serve)
      '/api': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
      // Proxy /storage/* (ảnh do Laravel serve) — tránh hardcode 127.0.0.1:8000
      '/storage': {
        target: 'http://127.0.0.1:8000',
        changeOrigin: true,
      },
    },
  },
})
