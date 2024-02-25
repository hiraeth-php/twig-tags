<?php

namespace Hiraeth\Twig\Tags;

use Twig\Token;
use Twig\Node\Node;
use Twig\TokenParser\AbstractTokenParser;
use Twig\Node\Expression\AbstractExpression;

class Parser extends AbstractTokenParser
{
	const PREFIX = '_TAGTOKEN_';

	/**
	 *
	 */
	protected $count = 0;

	/**
	 *
	 */
	protected $tokens = array();

	/**
	 *
	 */
	public function addToken(mixed $value)
	{
		$this->tokens[static::PREFIX . $this->count] = $value;
	}


	/**
	 *
	 */
	public function getTag()
	{
		return 'v';
	}


	/**
	 *
	 */
	public function getToken()
	{
		return static::PREFIX . $this->count++;
	}


	/**
	 *
	 */
	public function getValue($name)
	{
		return $this->tokens[$name];
	}


	/**
	 *
	 */
	public function parse(Token $token)
	{
		$parser = $this->parser;
		$stream = $parser->getStream();
		$stream->expect(Token::PUNCTUATION_TYPE, ':');
		$value = $parser->getExpressionParser()->parseExpression();
		$stream->expect(Token::BLOCK_END_TYPE);

		return new class($value) extends Node {
			static $count = 1;

			public function __construct(AbstractExpression $value)
			{
				parent::__construct(['value' => $value]);
			}

			public function compile(\Twig\Compiler $compiler)
			{
				$compiler
					->addDebugInfo($this)
					->write('$context[\'_tags_parser_\']->addToken(')
					->subcompile($this->getNode('value'))
					->write(')')
					->raw(";\n")
					->write('echo \'"\' . $context[\'_tags_parser_\']->getToken() . \'"\'')
					->raw(";\n");
				;
			}
		};
	}
}
