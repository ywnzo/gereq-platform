<?php

declare(strict_types=1);

namespace Gereq\Database\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * DQL: REPLACE(string, search, replace) → SQL REPLACE(...)
 */
final class ReplaceFunction extends FunctionNode
{
    private Node|string $subject;
    private Node|string $search;
    private Node|string $replace;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'REPLACE('
            . $sqlWalker->walkStringPrimary($this->subject) . ', '
            . $sqlWalker->walkStringPrimary($this->search) . ', '
            . $sqlWalker->walkStringPrimary($this->replace)
            . ')';
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->subject = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->search = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->replace = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
