import { cookies } from 'next/headers';
import { customFetch } from '@gitroom/helpers/utils/custom.fetch.func';
import { getBackendInternalUrl } from '@gitroom/helpers/utils/backend-url';

export const internalFetch = (url: string, options: RequestInit = {}) =>
  customFetch(
    { baseUrl: getBackendInternalUrl() },
    cookies()?.get('auth')?.value!,
    cookies()?.get('showorg')?.value!
  )(url, options);
