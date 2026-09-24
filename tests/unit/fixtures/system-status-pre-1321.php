<?php
/**
 * The Consent Records Refused row as it stood before 1.32.1.
 *
 * Kept only so the static guard in test-consent-token-window-php.php can prove
 * it still fails against the shape it was written for: two printf calls that
 * hand an integer conversion the locale-grouped string a formatting helper
 * returns, which truncates at the thousands separator so 2.002 prints as 2.
 *
 * Not loaded by the plugin, and deliberately left broken. Do not edit it to
 * "fix" the bug, and keep this header free of the two tokens the guard pairs
 * on, or it will count itself.
 *
 * @package FazCookie\Tests\Unit\Fixtures
 */

				// A consent record refused for a stale origin token leaves no
				// trace a site owner would ever look at: the banner works, the
				// visitor sees nothing, and only the accountability record is
				// missing. Say it here, where the rest of the effective
				// configuration is reported. Issue #292.
				$faz_token_rejections = \FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger::rejection_tally();
				$faz_rejected_count   = (int) $faz_token_rejections['count'];
				if ( $faz_rejected_count > 0 ) :
					$faz_token_window_days = max( 1, (int) round( \FazCookie\Frontend\Modules\Consent_Logger\Consent_Logger::token_max_age() / DAY_IN_SECONDS ) );
					?>
					<tr>
						<td><?php esc_html_e( 'Consent Records Refused', 'faz-cookie-manager' ); ?></td>
						<td>
							<?php
							printf(
								/* translators: %d: number of refused consent records. */
								esc_html( _n( '%d in the last 7 days — its origin token was not valid, so the consent was not recorded.', '%d in the last 7 days — their origin tokens were not valid, so those consents were not recorded.', $faz_rejected_count, 'faz-cookie-manager' ) ),
								esc_html( number_format_i18n( $faz_rejected_count ) )
							);
							?>
							<br><span class="faz-help">
							<?php
							printf(
								/* translators: %d: accepted token window, in days. */
								esc_html__( 'HTML served from a cache older than the accepted window of %d days is the usual cause: either shorten the cache lifetime or raise the window with the faz_consent_token_max_age filter. A token from another installation, or a malformed one, is refused the same way and counted here too.', 'faz-cookie-manager' ),
								esc_html( number_format_i18n( $faz_token_window_days ) )
							);
							?>
							</span>
						</td>
					</tr>
				<?php endif; ?>
