<?php

namespace Hiraeth\Twig\Tags;

use IvoPetkov\HTML5DOMElement;

/**
 *
 */
class Tag extends HTML5DOMElement {
	/**
	 * Undocumented function
	 *
	 * @return string
	 */
	public function __toString()
	{
		return $this->innerHTML;
	}
}
