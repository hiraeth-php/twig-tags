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
		$children = array();

		foreach ($node->childNodes as $child) {

			$result = $this->renderNode($child, $doc, $extension);

			if ($child instanceof DOMElement) {
				$children[] = $result;
			}

			if ($result !== $child) {
				$node->replaceChild($doc->importNode($result, true), $child);
			}
		}

		if (strpos($node->nodeName, ':')) {
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

			$this->doc->loadHTML(
				$template->render(),
				LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
			);

			$fragment = $this->doc->createDocumentFragment();

			$fragment->append(...$this->doc->childNodes);

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
