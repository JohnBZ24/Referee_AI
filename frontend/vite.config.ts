import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    proxy: {
      // Laravel API (and SSE streaming) during local dev.
      "/api": {
        // Use Herd site by default.
        target: "http://backend.test",
        changeOrigin: true,
        secure: false,
      },
      // If Sanctum endpoints are ever re-enabled.
      "/sanctum": {
        target: "http://backend.test",
        changeOrigin: true,
        secure: false,
      },
    },
  },
});
