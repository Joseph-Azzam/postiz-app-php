#!/usr/bin/env node
/**
 * Root environment loader for Postiz monorepo
 *
 * Loads .env from project root, then overlays .env.local or .env.production
 * based on APP_ENV (set in .env). Use for Next.js frontend and any Node scripts.
 *
 * Usage: node scripts/load-env.js [--cwd <dir>] -- <command> [args...]
 * Example: node scripts/load-env.js -- next dev -p 4200
 *
 * If --cwd is given, changes to that directory before running the command.
 */

const { spawn } = require('child_process');
const path = require('path');
const fs = require('fs');

const ROOT = path.resolve(__dirname, '..');

function loadEnvFile(filePath) {
  const fullPath = path.join(ROOT, filePath);
  if (!fs.existsSync(fullPath)) return false;
  try {
    require('dotenv').config({ path: fullPath, override: true });
    return true;
  } catch {
    return false;
  }
}

// Parse args: optional --cwd <dir>, then -- <command> [args]
let cwd = ROOT;
let cmdStart = 2;
if (process.argv[2] === '--cwd' && process.argv[3]) {
  cwd = path.resolve(ROOT, process.argv[3]);
  cmdStart = 4;
}
if (process.argv[cmdStart] === '--') cmdStart++;

const command = process.argv[cmdStart];
const args = process.argv.slice(cmdStart + 1);

if (!command) {
  console.error('Usage: node scripts/load-env.js [--cwd <dir>] -- <command> [args...]');
  process.exit(1);
}

// 1) Load base .env
if (!loadEnvFile('.env')) {
  console.warn('[load-env] No .env found at root, proceeding with existing env');
}

// 2) Overlay based on APP_ENV
const appEnv = (process.env.APP_ENV || 'local').toLowerCase();
if (appEnv === 'local' && loadEnvFile('.env.local')) {
  // loaded
} else if (appEnv === 'production' && loadEnvFile('.env.production')) {
  // loaded
}

// 3) Run command - prepend node dir + node_modules/.bin to PATH so npx, next, etc. are found
const delim = process.platform === 'win32' ? ';' : ':';
const nodeDir = path.dirname(process.execPath);
const binDirs = [
  nodeDir,
  path.join(cwd, 'node_modules', '.bin'),
  path.join(ROOT, 'node_modules', '.bin'),
].filter((d) => fs.existsSync(d));
const env = { ...process.env };
if (binDirs.length > 0) {
  env.PATH = `${binDirs.join(delim)}${delim}${env.PATH || ''}`;
}

let runCommand = command;
let runArgs = args;
// When 'next' is the command, run via node (pnpm workspace may not have next in PATH)
if (command === 'next') {
  const nextBins = [
    path.join(ROOT, 'node_modules', 'next', 'dist', 'bin', 'next'),
    path.join(cwd, 'node_modules', 'next', 'dist', 'bin', 'next'),
    path.join(ROOT, 'node_modules', '.pnpm', 'node_modules', 'next', 'dist', 'bin', 'next'),
  ];
  const nextBin = nextBins.find((p) => fs.existsSync(p));
  if (nextBin) {
    runCommand = process.execPath;
    runArgs = [nextBin, ...args];
  }
}

const isWindows = process.platform === 'win32';
const child = spawn(runCommand, runArgs, {
  cwd,
  env,
  stdio: 'inherit',
  shell: isWindows,
});
child.on('exit', (code) => process.exit(code ?? 0));
