<?php

namespace App\Providers;

use App\Services\Ai\AnthropicClient;
use App\Services\Statements\ParserRegistry;
use App\Services\Statements\Parsers\GenericLlmStatementPdfParser;
use App\Services\Statements\Parsers\NbcBankStatementPdfParser;
use App\Services\Statements\Parsers\NbcCreditCardStatementPdfParser;
use Illuminate\Support\ServiceProvider;

class StatementParserServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ParserRegistry::class, function ($app) {
            $registry = new ParserRegistry;

            // Register parsers here. New banks/products: implement
            // StatementParserInterface (typically by extending AbstractStatementParser)
            // and add one line below.
            $registry->register(new NbcBankStatementPdfParser);
            $registry->register(new NbcCreditCardStatementPdfParser);

            // Bank-agnostic fallback: parses any bank's statement via Claude.
            $registry->register(new GenericLlmStatementPdfParser($app->make(AnthropicClient::class)));

            return $registry;
        });
    }
}
