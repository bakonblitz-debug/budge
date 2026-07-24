<?php

namespace App\Services\Statements;

use App\Services\Statements\Contracts\StatementParserInterface;

/**
 * Registry of statement parsers. Registered as a singleton in
 * StatementParserServiceProvider so handlers don't have to rebuild it.
 */
class ParserRegistry
{
    /** @var array<string, StatementParserInterface> Keyed by format identifier. */
    private array $parsers = [];

    public function register(StatementParserInterface $parser): self
    {
        $this->parsers[$parser->getFormat()] = $parser;

        return $this;
    }

    public function get(string $format): StatementParserInterface
    {
        if (! isset($this->parsers[$format])) {
            throw new \InvalidArgumentException("Unknown statement format: {$format}");
        }

        return $this->parsers[$format];
    }

    /**
     * @return StatementParserInterface[]
     */
    public function all(): array
    {
        return array_values($this->parsers);
    }

    /**
     * Filter parsers that support a given bank account type
     * ('chequing', 'savings', 'credit_card', 'line_of_credit', 'other').
     *
     * @return StatementParserInterface[]
     */
    public function forAccountType(string $accountType): array
    {
        return array_values(array_filter(
            $this->parsers,
            fn (StatementParserInterface $p) => in_array($accountType, $p->getSupportedAccountTypes(), true),
        ));
    }
}
