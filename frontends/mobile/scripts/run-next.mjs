import net from 'node:net';
import { spawn } from 'node:child_process';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '..');
const nextBin = path.join(projectRoot, 'node_modules', 'next', 'dist', 'bin', 'next');

const command = process.argv[2] ?? 'dev';
const explicitPort = normalizePort(process.env.PORT);
const preferredPort = explicitPort ?? 3001;
const allowFallback = command === 'dev' && explicitPort === null;

function normalizePort(value) {
  if (!value) {
    return null;
  }

  const port = Number.parseInt(value, 10);
  return Number.isInteger(port) && port > 0 ? port : null;
}

async function isPortAvailable(port) {
  const hosts = ['127.0.0.1', '::'];

  for (const host of hosts) {
    const available = await isPortAvailableOnHost(port, host);

    if (!available) {
      return false;
    }
  }

  return true;
}

function isPortAvailableOnHost(port, host) {
  return new Promise((resolve, reject) => {
    const server = net.createServer();

    server.once('error', (error) => {
      if (error && (error.code === 'EADDRINUSE' || error.code === 'EACCES')) {
        resolve(false);
        return;
      }

      if (error && (error.code === 'EADDRNOTAVAIL' || error.code === 'EAFNOSUPPORT')) {
        resolve(true);
        return;
      }

      reject(error);
    });

    server.once('listening', () => {
      server.close(() => resolve(true));
    });

    // Mirror the way Next binds locally so conflicts are detected before boot.
    server.listen({ port, host });
  });
}

async function resolvePort(startPort) {
  if (!allowFallback) {
    return (await isPortAvailable(startPort)) ? startPort : null;
  }

  for (let port = startPort; port <= startPort + 9; port += 1) {
    if (await isPortAvailable(port)) {
      return port;
    }
  }

  return null;
}

function runNext(port) {
  const child = spawn(process.execPath, [nextBin, command, '-p', String(port)], {
    cwd: projectRoot,
    stdio: 'inherit',
    env: {
      ...process.env,
      PORT: String(port),
    },
  });

  child.on('error', (error) => {
    console.error(`[mobile] Falha ao iniciar o Next: ${error.message}`);
    process.exit(1);
  });

  child.on('exit', (code, signal) => {
    if (signal) {
      process.kill(process.pid, signal);
      return;
    }

    process.exit(code ?? 0);
  });
}

const port = await resolvePort(preferredPort);

if (port === null) {
  console.error(
    `[mobile] A porta ${preferredPort} nao esta disponivel. ` +
      `Defina PORT para outra porta livre ou libere a ${preferredPort}.`
  );
  process.exit(1);
}

if (port !== preferredPort) {
  console.warn(
    `[mobile] Porta ${preferredPort} ocupada. Iniciando o Next em http://127.0.0.1:${port}.`
  );
} else {
  console.log(`[mobile] Iniciando o Next em http://127.0.0.1:${port}.`);
}

runNext(port);
