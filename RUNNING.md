# Running FUEL on your computer

## 1. Try the prototype (mock data, no backend)
Needs Node 22+.

    cd web-prototype
    npm install
    npm run dev        # http://localhost:5173, also on your LAN (--host)

Phone on the same Wi-Fi: open `http://<computer-ip>:5173`. Install-as-app and the camera need HTTPS, so for the real PWA install test use the production build behind HTTPS (M0 deploy) or `npm run build && npx vite preview`.

Everything on screen is mock data except the engine plans (generated from the PHP engine) and the goal maths. Weights, photos and logs reset or stay on-device only.

## 2. Engine tests (PHP 8.3+)

    php tests/Engine/run.php            # must pass before every commit
    cd web-prototype
    npx tsx src/goal.test.ts            # TS port parity with PHP
    npx tsx src/lib/outbox.test.ts
    npx tsx src/lib/photos.test.ts
    npx tsx src/lib/nutrients.test.ts
    npx tsx src/lib/health.test.ts
    npx tsx src/lib/quickview.test.ts

After changing any PHP engine file: `php tools/export-engine-data.php` (rewrites `web-prototype/src/engine-data.json`).

## 3. Build the real app (M0 then M1)
Needs on your machine: PHP 8.3+, Composer 2, Node 22+, Docker. Open Claude Code in the repo root and give it exactly:

> Read BLUEPRINT.md and CLAUDE.md fully. Do M0 and M1 only, then stop.

Review, then ask for M2, and so on. One milestone at a time.

## 4. Known placeholders (do not trust yet)
- Food data in the prototype is mock, not AFCD.
- Nutrients tab intake is sample data. Only iron and zinc targets are verified NHMRC; the rest are US reference values (see `src/lib/nutrients.ts`).
- HIT lifting energy (MET 3.5) is an estimate. Muscle-gain figures are assumptions, not measurements.
- Progress photos live in this browser only; the real app stores them on your server.
