import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

export default defineConfig({
  server: { proxy: { '/api': process.env.VITE_PROXY_TARGET || 'http://127.0.0.1:8000' } },
  plugins: [react(), tailwindcss()],
})
