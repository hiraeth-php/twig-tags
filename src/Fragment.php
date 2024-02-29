<?php

namespace Hiraeth\Twig\Tags;

class Fragment extends \DOMDocumentFragment
{
	public function __toString(): string
	{
		$content = '';

		foreach ($this->childNodes as $child) {
			$content .= (string) $child;
		}

		return $content;
	}
}
