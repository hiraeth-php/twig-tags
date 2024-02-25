<?php
namespace Hiraeth\Twig\Tags;

use Hiraeth\Twig\Manager;
use Hiraeth\Twig\Renderer;
use Twig\Extension\GlobalsInterface;
use Twig\Extension\AbstractExtension;
use IvoPetkov\HTML5DOMDocument;
use RuntimeException;
use ArrayIterator;
use DOMElement;
use DOMNode;

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

		$this->doc->registerNodeClass(DOMElement::class, Tag::class);
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
		foreach ($node->childNodes as $child) {
			$data       = array();
			$children   = array();
			$children[] = $this->renderNode($child, $doc, $extension);
			$child_name = $child->nodeName;

			if (strpos($child_name, ':')) {
				$path = sprintf('@tags/%s.%s', str_replace(':', '/', $child_name), $extension);

				if (!$this->manager->has($path)) {
					throw new RuntimeException('');
				}

				foreach ($child->attributes as $attr) {
					if (strpos($attr->value, $this->parser::PREFIX) === 0) {
						$data[$attr->name] = $this->parser->getValue($attr->value);
					} else {
						$data[$attr->name] = $attr->value;
					}
				}

				$sub_doc  = clone $this->doc;
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
					] + $data);

				$sub_doc->loadHTML(
					$template->render(),
					LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
				);

				$fragment = $sub_doc->createDocumentFragment();

				$fragment->append(...$sub_doc->childNodes);
				$node->replaceChild($doc->importNode($fragment, true), $child);
			}
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
