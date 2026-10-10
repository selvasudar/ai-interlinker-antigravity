<?php
/**
 * CSV download helper.
 *
 * @package AILSUG
 */

namespace AILSUG\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Streams CSV with spreadsheet formula-injection protection.
 */
final class Csv {

	/**
	 * Send a CSV download and exit.
	 *
	 * @param string   $filename File name.
	 * @param string[] $headers  Header row.
	 * @param iterable $rows     Rows (arrays of scalars).
	 * @return void
	 */
	public static function download( $filename, array $headers, $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . sanitize_file_name( $filename ) );
		$out = fopen( 'php://output', 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming to output.
		fwrite( $out, "\xEF\xBB\xBF" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- UTF-8 BOM for spreadsheet apps.
		fputcsv( $out, $headers, ',', '"', '\\' );
		foreach ( $rows as $row ) {
			fputcsv( $out, array_map( array( __CLASS__, 'cell' ), (array) $row ), ',', '"', '\\' );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming to output.
		exit;
	}

	/**
	 * Neutralize cells that a spreadsheet would treat as formulas.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	public static function cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && in_array( $value[0], array( '=', '+', '-', '@', "\t", "\r" ), true ) && ! is_numeric( $value ) ) {
			$value = "'" . $value;
		}
		return $value;
	}
}
