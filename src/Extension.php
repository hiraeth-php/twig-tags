<?php
namespace Hiraeth\Twig\Tags;

use ArrayIterator;
use RuntimeException;
use Hiraeth\Twig\Manager;
use Hiraeth\Twig\Renderer;
use Twig\Extension\GlobalsInterface;
use Twig\Extension\AbstractExtension;
use IvoPetkov\HTML5DOMDocument;
use DOMDocumentFragment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

class Extension extends AbstractExtension implements Renderer, GlobalsInterface
{
	/**
	 * @var HTML5DOMDocument
	 */
	protected $doc;

	/**
	 * @var Manager
	 */
	protected $manager;

	/**
	 * @var TagsParser
	 */
	protected $parser;

	/**
	 *
	 */
	public function __construct(Parser $parser, HTML5DOMDocument $doc)
	{
		$this->parser = $parser;
		$this->doc    = $doc;

		$this->doc->registerNodeClass(DOMText::class, Text::class);
		$this->doc->registerNodeClass(DOMElement::class, Tag::class);
		$this->doc->registerNodeClass(DOMDocumentFragment::class, Fragment::class);
	}

	/**
	 *
	 */
	public function getGlobals(): array
	{
		return [
			'_tags_parser_' => $this->parser
		];
	}

	public function getTokenParsers()
	{
		return [$this->parser];
	}

	/**
	 *
	 */
	public function render (string $content, string $extension): string
	{
		if (!in_array($extension, ['html', 'html', 'xml'])) {
			return $content;
		}

		if (in_array($extension, ['html', 'htm'])) {
			$doc = clone $this->doc;

			$doc->loadHTML(
				sprintf('%s', $content),
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);

			$this->renderNode($doc, $doc, $extension);

			return $doc->saveHTML($doc);
		}
	}

	/**
	 *
	 */
	public function renderNode(DOMNode $node, HTML5DOMDocument $doc, string $extension)
	{
		$children  = array();

		for ($x = 0; $x < count($node->childNodes); $x++) {
			$child  = $node->childNodes->item($x);
			$result = $child;

			if ($child instanceof Text) {
				if ($child->clean()) {
					$node->removeChild($child); $x--;
					continue;
				}
			}


			if ($child instanceof Tag) {
				$result = $this->renderNode($child, $doc, $extension);

				if ($result !== $child) {
					$node->replaceChild($doc->importNode($result, true), $child);
					$x = $x - 1 + count($result->childNodes);
				}
			}

			$children[] = $result;
		}

		if (strpos($node->nodeName, ':') !== FALSE) {
			$data = array();
			$path = sprintf('@tags/%s.%s', str_replace(':', '/', $node->nodeName), $extension);

			if (!$this->manager->has($path)) {
				throw new RuntimeException(sprintf(
					'Could not find matching tag for "%s"',
					$node->nodeName
				));
			}

			foreach ($node->attributes as $attr) {
				if (strpos($attr->value, $this->parser::PREFIX) === 0) {
					$data[$attr->name] = $this->parser->getValue($attr->value);
				} else {
					$data[$attr->name] = $attr->value;
				}
			}

			$template = $this->manager->load(
				$path,
				[
					'children' => new class($children) extends ArrayIterator {
						public function __toString(): string
						{
							$content = '';

							foreach ($this as $item) {
								$content = $content . $item;
							}

							return $content;
						}
					}
				] + $data
			);

			$sub_doc = clone $this->doc;
			$sub_doc->loadHTML(
				$template->render(),
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);

			$fragment = $sub_doc->createDocumentFragment();

			$fragment->append(...$sub_doc->childNodes);

			return $fragment;
		}

		return $node;
	}


	/**
	 *
	 */
	public function setRenderManager(Manager $manager): self
	{
		$this->manager = $manager;

		return $this;
	}
}
