export const DEFAULT_WP_LOGIN_PATH = '/wp-login.php';

export function getWpLoginPath(): string {
  return process.env.WP_LOGIN_PATH ?? DEFAULT_WP_LOGIN_PATH;
}
