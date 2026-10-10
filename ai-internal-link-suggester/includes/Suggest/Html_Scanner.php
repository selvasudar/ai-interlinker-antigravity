<?php
/**
 * Tag processor that also reports the byte span of the current token.
 *
 * @package AILSUG
 */

namespace AILSUG\Suggest;

defined( 'ABSPATH' ) || exit;

/**
 * WP_HTML_Tag_Processor keeps bookmark spans in a protected property; reading them gives the
 * exact byte offsets of any token (including text nodes) in the original HTML string.
 * Read-only: it never modifies the document.
 */
class Html_Scanner extends \WP_HTML_Tag_Processor {

	/**
	 * Byte span of the current token.
	 *
	 * @return array{start:int,length:int}|null
	 */
	public function current_span() {
		if ( ! $this->set_bookmark( 'ailsug_here' ) || ! isset( $this->bookmarks['ailsug_here'] ) ) {
			return null;
		}
		$span = $this->bookmarks['ailsug_here'];
		$this->release_bookmark( 'ailsug_here' );
		return array(
			'start'  => (int) $span->start,
			'length' => (int) $span->length,
		);
	}
}
