import tailwind from '@tailwindcss/vite'
import { defineConfig } from 'vite'

const api = { target: 'http://127.0.0.1:8000' }
const proxy = { '/api': api, '/sanctum': api }

export default defineConfig({
  plugins: [tailwind()],
  server: { host: 'localhost', port: 5173, strictPort: true, proxy },
  preview: { host: 'localhost', port: 5173, strictPort: true, proxy },
})
