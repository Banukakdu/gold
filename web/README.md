# Account-gold web (Angular 18)

`npm install` then `npx ng serve` (http://localhost:4200). `/api` is proxied to the Laravel API on :8000 (see `proxy.conf.json`).
For production, set `apiUrl` in `src/environments/environment.ts` or serve the built app from the same domain as the API.

- `src/app/core` session (`AuthService`), HTTP interceptor (token + `X-Branch-Id`), guards, money pipe
- `src/app/layout` sidebar + branch switcher
- `src/app/features/*` one folder per module
