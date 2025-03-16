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
		$children  = [];

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

		if (str_contains($node->nodeName, ':')) {
			$prop = [];
			$data = [];
			$path = sprintf('@tags/%s.%s', preg_replace('/:+/', '/', $node->nodeName), $extension);

			if (!$this->manager->has($path)) {
				throw new RuntimeException(sprintf(
					'Could not find matching tag for "%s"',
					$node->nodeName
				));
			}

			foreach ($node->attributes as $attr) {
				if (str_ends_with($attr->name, ':')) {
					$type = 'prop';
					$name = substr($attr->name, 0, -1);
				} else {
					$type = 'data';
					$name = $attr->name;
				}

				if (str_starts_with($attr->value, (string) $this->parser::PREFIX)) {
					$$type[$name] = $this->parser->getValue($attr->value);
				} else {
					$$type[$name] = $attr->value;
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
								$content .= $item;
							}

							return $content;
						}
					}
				] + $data
			);

			$sub_doc = clone $this->doc;
			$sub_doc->loadHTML(
				$template->render(),
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_COMPACT | LIBXML_NONET
			);

			foreach ($sub_doc->childNodes as $sub_node) {
				foreach ($prop as $attr_name => $attr_value) {
					if ($sub_node->hasAttributes()) {
						foreach ($sub_node->attributes as $target_attr) {
							if ($target_attr->name == $attr_name) {
								$target_attr->value = $target_attr->value . ' ' . $attr_value;
								continue 2;
							}
						}
					}

					$new_attr        = $sub_doc->createAttribute($attr_name);
					$new_attr->value = $attr_value;

					$sub_node->appendChild($new_attr);
				}
			}

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
