<?php

namespace Hiraeth\Twig\Tags;

class Fragment extends \DOMDocumentFragment implements \Stringable
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
