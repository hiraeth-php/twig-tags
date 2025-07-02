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
	 * @var array<array<string, mixed>>
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
	 * @var array<string>
	 */
	protected $hashes = [];

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
	 *
	 */
	public function __construct(Application $app, Parser $parser)
	{
		$this->app    = $app;
		$this->parser = $parser;
		$this->dom    = new HTML5([
			'xmlNamespaces'   => TRUE,
			'disable_html_ns' => TRUE
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
				'default',
				function(array &$context, array $values) {
					$context = array_merge($context, $values);
				},
				[
				 	'needs_context' => TRUE
				]
			),

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
						$missing = array_diff($priors, array_keys($context['context']));

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
				'styling',
				function(array &$context, string|array|null ...$classes) {
					return $context['styling'] = array_reduce(
						$classes,
						function($n, $v) {
							if (is_array($v)) {
								$v = trim(implode(' ', array_keys(array_filter($v))));
							}

							return !empty($v)
								? $n .= ' ' . $v
								: $n
							;
						}
					);
				},
				[
					'needs_context' => TRUE,
					'is_variadic' => TRUE,
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
	public function render(string $content, string $extension, array $context = []): string
	{

		if (!in_array($extension, ['html', 'html'])) {
			return $content;
		}

		if (!$this->depth) {
			$this->context = [$context];
		}

		$doc = $this->dom->loadHTML($content);

		$doc->registerNodeClass(DOMDocumentFragment::class, Fragment::class);
		$doc->registerNodeClass(DOMElement::class, Tag::class);
		$doc->registerNodeClass(DOMText::class, Text::class);

		if (!$doc->documentElement) {
			return $content;
		}

		$this->renderNode($doc->documentElement, $doc, $extension);

		if (!$this->depth) {
			foreach (['title', 'script', 'style'] as $name) {
				foreach ($doc->getElementsByTagName($name) as $node) {
					if ($node->getAttribute('src')) {
						continue;
					}

					if ($node->parentElement->nodeName == 'head') {
						continue;
					}

					/**
					 * @var Tag
					 */
					$root = count($doc->getElementsByTagName('head'))
						? $doc->getElementsByTagName('head')[0]
						: $doc->getElementsByTagName('html')[0]
					;

					if ($name == 'title') {
						$new_node = $doc->createElement($node->nodeName);
						$new_node->prepend($node->textContent);
						$root->prepend($new_node);

					} else {
						$hash = md5($node->textContent);

						if (!isset($this->hashes[$hash])) {
							$attr        = $doc->createAttribute('data-hash');
							$new_node    = $doc->createElement($node->nodeName);
							$attr->value = $hash;

							$new_node->appendChild($attr);
							$new_node->append($node->textContent);

							if ($root->nodeName == 'html') {
								$root->prepend($new_node);
							} else {
								$root->append($new_node);
							}
						}

						$this->hashes[$hash] = TRUE;
					}

					$node->remove();
				}
			}

			if (strpos(substr($content, 0, 32), '<html') === FALSE) {
				return preg_replace(
					'#^<!doctype.*html>\s*<html>|</html>$#i',
					'',
					$this->dom->saveHTML($doc)
				);
			}
		}

		return $this->dom->saveHTML($doc);
	}


	/**
	 *
	 */
	public function renderChildren(Tag $node, DOMDocument $doc, string $extension, array $data = []): array {
		$children = [];

		for ($index = 0; $index < count($node->childNodes); $index++) {
			$result = $child = $node->childNodes[$index];

			if ($child instanceof Text) {
				if ($child->trim()) {
					$child->remove();
					$index--;

					continue;
				}
			}

			if ($child instanceof Tag) {
				$result = $this->renderNode($child, $doc, $extension, $data);

				if ($result->nodeName == 'html') {
					$result   = iterator_to_array($result->childNodes);
					$children = array_merge($children, $result);
					$index    = $index - 1 + count($result);

					$child->replaceWith(...$result);


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
			$attributes    = [];
			$sub_document  = $doc->createElement('html');
			$path_tag      = preg_replace('/^[a-z]+[:]+|[:]+/', '/', $node->nodeName);
			$path          = '@tags' . $path_tag . '.' . $extension;

			foreach ($node->attributes as $attr) {
				if (str_ends_with($attr->name, '...')) {
					$name    = substr($attr->name, 0, -3);
					$context = end($this->context);

					if (array_key_exists($name, $context)) {
						$data[$name] = $context[$name];
					}

				} else {
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
						$$type[$name] = $attr->value != '' ? $attr->value : 1;
					}
				}
			}

			array_push($this->context, $data);

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

				/**
				 * @var Fragment
				 */
				$fragment = $this->dom->loadHTMLFragment(
					(string) $this->manager->load(
						$path,
						[
							'context'  => array_replace([], ...$this->context),
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

				$sub_document->append(...$fragment->getHTMLChildren());
			}

			array_pop($this->context);

			foreach ($sub_document->childNodes as $sub_node) {
				if (in_array($sub_node->nodeName, ['script', 'style'])) {
					continue;
				}

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
