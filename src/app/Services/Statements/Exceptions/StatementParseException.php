<?php

namespace App\Services\Statements\Exceptions;

/**
 * Thrown when a parser cannot make sense of a file.
 * Messages from this exception are considered safe to display to users
 * (parsers must not include raw file content in the message).
 */
class StatementParseException extends \RuntimeException
{
}
