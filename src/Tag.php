<?php

namespace Hiraeth\Twig\Tags;

/**
 *
 */
class Tag extends \DOMElement implements \Stringable
{
	/**
	 *
	 */
	public function __toString(): string
	{
		return preg_replace('#^<html>|</html>$#', '', $this->ownerDocument->saveHTML($this));
	}
}
