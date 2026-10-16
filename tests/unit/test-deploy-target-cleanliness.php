<?php
/** Functional regression test for the local WordPress deploy boundary. */

$root   = dirname( __DIR__, 2 );
$script = $root . '/scripts/deploy-test.sh';
$base   = sys_get_temp_dir() . '/faz-deploy-' . bin2hex( random_bytes( 6 ) );
$target = $base . '/wp-content/plugins/faz-cookie-manager';

function faz_deploy_remove_tree( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $iterator as $item ) {
		if ( $item->isDir() ) {
			rmdir( $item->getPathname() );
		} else {
			unlink( $item->getPathname() );
		}
	}
	rmdir( $path );
}

$failed = 0;
try {
	mkdir( $target . '/vendor', 0777, true );
	mkdir( $target . '/.git/objects', 0777, true );
	mkdir( $target . '/tests/stale', 0777, true );
	mkdir( $target . '/test-results', 0777, true );
	file_put_contents( $target . '/vendor/stale.php', 'stale' );
	file_put_contents( $target . '/.git/objects/stale', 'stale' );
	file_put_contents( $target . '/tests/stale/result.txt', 'stale' );
	file_put_contents( $target . '/test-results/.last-run.json', '{}' );

	$output = array();
	$status = 0;
	$cmd    = 'FAZ_DEPLOY_TARGET=' . escapeshellarg( $target . '/' )
		. ' bash ' . escapeshellarg( $script ) . ' 2>&1';
	exec( $cmd, $output, $status );

	$checks = array(
		'deploy command succeeds'                         => 0 === $status,
		'plugin entry point is copied'                    => is_file( $target . '/faz-cookie-manager.php' ),
		'previously deployed excluded vendor is removed' => ! file_exists( $target . '/vendor' ),
		'previously deployed .git is removed'             => ! file_exists( $target . '/.git' ),
		'previously deployed tests are removed'            => ! file_exists( $target . '/tests' ),
		'browser artifacts never reach the webroot'        => ! file_exists( $target . '/test-results' ),
		'node_modules never reaches the webroot'           => ! file_exists( $target . '/node_modules' ),
	);
	// The private assistant context of the checkout never reaches the webroot:
	// .claude/ carries notes and, when agents work in worktrees, whole copies
	// of the repository (206 MB measured), and CLAUDE.md is private context.
	// Asked of the list itself, because the test source tree has neither.
	$excludes = array();
	exec( 'bash ' . escapeshellarg( $script ) . ' --print-excludes 2>&1', $excludes );
	$checks['.claude/ is excluded from the deploy'] = in_array( '.claude', $excludes, true );
	$checks['CLAUDE.md is excluded from the deploy'] = in_array( 'CLAUDE.md', $excludes, true );
	// Browser-tool scratch: measured in a working tree on 2026-10-16, copied
	// into the webroot by every deploy until it was listed here.
	$checks['.playwright-mcp/ is excluded from the deploy'] = in_array( '.playwright-mcp', $excludes, true );
	$checks['.playwright-cli/ is excluded from the deploy'] = in_array( '.playwright-cli', $excludes, true );
	foreach ( $checks as $label => $passed ) {
		if ( $passed ) {
			echo "  [PASS] {$label}\n";
		} else {
			$failed++;
			echo "  [FAIL] {$label}\n";
		}
	}
	if ( 0 !== $status ) {
		echo implode( "\n", $output ) . "\n";
	}
} finally {
	faz_deploy_remove_tree( $base );
}

if ( $failed ) {
	exit( 1 );
}
echo "Deploy target cleanliness: " . count( $checks ) . " passed.\n";
