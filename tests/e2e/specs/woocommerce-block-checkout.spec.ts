import { expect, test } from '../fixtures/wp-fixture';
import {
  activatePlugins,
  deactivatePluginsExcept,
  ensureFixturePlugin,
  listActivePluginFiles,
  restoreActivePluginFiles,
  wpEval,
} from '../utils/wp-env';

/**
 * Extract the opening `<script>` tag carrying a given id from raw page source.
 */
function tagWithAttribute(source: string, attribute: string, value: string): string {
  const escaped = value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  return (
    source.match(new RegExp(`<script\\b[^>]*\\b${attribute}=["']${escaped}["'][^>]*>`, 'i'))?.[0] ?? ''
  );
}

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
    // Emits the strictly-necessary look-alikes the negative assertion needs.
    ensureFixturePlugin('faz-e2e-woo-lab');
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
      update_option( 'faz_e2e_woo_lab_lookalike', 'yes', false );

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
        delete_option( 'faz_e2e_woo_lab_lookalike' );
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

  // The exemption above must stay an EXACT-identity exemption. A look-alike is
  // not a WordPress-generated identity for one of the three real handles, so it
  // carries no strictly-necessary guarantee and must still be gated by consent.
  // Without this assertion the exemption can be silently widened into a
  // token-prefix whitelist entry (matched against id and class, not just src)
  // while the positive assertion above stays green.
  test('wc-settings look-alike scripts stay blocked before consent', async ({ browser }) => {
    test.setTimeout(180_000);
    const context = await browser.newContext();
    try {
      const page = await context.newPage();
      await context.clearCookies();

      let source = '';
      await page.route(checkoutUrl, async (route) => {
        const upstream = await route.fetch();
        source = await upstream.text();
        await route.fulfill({ response: upstream, body: source });
      });
      const response = await page.goto(checkoutUrl, { waitUntil: 'domcontentloaded' });
      expect(response).not.toBeNull();
      expect(response!.status()).toBe(200);
      expect(source, 'checkout navigation was not captured').not.toBe('');

      // Guard the fixture itself: an absent look-alike would make the
      // assertions below pass vacuously.
      const idVectorTag = tagWithAttribute(source, 'id', 'wc-settings-tracker-js');
      expect(idVectorTag, 'the wc-settings-tracker-js look-alike was not rendered').not.toBe('');

      // ID vector: id="wc-settings-tracker-js" is NOT one of the WordPress
      // suffixed identities of the real `wc-settings` handle.
      expect(idVectorTag, 'wc-settings-tracker-js was served executable before consent').toMatch(
        /\btype=["']text\/plain["']/i
      );
      expect(idVectorTag, 'wc-settings-tracker-js carried no consent category').toMatch(
        /\bdata-faz-category=/i
      );

      // Class vector: class="wc-settings-tracker" must not exempt either.
      const classVectorTag = tagWithAttribute(source, 'class', 'wc-settings-tracker');
      expect(classVectorTag, 'the class="wc-settings-tracker" look-alike was not rendered').not.toBe('');
      expect(classVectorTag, 'the wc-settings-tracker class was served executable before consent').toMatch(
        /\btype=["']text\/plain["']/i
      );
      expect(classVectorTag, 'the wc-settings-tracker class carried no consent category').toMatch(
        /\bdata-faz-category=/i
      );

      // Neither look-alike may be live in the browser. "Live" — rather than
      // "type is text/plain" — is the right shape here: the client-side
      // mutation observer lifts an already-neutralised script out of the
      // document into _fazStore._backupNodes, so a correctly blocked tag is
      // usually absent from the DOM by the time this runs. An exempted tag,
      // by contrast, is always present and executable.
      const live = await page.evaluate(() => {
        const isLive = (element: Element | null): boolean => {
          if (element === null) {
            return false; // Lifted out of the document by the observer.
          }
          const type = (element.getAttribute('type') ?? '').toLowerCase();
          return type !== 'text/plain' && type !== 'javascript/blocked';
        };
        return {
          idVector: isLive(document.querySelector('#wc-settings-tracker-js')),
          classVector: isLive(document.querySelector('script.wc-settings-tracker')),
        };
      });
      expect(live.idVector, 'the wc-settings-tracker-js look-alike stayed executable in the DOM').toBe(
        false
      );
      expect(live.classVector, 'the wc-settings-tracker class look-alike stayed executable in the DOM').toBe(
        false
      );
    } finally {
      await context.close();
    }
  });
});
