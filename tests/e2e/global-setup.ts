import { request } from '@playwright/test';
import { getWpLoginPath } from './utils/wp-auth';

async function globalSetup(): Promise<void> {
  const baseURL = process.env.WP_BASE_URL ?? 'http://localhost:9998';
  const adminUser = process.env.WP_ADMIN_USER ?? 'admin';
  const adminPass = process.env.WP_ADMIN_PASS ?? 'admin';

  const api = await request.newContext({
    baseURL,
    ignoreHTTPSErrors: true,
  });

  const loginPath = getWpLoginPath();
  const loginPage = await api.get(loginPath);
  if (!loginPage.ok()) {
    await api.dispose();
    throw new Error(`WordPress login page not reachable at ${baseURL}${loginPath} (status ${loginPage.status()}).`);
  }

  // Verify credentials actually work before running the full suite.
  const loginResponse = await api.post(loginPath, {
    form: {
      log: adminUser,
      pwd: adminPass,
      'wp-submit': 'Log In',
      redirect_to: '/wp-admin/',
      testcookie: '1',
    },
  });
  if (!loginResponse.url().includes('/wp-admin')) {
    await api.dispose();
    throw new Error(`WordPress login failed for user '${adminUser}' at ${baseURL}${loginPath}. Check WP_ADMIN_USER/WP_ADMIN_PASS.`);
  }

  await api.dispose();
}

export default globalSetup;
