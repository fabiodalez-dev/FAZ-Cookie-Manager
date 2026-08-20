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
	file_put_contents( $target . '/vendor/stale.php', 'stale' );
	file_put_contents( $target . '/.git/objects/stale', 'stale' );
	file_put_contents( $target . '/tests/stale/result.txt', 'stale' );

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
		'node_modules never reaches the webroot'           => ! file_exists( $target . '/node_modules' ),
	);
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
echo "Deploy target cleanliness: 6 passed.\n";
