'use client';

/**
 * Postiz Laravel migration: one-time test session redirect.
 * WHY: When /test is opened on the backend origin, the auth cookie may not be sent
 * (frontend and backend on different origins). This page runs on the frontend origin,
 * calls the backend with the auth cookie (sent as header by the app fetch), gets
 * a one-time /test?session=… URL, and redirects so "Post on X" runs as the logged-in user.
 * end of change
 */
import { useVariables } from '@gitroom/react/helpers/variable.context';
import { useSearchParams } from 'next/navigation';
import { useEffect, useState } from 'react';

function getAuthFromCookie(): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie
    .split(';')
    .find((p) => p.trim().startsWith('auth='));
  return match ? match.split('=')[1]?.trim() ?? null : null;
}

export default function TestSessionPage() {
  const { backendUrl } = useVariables();
  const searchParams = useSearchParams();
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const auth = getAuthFromCookie();
    if (!auth) {
      setError('Not logged in. Log in to Postiz first, then open this page.');
      return;
    }
    const params = new URLSearchParams();
    const suite = searchParams.get('suite');
    if (suite) params.set('suite', suite);
    const noAi = searchParams.get('no_ai');
    if (noAi) params.set('no_ai', noAi);
    const testImage = searchParams.get('test_image');
    if (testImage) params.set('test_image', testImage);
    const postOnX = searchParams.get('post_on_x');
    if (postOnX) params.set('post_on_x', postOnX);
    const postOnLinkedin = searchParams.get('post_on_linkedin');
    if (postOnLinkedin) params.set('post_on_linkedin', postOnLinkedin);
    const postOnBluesky = searchParams.get('post_on_bluesky');
    if (postOnBluesky) params.set('post_on_bluesky', postOnBluesky);
    const q = params.toString();
    const url =
      backendUrl +
      '/test/session-token' +
      (q ? '?' + q : '');
    fetch(url, {
      method: 'GET',
      headers: { Accept: 'application/json', auth: auth },
      credentials: 'include',
    })
      .then((res) => {
        if (!res.ok) {
          if (res.status === 401) {
            setError('Not authenticated. Log in to Postiz first.');
            return;
          }
          throw new Error(`Request failed: ${res.status}`);
        }
        return res.json();
      })
      .then((data: { url?: string }) => {
        if (data?.url) {
          window.location.href = data.url;
        } else {
          setError('Invalid response from server.');
        }
      })
      .catch((err) => {
        setError(err?.message ?? 'Failed to get test link.');
      });
  }, [backendUrl, searchParams]);

  if (error) {
    return (
      <div style={{ padding: '2rem', maxWidth: '400px', margin: '0 auto' }}>
        <p style={{ color: 'var(--new-btn-text, #fff)' }}>{error}</p>
        <a href="/" style={{ color: 'var(--new-btn-text)' }}>
          Back to app
        </a>
      </div>
    );
  }

  return (
    <div style={{ padding: '2rem', textAlign: 'center' }}>
      <p style={{ color: 'var(--new-btn-text, #fff)' }}>
        Redirecting to test dashboard with your session…
      </p>
    </div>
  );
}
