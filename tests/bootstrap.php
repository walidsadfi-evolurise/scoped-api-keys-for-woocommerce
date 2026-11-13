<?php
/**
 * Unit bootstrap: the pure class only needs ABSPATH.
 *
 * @package ScopedApiKeys
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/../' );
}
require_once __DIR__ . '/../includes/class-wcsak-scope.php';
