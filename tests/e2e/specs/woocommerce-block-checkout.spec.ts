import { expect, test } from '../fixtures/wp-fixture';
import {
  activatePlugins,
  deactivatePluginsExcept,
  listActivePluginFiles,
  restoreActivePluginFiles,
  wpEval,
} from '../utils/wp-env';

test.describe('WooCommerce block checkout strictly-necessary configuration', () => {
  test.describe.configure({ mode: 'serial', timeout: 180_000 });

  let initialActivePluginFiles: string[] = [];
  let optionSnapshot = '';
  let checkoutUrl = '';
  let checkoutSlug = '';

  test.beforeAll(async ({ browser: _browser }, testInfo) => {
    testInfo.setTimeout(180_000);
    initialActivePluginFiles = listActivePluginFiles();
    deactivatePluginsExcept(['faz-cookie-manager', 'woocommerce']);
    activatePlugins(['woocommerce']);
    checkoutSlug = `faz-e2e-block-checkout-${process.pid}-${Date.now()}`;

    optionSnapshot = wpEval(`
      echo wp_json_encode(
        array(
          'fazSettings'   => get_option( 'faz_settings', array() ),
          'guestCheckout' => get_option( 'woocommerce_enable_guest_checkout', null ),
        )
      );
    `);

    checkoutUrl = wpEval(`
      $existing = get_page_by_path( '${checkoutSlug}', OBJECT, 'page' );
      $post_id  = $existing instanceof WP_Post ? (int) $existing->ID : wp_insert_post(
        array(
          'post_type'    => 'page',
          'post_status'  => 'publish',
          'post_title'   => 'FAZ E2E Block Checkout',
          'post_name'    => '${checkoutSlug}',
          'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
        )
      );
      if ( is_wp_error( $post_id ) || ! $post_id ) {
        throw new RuntimeException( 'Could not create the WooCommerce block checkout fixture.' );
      }

      update_option( 'woocommerce_enable_guest_checkout', 'yes', false );

      $settings = get_option( 'faz_settings', array() );
      if ( ! is_array( $settings ) ) {
        $settings = array();
      }
      if ( ! isset( $settings['banner_control'] ) || ! is_array( $settings['banner_control'] ) ) {
        $settings['banner_control'] = array();
      }
      if ( ! isset( $settings['script_blocking'] ) || ! is_array( $settings['script_blocking'] ) ) {
        $settings['script_blocking'] = array();
      }
      $settings['banner_control']['status']                    = true;
      $settings['script_blocking']['whitelist_patterns']       = array();
      $settings['script_blocking']['custom_rules']             = array(
        array( 'pattern' => 'wc-settings', 'category' => 'marketing' ),
        array( 'pattern' => 'wc-blocks-middleware', 'category' => 'marketing' ),
        array( 'pattern' => 'wc-mini-cart-block-frontend', 'category' => 'marketing' ),
      );
      update_option( 'faz_settings', $settings, false );

      if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
        \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
      }
      clean_post_cache( (int) $post_id );
      wp_cache_flush();
      flush_rewrite_rules( false );
      echo esc_url_raw( get_permalink( (int) $post_id ) );
    `);
  });

  test.afterAll(async ({ browser: _browser }, testInfo) => {
    testInfo.setTimeout(180_000);
    if ( optionSnapshot ) {
      const encoded = Buffer.from(optionSnapshot, 'utf8').toString('base64');
      wpEval(`
        $snapshot = json_decode( base64_decode( '${encoded}' ), true );
        if ( is_array( $snapshot ) ) {
          update_option( 'faz_settings', $snapshot['fazSettings'], false );
          if ( null === $snapshot['guestCheckout'] ) {
            delete_option( 'woocommerce_enable_guest_checkout' );
          } else {
            update_option( 'woocommerce_enable_guest_checkout', $snapshot['guestCheckout'], false );
          }
        }
        $fixture = get_page_by_path( '${checkoutSlug}', OBJECT, 'page' );
        if ( $fixture instanceof WP_Post ) {
          wp_delete_post( (int) $fixture->ID, true );
        }
        if ( class_exists( '\\FazCookie\\Includes\\Cache' ) ) {
          \\FazCookie\\Includes\\Cache::invalidate_cache_group( 'settings' );
        }
      `);
    }
    restoreActivePluginFiles(initialActivePluginFiles);
  });

  test('wc-settings-js-before executes before consent even when classified as marketing', async ({ browser }) => {
    test.setTimeout(180_000);
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      await page.goto(new URL('/?add-to-cart=48', checkoutUrl).toString(), { waitUntil: 'domcontentloaded' });
      await expect
        .poll(async () => {
          return page.evaluate(async () => {
            const cart = (await fetch('/wp-json/wc/store/v1/cart').then((result) => result.json())) as {
              items_count?: number;
            };
            return cart.items_count ?? 0;
          });
        })
        .toBeGreaterThan(0);

      let source = '';
      await page.route(checkoutUrl, async (route) => {
        const upstream = await route.fetch();
        source = await upstream.text();
        await route.fulfill({ response: upstream, body: source });
      });
      const response = await page.goto(checkoutUrl, { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response!.status()).toBe(200);
      expect(new URL(page.url()).pathname).toBe(new URL(checkoutUrl).pathname);
      expect(source, 'checkout navigation was not captured').not.toBe('');
      const tagForId = (id: string): string => {
        const escaped = id.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        return source.match(new RegExp(`<script\\b[^>]*\\bid=["']${escaped}["'][^>]*>`, 'i'))?.[0] ?? '';
      };
      const requiredTagIds = [
        'wc-settings-js-before',
        'wc-settings-js',
        'wc-blocks-middleware-js-before',
        'wc-blocks-middleware-js',
      ];

      const state = await page.evaluate(() => {
        const script = document.querySelector<HTMLScriptElement>('#wc-settings-js-before');
        const globals = window as Window & {
          wcSettings?: Record<string, unknown>;
          wcBlocksMiddlewareConfig?: Record<string, unknown>;
        };
        const settings = globals.wcSettings;
        return {
          scriptPresent: script !== null,
          scriptType: script?.getAttribute('type') ?? '',
          fazCategory: script?.getAttribute('data-faz-category') ?? '',
          settingsPresent: typeof settings === 'object' && settings !== null,
          guestCheckout: settings?.checkoutAllowsGuest,
          middlewarePresent:
            typeof globals.wcBlocksMiddlewareConfig === 'object' && globals.wcBlocksMiddlewareConfig !== null,
          renderedText: document.body.innerText,
        };
      });

      for (const id of requiredTagIds) {
        const tag = tagForId(id);
        expect(tag, `${id} was not rendered by the checkout fixture`).not.toBe('');
        expect(tag, `${id} was neutralised`).not.toMatch(/\btype=["']text\/plain["']/i);
        expect(tag, `${id} was assigned a consent category`).not.toMatch(/\bdata-faz-category=/i);
      }
      expect(state.scriptPresent).toBe(true);
      expect(state.scriptType).not.toBe('text/plain');
      expect(state.fazCategory).toBe('');
      expect(state.settingsPresent).toBe(true);
      expect(state.guestCheckout).toBe(true);
      expect(state.middlewarePresent).toBe(true);
      expect(state.renderedText).not.toMatch(/you must be logged in to checkout/i);
    } finally {
      await context.close();
    }
  });
});
