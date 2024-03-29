<?php

namespace Hiraeth\Twig\Tags;

class Text extends \DOMText
{
	static protected $inlineElements = [
		'a','abbr','acronym','b','bdo','big','br','button','cite','code','dfn','em','i','img',
		'input','kbd','label','map','object','q','samp','script','select','small','span','strong',
		'sub','sup','textarea','time','tt','var'
	];

	public function __toString(): string
	{
		return $this->textContent;
	}

	public function clean(): bool
	{
		$next = $this->nextSibling;
		$prev = $this->previousSibling;

		if (in_array($this->parentNode->nodeName, ['pre', 'code'])) {
			return FALSE;
		}

		if (!ctype_space($this->textContent)) {
			if (!$prev || !in_array($prev->nodeName, static::$inlineElements)) {
				$this->textContent = ltrim($this->textContent);
			} else {
				$this->textContent = preg_replace('/^\s+/', ' ', $this->textContent);
			}

			if (!$next || !in_array($next->nodeName, static::$inlineElements)) {
				$this->textContent = rtrim($this->textContent);
			} else {
				$this->textContent = preg_replace('/\s+$/', ' ', $this->textContent);
			}

			$this->textContent = preg_replace('/\s+/', ' ', $this->textContent);

			return FALSE;
		}

		if ($this->isInline()) {
			$this->textContent = preg_replace('/\s+/', ' ', $this->textContent);

			return FALSE;
		}


		return TRUE;
	}


	public function isInline(): bool
	{
		$next = $this->nextSibling;
		$prev = $this->previousSibling;

		if ($next && in_array($next->nodeName, static::$inlineElements)) {
			return TRUE;
		}

		if ($prev && in_array($prev->nodeName, static::$inlineElements)) {
			return TRUE;
		}

		return FALSE;
	}
}
