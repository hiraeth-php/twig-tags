<?php

namespace Hiraeth\Twig\Tags;

class Fragment extends \DOMDocumentFragment implements \Stringable
{
	public function __toString(): string
	{
		return preg_replace('#^<html>|</html>$#', '', $this->ownerDocument->saveHTML($this));
	}


	public function getHTMLChildren()
	{
		$test = function($node) use (&$test) {
			if ($node->nodeName == 'html') {
				return iterator_to_array($node->childNodes);
			}

			foreach ($node->childNodes as $child) {
				if ($child instanceof Tag) {
					return $test($child);
				}
			}

			return [];
		};

		return $test($this->getRootNode());
	}
}
