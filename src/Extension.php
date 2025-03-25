<?php
namespace Hiraeth\Twig\Tags;

use ArrayIterator;
use DOMDocument;
use DOMDocumentFragment;
use DOMElement;
use DOMText;
use Hiraeth\Application;
use RuntimeException;
use Hiraeth\Twig\Manager;
use Hiraeth\Twig\Renderer;
use Twig\Extension\GlobalsInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;
use Masterminds\HTML5;
use Stringable;

class Extension extends AbstractExtension implements Renderer, GlobalsInterface
{
	/**
	 * @var Application
	 */
	protected $app;

	/**
	 * @var array<string, mixed>
	 */
	protected $context = [];

	/**
	 * @var int
	 */
	protected $depth = 0;

	/**
	 * @var HTML5
	 */
	protected $dom;


	/**
	 * @var Manager
	 */
	protected $manager;

	/**
	 * @var string
	 */
	protected $nodeName;

	/**
	 * @var TagsParser
	 */
	protected $parser;

	/**
	 * @var array<Tag>
	 */
	protected $scripts = [];

	/**
	 *
	 */
	public function __construct(Application $app, Parser $parser)
	{
		$this->app    = $app;
		$this->parser = $parser;
		$this->dom    = new HTML5([
			'xmlNamespaces' => TRUE
		]);
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


	/**
	 *
	 */
	public function getFunctions(): array
	{
		return [
			new TwigFunction(
				'require',
				function(array $context, array $attributes, array $priors = []) {
					if ($attributes) {
						$missing = array_diff($attributes, array_keys($context));

						if (count($missing)) {
							throw new RuntimeException(sprintf(
								'Could not initialize %s component without attributes: "%s"',
								$this->nodeName,
								implode(', ', $missing)
							));
						}
					}

					if ($priors) {
						$missing = array_diff($priors, array_keys($context['ctx']));

						if (count($missing)) {
							throw new RuntimeException(sprintf(
								'Could not initialize %s component without context: "%s"',
								$this->nodeName,
								implode(', ', $missing)
							));
						}
					}
				},
				[
				 	'needs_context' => TRUE
				]
			),
			new TwigFunction(
				'resolve',
				function(array &$context, array $values) {
					$context = array_merge($context, $values);
				},
				[
				 	'needs_context' => TRUE
				]
			)
		];
	}


	/**
	 *
	 */
	public function getTokenParsers()
	{
		return [$this->parser];
	}

	/**
	 *
	 */
	public function render(string $content, string $extension): string
	{

		if (!in_array($extension, ['html', 'html'])) {
			return $content;
		}

		if (!$this->depth) {
			$this->context = [];
			$this->scripts = [];
		}

		$doc = $this->dom->loadHTML($content);

		$doc->registerNodeClass(DOMDocumentFragment::class, Fragment::class);
		$doc->registerNodeClass(DOMElement::class, Tag::class);
		$doc->registerNodeClass(DOMText::class, Text::class);

		$this->renderNode($doc->documentElement, $doc, $extension);

		if (!$this->depth) {
			if (count($this->scripts)) {
				ksort($this->scripts);

				$content = sprintf('%s', join("\n", array_map(
					fn($script) => $script->textContent,
					$this->scripts
				)));

				$hash = md5($content);
				$file = sprintf('storage/public/hscripts/%s.js', $hash);

				if (!$this->app->hasFile($file)) {
					$handle = $this->app->getFile($file, TRUE)->openFile('w+');

					$handle->fwrite($content);
					$handle->fflush();
				}

				$scripts = $doc->createElement('script');
				$source  = $doc->createAttribute('src');

				$source->value = sprintf('/storage/hscripts/%s.js', $hash);

				$scripts->appendChild($source);

				$doc->getElementsByTagName('html')[0]->prepend($scripts);
			}
		}

		return $this->dom->saveHTML($doc);
	}


	/**
	 *
	 */
	public function renderChildren(Tag $node, DOMDocument $doc, string $extension, array $data = []): array {
		$children = [];

		for ($x = 0; $x < count($node->childNodes); $x++) {
			$result = $child = $node->childNodes[$x];

			if ($child instanceof Text) {
				if ($child->trim()) {
					$child->remove();
					$x--;

					continue;
				}
			}

			if ($child instanceof Tag) {
				$result = $this->renderNode($child, $doc, $extension, $data);

				if ($result->nodeName == 'html') {
					$result   = iterator_to_array($result->childNodes);
					$children = array_merge($children, $result);

					$child->replaceWith(...$result);

					$x        = $x - 1 + count($result);

					continue;
				}

			}

			$children[] = $result;
		}

		return $children;
	}


	/**
	 *
	 */
	public function renderNode(Tag $node, DOMDocument $doc, string $extension, array $data = [])
	{
		$this->nodeName = $node->nodeName;

		if (str_contains($node->nodeName, ':')) {
			$context       = [];
			$attributes    = [];
			$sub_document  = $doc->createElement('html');
			$path_tag      = preg_replace('/^[a-z]+[:]+|[:]+/', '/', $node->nodeName);
			$path          = '@tags' . $path_tag . '.' . $extension;

			foreach ($node->attributes as $attr) {
				if (str_ends_with($attr->name, ':')) {
					$type = 'attributes';
					$name = substr($attr->name, 0, -1);
				} else {
					$type = 'data';
					$name = $attr->name;
				}

				if (str_starts_with($attr->value, (string) $this->parser::PREFIX)) {
					$$type[$name] = $this->parser->getValue($attr->value);
				} else {
					$$type[$name] = $attr->value ?: 1;
				}
			}

			$this->context = $context = $data + $this->context;

			if ($path_tag == '/') {
				$children = $this->renderChildren($node, $doc, $extension, $data);

				$sub_document->append(...$children);

			} else {
				$children = $this->renderChildren($node, $doc, $extension);

				if (!$this->manager->has($path)) {
					throw new RuntimeException(sprintf(
						'Could not find matching tag for "%s" at path "%s"',
						$path_tag,
						$path
					));
				}

				$this->depth++;

				$fragment = $this->dom->loadHTMLFragment(
					(string) $this->manager->load(
						$path,
						[
							'ctx'      => $context,
							'children' => new class($children) extends ArrayIterator implements Stringable {
								public function __toString(): string
								{
									return join('', iterator_to_array($this));
								}
							}
						] + $data
					),
					[
						'target_document' => $doc,
						'disable_html_ns' => TRUE
					]
				);

				$this->depth--;

				foreach ($fragment->ownerDocument->getElementsByTagName('script') as $script) {
					$this->scripts[md5($script->textContent)] = $script;
					$script->remove();
				}

				$sub_document->append(...$fragment->getHTMLChildren());
			}

			foreach ($sub_document->childNodes as $sub_node) {
				foreach ($attributes as $name => $value) {
					if (!is_string($value)) {
						if (is_array($value) || is_bool($value)) {
							$value = json_encode($value);
						} else {
							$value = (string) $value;
						}
					}

					if ($sub_node->hasAttributes()) {
						foreach ($sub_node->attributes as $target_attribute) {
							if ($target_attribute->name == $name) {
								$target_attribute->value = $target_attribute->value . ' ' . $value;
								continue 2;
							}
						}
					}

					$new_attribute        = $doc->createAttribute($name);
					$new_attribute->value = $value;

					$sub_node->appendChild($new_attribute);
				}
			}

			return $sub_document;

		} else {
			$this->renderChildren($node, $doc, $extension);

			return $node;
		}
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
