#!/usr/bin/env node
/* eslint-disable no-console */
import { promises as fs } from 'fs';
import path from 'path';
import fg from 'fast-glob';
import { build } from 'esbuild';

const srcRoot = path.resolve('assets/js');
const outRoot = path.resolve('assets/js/dist');

async function ensureDir(dir) {
  await fs.mkdir(dir, { recursive: true });
}

async function run() {
  const entries = await fg('**/*.js', {
    cwd: srcRoot,
    ignore: ['dist/**', '**/*.min.js', '**/vendor/**'],
  });

  if (entries.length === 0) {
    console.log('No JS files found to build.');
    return;
  }

  await ensureDir(outRoot);

  await Promise.all(
    entries.map(async (entry) => {
      const absEntry = path.join(srcRoot, entry);
      const outDir = path.join(outRoot, path.dirname(entry));
      await ensureDir(outDir);

      await build({
        entryPoints: [absEntry],
        outfile: path.join(outDir, path.basename(entry)),
        bundle: false,
        minify: true,
        sourcemap: true,
        target: 'es2019',
        format: 'esm',
        logLevel: 'info',
      });
    })
  );

  console.log(`Built ${entries.length} file(s) to ${path.relative(process.cwd(), outRoot)}`);
}

run().catch((error) => {
  console.error(error);
  process.exitCode = 1;
});
