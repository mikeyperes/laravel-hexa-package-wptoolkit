<?php

namespace hexa_package_wptoolkit\Support;

use RuntimeException;

final class WordPressConfigCodec
{
    public function parse(string $contents): array
    {
        $tokens = token_get_all($contents);
        $offset = 0;
        $constants = [];
        $passwordSpan = null;
        $prefix = null;

        foreach ($tokens as $index => $token) {
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_STRING && strtolower($text) === 'define') {
                $match = $this->defineAt($tokens, $index, $offset);
                if ($match !== null) {
                    $constants[$match['name']] = $match['value'];
                    if ($match['name'] === 'DB_PASSWORD') {
                        $passwordSpan = [$match['offset'], $match['length']];
                    }
                }
            }
            if (is_array($token) && $token[0] === T_VARIABLE && $text === '$table_prefix') {
                $prefix = $this->assignmentAt($tokens, $index);
            }
            $offset += strlen($text);
        }

        foreach (['DB_NAME', 'DB_USER', 'DB_PASSWORD', 'DB_HOST'] as $name) {
            if (!array_key_exists($name, $constants)) {
                throw new RuntimeException("Computed or missing {$name} is unsupported; wp-config.php was not evaluated.");
            }
        }
        if ($passwordSpan === null || $prefix === null) {
            throw new RuntimeException('Computed or missing WordPress database settings are unsupported.');
        }
        foreach ([$constants['DB_NAME'], $constants['DB_USER'], $prefix] as $identifier) {
            if (preg_match('/\A[A-Za-z0-9_$-]+\z/D', $identifier) !== 1) {
                throw new RuntimeException('WordPress database identifiers contain unsupported characters.');
            }
        }

        return [
            'database' => $constants['DB_NAME'],
            'username' => $constants['DB_USER'],
            'password' => $constants['DB_PASSWORD'],
            'host' => $constants['DB_HOST'],
            'table_prefix' => $prefix,
            'password_span' => $passwordSpan,
        ];
    }

    public function withPassword(string $contents, array $span, string $password): string
    {
        if (count($span) !== 2 || !is_int($span[0]) || !is_int($span[1])) {
            throw new RuntimeException('Invalid wp-config.php password span.');
        }
        $literal = "'".str_replace(["\\", "'"], ["\\\\", "\\'"], $password)."'";

        return substr($contents, 0, $span[0]).$literal.substr($contents, $span[0] + $span[1]);
    }

    private function defineAt(array $tokens, int $index, int $offset): ?array
    {
        $offset += strlen($tokens[$index][1]);
        $strings = [];
        $equalsOrConcat = false;
        for ($i = $index + 1, $limit = min(count($tokens), $index + 16); $i < $limit; $i++) {
            $token = $tokens[$i];
            $text = is_array($token) ? $token[1] : $token;
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                $strings[] = ['raw' => $text, 'offset' => $offset];
            } elseif (!is_array($token) && in_array($token, ['.', '='], true)) {
                $equalsOrConcat = true;
            } elseif (!is_array($token) && $token === ';') {
                break;
            }
            if (count($strings) === 2) {
                if ($equalsOrConcat) {
                    return null;
                }
                return [
                    'name' => $this->decode($strings[0]['raw']),
                    'value' => $this->decode($strings[1]['raw']),
                    'offset' => $strings[1]['offset'],
                    'length' => strlen($strings[1]['raw']),
                ];
            }
            $offset += strlen($text);
        }

        return null;
    }

    private function assignmentAt(array $tokens, int $index): ?string
    {
        $sawEquals = false;
        for ($i = $index + 1, $limit = min(count($tokens), $index + 12); $i < $limit; $i++) {
            $token = $tokens[$i];
            if (!is_array($token) && $token === '=') {
                $sawEquals = true;
                continue;
            }
            if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING && $sawEquals) {
                return $this->decode($token[1]);
            }
            if (!is_array($token) && in_array($token, ['.', ';'], true)) {
                return null;
            }
        }

        return null;
    }

    private function decode(string $literal): string
    {
        $quote = $literal[0] ?? '';
        if (!in_array($quote, ["'", '"'], true) || substr($literal, -1) !== $quote) {
            throw new RuntimeException('Unsupported PHP string literal.');
        }
        $body = substr($literal, 1, -1);

        return $quote === "'"
            ? str_replace(["\\\\", "\\'"], ["\\", "'"], $body)
            : stripcslashes($body);
    }
}
