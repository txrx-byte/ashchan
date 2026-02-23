#!/usr/bin/env node
/**
 * WebSocket liveposting load test harness.
 *
 * Simulates multiple concurrent clients connecting to the liveposting
 * WebSocket endpoint, synchronising to a thread, creating open posts,
 * and streaming characters.
 *
 * Usage:
 *   node tools/ws-load-test.mjs [options]
 *
 * Options:
 *   --url       WebSocket URL       (default: ws://127.0.0.1:9501/api/socket)
 *   --clients   Number of clients   (default: 50)
 *   --thread    Thread ID to sync   (default: 1)
 *   --board     Board slug          (default: a)
 *   --duration  Test duration in ms (default: 30000)
 *   --rate      Chars per second    (default: 5)
 *   --verbose   Show per-client log (default: false)
 *
 * Prerequisites:
 *   npm install ws   (or use Node 22+ with built-in WebSocket)
 *
 * Copyright 2026 txrx-byte — Apache-2.0
 *
 * @see docs/LIVEPOSTING.md §14
 */

import { WebSocket } from 'ws';

// ---------------------------------------------------------------------------
// CLI argument parsing
// ---------------------------------------------------------------------------
const args = Object.fromEntries(
  process.argv.slice(2).map((a) => {
    const [k, v] = a.replace(/^--/, '').split('=');
    return [k, v ?? 'true'];
  }),
);

const WS_URL   = args.url      || 'ws://127.0.0.1:9501/api/socket';
const CLIENTS  = parseInt(args.clients  || '50', 10);
const THREAD   = parseInt(args.thread   || '1', 10);
const BOARD    = args.board    || 'a';
const DURATION = parseInt(args.duration || '30000', 10);
const CHAR_RATE = parseInt(args.rate    || '5', 10);
const VERBOSE  = args.verbose === 'true';

// Protocol constants
const TYPE_APPEND   = 0x02;
const TYPE_SYNC     = 30;  // text type
const TYPE_INSERT   = 1;   // text type
const TYPE_CLOSE    = 5;   // text type

// Metrics
let totalConnected   = 0;
let totalSynced      = 0;
let totalPostCreated = 0;
let totalCharsTyped  = 0;
let totalErrors      = 0;
let totalClosed      = 0;
let messagesReceived = 0;
let bytesReceived    = 0;

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

/**
 * Encode a text message in the ashchan protocol format.
 * 2-char zero-padded type prefix + JSON body.
 */
function encodeText(type, payload) {
  const prefix = String(type).padStart(2, '0');
  return prefix + JSON.stringify(payload);
}

/**
 * Encode a binary append frame.
 * C→S: [char:utf8][0x02]
 */
function encodeAppend(char) {
  const charBuf = Buffer.from(char, 'utf8');
  const frame = Buffer.alloc(charBuf.length + 1);
  charBuf.copy(frame, 0);
  frame[frame.length - 1] = TYPE_APPEND;
  return frame;
}

/**
 * Random alphanumeric string.
 */
function randomString(len) {
  const chars = 'abcdefghijklmnopqrstuvwxyz0123456789 ';
  let s = '';
  for (let i = 0; i < len; i++) {
    s += chars[Math.floor(Math.random() * chars.length)];
  }
  return s;
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

// ---------------------------------------------------------------------------
// Client simulation
// ---------------------------------------------------------------------------

async function runClient(id) {
  return new Promise((resolve) => {
    const label = `[client-${id}]`;
    const log = VERBOSE ? (...a) => console.log(label, ...a) : () => {};

    let ws;
    try {
      ws = new WebSocket(WS_URL, 'ashchan-v1');
    } catch (err) {
      totalErrors++;
      log('Connection error:', err.message);
      resolve();
      return;
    }

    let charTimer = null;
    let postId = null;
    let charsTyped = 0;

    const cleanup = () => {
      if (charTimer) clearInterval(charTimer);
      if (ws.readyState === WebSocket.OPEN) ws.close();
      totalClosed++;
      resolve();
    };

    ws.on('open', () => {
      totalConnected++;
      log('Connected');

      // Send sync message
      const syncMsg = encodeText(TYPE_SYNC, { thread: THREAD, board: BOARD });
      ws.send(syncMsg);
      log('Sent sync');
    });

    ws.on('message', (data, isBinary) => {
      messagesReceived++;
      if (isBinary) {
        bytesReceived += data.length;
        return;
      }

      const str = data.toString();
      bytesReceived += str.length;
      const type = parseInt(str.substring(0, 2), 10);
      let payload;
      try {
        payload = JSON.parse(str.substring(2));
      } catch {
        return;
      }

      // Handle sync response (type 30)
      if (type === TYPE_SYNC) {
        totalSynced++;
        log('Synced, active IPs:', payload.active);

        // After sync, create an open post
        setTimeout(() => {
          if (ws.readyState !== WebSocket.OPEN) return;
          const insertMsg = encodeText(TYPE_INSERT, {
            name: `loadtest-${id}`,
            password: `pass-${id}`,
          });
          ws.send(insertMsg);
          log('Sent InsertPost');
        }, 100 + Math.random() * 500);
      }

      // Handle PostID response (type 32)
      if (type === 32) {
        postId = payload.id;
        totalPostCreated++;
        log('Got post ID:', postId);

        // Start streaming characters
        const intervalMs = Math.round(1000 / CHAR_RATE);
        charTimer = setInterval(() => {
          if (ws.readyState !== WebSocket.OPEN) {
            clearInterval(charTimer);
            return;
          }
          const char = randomString(1);
          ws.send(encodeAppend(char));
          charsTyped++;
          totalCharsTyped++;
        }, intervalMs);
      }

      // Handle captcha required (type 38)
      if (type === 38) {
        log('Captcha required — spam threshold hit');
        totalErrors++;
      }

      // Handle errors (type 00)
      if (type === 0 && payload.error) {
        log('Error:', payload.error);
        totalErrors++;
      }
    });

    ws.on('error', (err) => {
      log('WS error:', err.message);
      totalErrors++;
      cleanup();
    });

    ws.on('close', () => {
      log('Disconnected');
      cleanup();
    });

    // Auto-close after duration
    setTimeout(() => {
      if (postId && ws.readyState === WebSocket.OPEN) {
        // Close the open post before disconnecting
        ws.send(encodeText(TYPE_CLOSE, {}));
        setTimeout(cleanup, 200);
      } else {
        cleanup();
      }
    }, DURATION);
  });
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

async function main() {
  console.log('=== Ashchan WebSocket Load Test ===');
  console.log(`URL:       ${WS_URL}`);
  console.log(`Clients:   ${CLIENTS}`);
  console.log(`Thread:    ${THREAD} (board: ${BOARD})`);
  console.log(`Duration:  ${DURATION}ms`);
  console.log(`Char rate: ${CHAR_RATE}/s per client`);
  console.log('');

  const startTime = Date.now();

  // Stagger client connections to avoid thundering herd
  const staggerMs = Math.min(100, DURATION / CLIENTS / 2);
  const promises = [];

  for (let i = 0; i < CLIENTS; i++) {
    promises.push(runClient(i));
    if (i < CLIENTS - 1) {
      await sleep(staggerMs);
    }
  }

  // Print metrics every 5 seconds
  const metricsTimer = setInterval(() => {
    const elapsed = ((Date.now() - startTime) / 1000).toFixed(1);
    console.log(
      `[${elapsed}s] connected=${totalConnected} synced=${totalSynced} ` +
      `posts=${totalPostCreated} chars=${totalCharsTyped} ` +
      `msgs_rx=${messagesReceived} errors=${totalErrors}`,
    );
  }, 5000);

  await Promise.all(promises);
  clearInterval(metricsTimer);

  const elapsed = ((Date.now() - startTime) / 1000).toFixed(2);
  console.log('');
  console.log('=== Results ===');
  console.log(`Duration:          ${elapsed}s`);
  console.log(`Total connected:   ${totalConnected}`);
  console.log(`Total synced:      ${totalSynced}`);
  console.log(`Posts created:     ${totalPostCreated}`);
  console.log(`Chars typed:       ${totalCharsTyped}`);
  console.log(`Messages received: ${messagesReceived}`);
  console.log(`Bytes received:    ${(bytesReceived / 1024).toFixed(1)} KB`);
  console.log(`Errors:            ${totalErrors}`);
  console.log(`Closed cleanly:    ${totalClosed}`);
  console.log(`Chars/sec:         ${(totalCharsTyped / parseFloat(elapsed)).toFixed(1)}`);
  console.log(`Msgs/sec (rx):     ${(messagesReceived / parseFloat(elapsed)).toFixed(1)}`);
}

main().catch((err) => {
  console.error('Fatal:', err);
  process.exit(1);
});
