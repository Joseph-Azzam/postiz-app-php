/**
 * Backend URL Adapter - Postiz Laravel migration
 *
 * WHY: Enables switching between NestJS (original Postiz) and Laravel backends
 * via NEXT_PUBLIC_BACKEND_TYPE env var without modifying components.
 *
 * WHAT: Resolves the correct API base URL for client-side fetch and
 * server-side internalFetch based on backend type.
 *
 * ENV VARS:
 * - NEXT_PUBLIC_BACKEND_TYPE: 'laravel' | 'nestjs' (default: nestjs)
 * - NEXT_PUBLIC_BACKEND_URL: NestJS backend base URL
 * - NEXT_PUBLIC_LARAVEL_BACKEND_URL: Laravel API base URL (e.g. .../api)
 * - BACKEND_INTERNAL_URL: Server-side NestJS URL (for internal fetch)
 * - BACKEND_INTERNAL_LARAVEL_URL: Server-side Laravel URL (optional, falls back to NEXT_PUBLIC_LARAVEL_BACKEND_URL)
 * end of change
 */

export type BackendType = 'laravel' | 'nestjs';

export function getBackendType(): BackendType {
  const type = process.env.NEXT_PUBLIC_BACKEND_TYPE?.toLowerCase();
  if (type === 'laravel' || type === 'nestjs') {
    return type;
  }
  return 'nestjs';
}

/**
 * Returns the client-side API base URL for use with FetchWrapperComponent.
 * Used by VariableContextComponent and LayoutContext.
 */
export function getBackendUrl(): string {
  const type = getBackendType();
  if (type === 'laravel') {
    const url = process.env.NEXT_PUBLIC_LARAVEL_BACKEND_URL;
    if (!url) {
      console.warn(
        '[backend-url] NEXT_PUBLIC_BACKEND_TYPE=laravel but NEXT_PUBLIC_LARAVEL_BACKEND_URL is not set. Falling back to NEXT_PUBLIC_BACKEND_URL.'
      );
      return process.env.NEXT_PUBLIC_BACKEND_URL || process.env.BACKEND_URL || '';
    }
    return url;
  }
  return (
    process.env.NEXT_PUBLIC_BACKEND_URL || process.env.BACKEND_URL || ''
  );
}

/**
 * Returns the server-side API base URL for internalFetch (middleware, SSR).
 * Uses internal URLs when available for server-to-server calls.
 */
export function getBackendInternalUrl(): string {
  const type = getBackendType();
  if (type === 'laravel') {
    return (
      process.env.BACKEND_INTERNAL_LARAVEL_URL ||
      process.env.NEXT_PUBLIC_LARAVEL_BACKEND_URL ||
      process.env.NEXT_PUBLIC_BACKEND_URL ||
      ''
    );
  }
  return process.env.BACKEND_INTERNAL_URL || process.env.BACKEND_URL || '';
}
