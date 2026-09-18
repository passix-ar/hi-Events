/// <reference types="vitest/config" />
import {defineConfig} from "vite";
import {lingui} from "@lingui/vite-plugin";
import react from "@vitejs/plugin-react";
import {copy} from "vite-plugin-copy";
import {existsSync, readFileSync} from "fs";
import {resolve} from "path";

function getVersion(): string {
    const candidates = [
        resolve(__dirname, "../VERSION"),
        resolve(__dirname, "../../VERSION"),
        "/app/VERSION",
    ];
    for (const path of candidates) {
        if (existsSync(path)) {
            return readFileSync(path, "utf-8").trim();
        }
    }
    return "unknown";
}

export default defineConfig({
    // The scanner's decisions were pulled out of their hooks and components into plain functions
    // precisely so they could be tested without a browser: node, no jsdom, no DOM globals. A test
    // that needs any of those is a sign the logic is still tangled with the UI.
    test: {
        environment: "node",
        include: ["src/**/*.test.ts"],
    },
    optimizeDeps: {
        include: ["react-router"]
    },
    server: {
        hmr: {
            port: 24678,
            protocol: "ws",
        },
    },
    plugins: [
        react({
            babel: {
                plugins: ["macros"],
            },
        }),
        lingui(),
        copy({
            targets: [{src: "src/embed/widget.js", dest: "public"}],
            hook: "writeBundle",
        }),
    ],
    define: {
        "process.env": process.env,
        "__APP_VERSION__": JSON.stringify(getVersion()),
    },
    ssr: {
        noExternal: ["react-helmet-async"],
    },
    css: {
        preprocessorOptions: {
            scss: {
                api: "modern-compiler",
            }
        }
    }
});
