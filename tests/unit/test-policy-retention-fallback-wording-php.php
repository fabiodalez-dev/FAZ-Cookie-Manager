<?php
/**
 * L14: every scaffold presents {{RETENTION_PERIOD}} as the declared fallback.
 *
 * The figure comes from the policy's `retention_months` setting, a declared
 * fallback that does not drive any purge (the consent log has its own
 * retention). The GDPR and POPIA scaffolds already say so; the CCPA ones and
 * every LGPD one except pt-BR still printed it as THE retention of personal
 * information ("{{RETENTION_PERIOD}} from collection."). Pinned here, per
 * language, across every shipped scaffold: each sentence carrying the token
 * uses that language's "declared fallback period" wording.
 *
 * Run: php tests/unit/test-policy-retention-fallback-wording-php.php
 */
$root    = dirname( __DIR__, 2 ) . '/admin/modules/cookie-policy-generator/templates';
$markers = array(
	'en'    => 'declared fallback period',
	'it'    => 'periodo residuale dichiarato',
	'cs'    => 'deklarovanou náhradní dobu',
	'es'    => 'periodo residual declarado',
	'de'    => 'erklärten Auffangzeitraum',
	'fr'    => 'période résiduelle déclarée',
	'nl'    => 'opgegeven terugvaltermijn',
	'hr'    => 'objavljenog zamjenskog roka',
	'bg'    => 'декларирания резервен срок',
	'pt-BR' => 'período residual declarado',
);
$checks = 0;
$failed = 0;
foreach ( glob( $root . '/*/*.md' ) as $file ) {
	$lang = basename( $file, '.md' );
	$juris = basename( dirname( $file ) );
	if ( ! isset( $markers[ $lang ] ) ) {
		// A scaffold added in a language with no marker must not pass unread:
		// "every shipped scaffold" includes it.
		if ( false !== strpos( (string) file_get_contents( $file ), '{{RETENTION_PERIOD}}' ) ) {
			++$checks;
			++$failed;
			echo "  FAIL {$juris}/{$lang}.md carries {{RETENTION_PERIOD}} but no fallback marker is defined for '{$lang}'\n";
		}
		continue;
	}
	foreach ( file( $file ) as $n => $line ) {
		if ( false === strpos( $line, '{{RETENTION_PERIOD}}' ) ) {
			continue;
		}
		++$checks;
		if ( false === mb_strpos( $line, $markers[ $lang ] ) ) {
			++$failed;
			echo "  FAIL {$juris}/{$lang}.md:" . ( $n + 1 ) . " presents the fallback figure as the retention period\n";
		}
		// LGPD: a period longer than the declared fallback is allowed only where
		// the law requires it or Art. 16 permits it. pt-BR, the native version,
		// said "ou para fins legítimos", a broader exception than any other
		// language and than Art. 16 itself.
		if ( 'lgpd-brazil' === $juris ) {
			++$checks;
			if ( 1 !== preg_match( '/\b16\.? (?:da )?LGPD/u', $line ) || false !== mb_strpos( $line, 'fins legítimos' ) ) {
				++$failed;
				echo "  FAIL {$juris}/{$lang}.md:" . ( $n + 1 ) . " allows a longer period on a ground other than the law or Art. 16 LGPD\n";
			}
		}
	}
}
echo "policy-retention-fallback-wording: " . ( $checks - $failed ) . " passed, {$failed} failed\n";
exit( $failed || $checks < 30 ? 1 : 0 );
