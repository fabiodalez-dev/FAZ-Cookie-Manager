<?php
header( 'Content-Type: text/html; charset=utf-8' );

$scenario = isset( $_GET['scenario'] ) ? preg_replace( '/[^a-z0-9\-]/i', '', (string) $_GET['scenario'] ) : 'plain';
$token    = isset( $_GET['token'] ) ? preg_replace( '/[^a-z0-9_]/i', '', (string) $_GET['token'] ) : 'lab';

if ( 'headers' === $scenario ) {
	header( sprintf( 'Set-Cookie: _faz_lab_http_%s=1; Path=/; HttpOnly; SameSite=Lax', $token ), false );
}
?><!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<title>FAZ Server Scan Lab</title>
</head>
<body>
	<h1>FAZ Server Scan Lab</h1>
	<?php if ( 'src-ga' === $scenario ) : ?>
		<script src="https://www.googletagmanager.com/gtag/js?id=G-LAB"></script>
	<?php elseif ( 'data-src-ga' === $scenario ) : ?>
		<script data-src="https://www.googletagmanager.com/gtag/js?id=G-LAB"></script>
	<?php elseif ( 'litespeed-fb' === $scenario ) : ?>
		<script data-litespeed-src="https://connect.facebook.net/en_US/fbevents.js"></script>
	<?php elseif ( 'iframe-youtube' === $scenario ) : ?>
		<iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ" title="YouTube"></iframe>
	<?php elseif ( 'src-facebook' === $scenario ) : ?>
		<script src="https://connect.facebook.net/en_US/fbevents.js"></script>
	<?php elseif ( 'mixed' === $scenario ) : ?>
		<script src="https://www.googletagmanager.com/gtag/js?id=G-LAB"></script>
		<script src="https://connect.facebook.net/en_US/fbevents.js"></script>
	<?php endif; ?>
</body>
</html>
