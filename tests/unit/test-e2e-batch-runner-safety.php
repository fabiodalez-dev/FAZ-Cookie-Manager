<?php
/** Source-contract checks for the shared-state E2E batch runner. */

$source = file_get_contents( dirname( __DIR__, 2 ) . '/scripts/run-e2e-batches.sh' );
$checks = array(
	'one spec per clean batch is the default'       => false !== strpos( $source, 'BATCH_SIZE="${BATCH_SIZE:-1}"' ),
	'one Playwright worker is the default'           => false !== strpos( $source, 'E2E_WORKERS="${E2E_WORKERS:-1}"' ),
	'worker count is passed to Playwright'            => false !== strpos( $source, '--workers="$E2E_WORKERS"' ),
	'any failed batch marks the whole run failed'     => false !== strpos( $source, '[ "$rc" -eq 0 ] || overall_rc=1' ),
	'runner exits with the aggregate failure status' => false !== strpos( $source, 'exit "$overall_rc"' ),
	'ANSI cursor fragments are stripped from counts' => false !== strpos( $source, "sed -E 's/\\[[0-9;?]*[A-Za-z]//g'" ),
	'multisite spec stays in its isolated runner'     => false !== strpos( $source, '*release-verify-multisite-scanner.spec.ts) continue' ),
);

$failed = 0;
foreach ( $checks as $label => $passed ) {
	if ( $passed ) {
		echo "  [PASS] {$label}\n";
	} else {
		$failed++;
		echo "  [FAIL] {$label}\n";
	}
}

if ( $failed ) {
	exit( 1 );
}
echo "E2E batch runner safety: 7 passed.\n";
