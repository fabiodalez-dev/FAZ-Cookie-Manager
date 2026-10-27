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
  //
  // WHY THE ID VECTOR IS BLOCKED (its src carries no `wc-settings` fragment, on
  // purpose — only an id-based exemption could rescue it, which is the whole
  // point of the vector). Two different code paths, one per vector:
  //
  //   ID vector    — enqueued with handle `wc-settings-tracker`, so it travels
  //                  through `script_loader_tag` → Frontend::filter_script_loader_tag().
  //                  That matcher tests each provider pattern against BOTH the
  //                  handle and the src:
  //                      stripos( $handle, $pattern ) || stripos( $tag_src, $pattern )
  //                  beforeAll above installs the custom rule `wc-settings` →
  //                  marketing, which enters the provider map, and
  //                  stripos('wc-settings-tracker', 'wc-settings') === 0. Marketing
  //                  is denied pre-consent → type="text/plain". The src is never
  //                  consulted; a reading that only considers src concludes,
  //                  wrongly, that this vector can never be blocked.
  //
  //   Class vector — printed directly in wp_footer (no handle), so it is caught
  //                  by the output-buffer path → match_script_to_provider(), which
  //                  DOES go by src and applies a token-boundary check. Hence its
  //                  src spells `/wc-settings/` slash-delimited.
  //
  // What makes the assertion discriminating in both cases is that
  // is_strictly_necessary_script() and is_whitelisted() run BEFORE the provider
  // loop in every path: widening the exemption to prefix/id matching short-
  // circuits ahead of the block and the tag ships executable. Verified by
  // mutation — reinstating the whitelist merge in class-frontend.php turns this
  // test red with "wc-settings-tracker-js was served executable before consent".
  //
  // Nothing here depends on scanner-detected state: the custom rule is
  // self-provisioned in beforeAll, so the test behaves identically on a clean
  // install.
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

  test('fresh mobile guests retain the first AJAX addition after rejecting cookies', async ({ browser, browserName }, testInfo) => {
    // Exercise the classic catalogue -> immediate View cart flow as well as the
    // Store API coverage above. Every iteration owns a new cookie jar: reusing
    // a guest session would miss first-add failures. This fixture has no edge
    // cache and cannot by itself rule out a production LiteSpeed/Cloudflare race.
    const fixture = JSON.parse(wpEval(`
      $snapshot = array();
      foreach ( array( 'woocommerce_cart_page_id', 'woocommerce_enable_ajax_add_to_cart', 'woocommerce_cart_redirect_after_add', 'woocommerce_coming_soon' ) as $name ) {
        $snapshot[ $name ] = get_option( $name, null );
      }
      $product = new WC_Product_Simple();
      $product->set_name( 'FAZ first mobile cart addition' );
      $product->set_status( 'publish' );
      $product->set_regular_price( '9.99' );
      $product->set_virtual( true );
      $product_id = $product->save();
      $catalogue_id = wp_insert_post( array(
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'FAZ first mobile cart catalogue',
        'post_content' => '[products ids="' . $product_id . '"]',
      ), true );
      $cart_id = wp_insert_post( array(
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => 'FAZ first mobile cart', 'post_content' => '[woocommerce_cart]',
      ), true );
      if ( ! $product_id || is_wp_error( $catalogue_id ) || is_wp_error( $cart_id ) ) {
        if ( $product_id ) { wp_delete_post( $product_id, true ); }
        if ( is_int( $catalogue_id ) ) { wp_delete_post( $catalogue_id, true ); }
        if ( is_int( $cart_id ) ) { wp_delete_post( $cart_id, true ); }
        throw new RuntimeException( 'Could not create the classic cart fixture.' );
      }
      update_option( 'woocommerce_cart_page_id', $cart_id );
      update_option( 'woocommerce_enable_ajax_add_to_cart', 'yes' );
      update_option( 'woocommerce_cart_redirect_after_add', 'no' );
      // A newly assigned real cart page otherwise inherits Woo's store-only
      // "coming soon" screen from the baseline instead of rendering a cart.
      update_option( 'woocommerce_coming_soon', 'no' );
      wp_cache_flush();
      echo wp_json_encode( array(
        'snapshot' => $snapshot, 'productId' => $product_id,
        'catalogueId' => $catalogue_id, 'cartId' => $cart_id,
        'catalogueUrl' => get_permalink( $catalogue_id ), 'cartUrl' => get_permalink( $cart_id ),
      ) );
    `)) as { snapshot: Record<string, unknown>; productId: number; catalogueId: number; cartId: number; catalogueUrl: string; cartUrl: string };

    try {
      for (let attempt = 0; attempt < 5; attempt++) {
        const context = await browser.newContext({
          viewport: { width: 393, height: 852 },
          isMobile: browserName !== 'firefox',
          hasTouch: true,
        });
        const page = await context.newPage();
        try {
          await page.goto(fixture.catalogueUrl, { waitUntil: 'domcontentloaded' });
          await page.locator('[data-faz-tag="reject-button"]').click();
          const consent = (await context.cookies()).find((cookie) => cookie.name === 'fazcookie-consent');
          expect(consent, 'Reject All must persist before adding the product').toBeDefined();
          expect(decodeURIComponent(consent!.value)).toContain('analytics:no');
          expect(decodeURIComponent(consent!.value)).toContain('marketing:no');

          await page.locator(`.add_to_cart_button[data-product_id="${fixture.productId}"]`).click();
          // No delay or polling the Store API before navigation: follow the
          // real Woo link as soon as its AJAX success handler inserts it.
          await page.locator('a.added_to_cart.wc-forward').click();
          expect(new URL(page.url()).pathname).toBe(new URL(fixture.cartUrl).pathname);
          await expect(page.locator('.woocommerce-cart-form .cart_item')).toHaveCount(1);
          await expect(page.locator('.woocommerce-cart-form .cart_item')).toContainText('FAZ first mobile cart addition');
          const session = (await context.cookies()).find((cookie) => cookie.name.startsWith('wp_woocommerce_session_'));
          expect(session, 'the first guest cart must have a session cookie').toBeDefined();
          expect(session!.httpOnly).toBe(true);
        } catch (error) {
          await testInfo.attach(`classic-cart-attempt-${attempt + 1}`, {
            body: await page.screenshot(), contentType: 'image/png',
          });
          throw error;
        } finally {
          await context.close();
        }
      }
    } finally {
      const encoded = Buffer.from(JSON.stringify(fixture), 'utf8').toString('base64');
      wpEval(`
        $fixture = json_decode( base64_decode( '${encoded}' ), true );
        foreach ( $fixture['snapshot'] as $name => $value ) {
          if ( null === $value ) { delete_option( $name ); }
          else { update_option( $name, $value ); }
        }
        foreach ( array( 'productId', 'catalogueId', 'cartId' ) as $key ) {
          wp_delete_post( (int) $fixture[ $key ], true );
        }
        wp_cache_flush();
      `);
    }
  });
});
