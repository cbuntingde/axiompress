<?php

declare(strict_types=1);

namespace AxiomPress\Protocol;

use JsonException;
use stdClass;

/** Bounded framing and strict validation for the first read-only experiment. */
final class Protocol
{
    public const API = 'axiompress.plugin/0.1';
    public const MAX_BYTES = 262144;
    public const MAX_DEPTH = 16;

    /** Read exactly one frame. Null means clean EOF before a frame starts. */
    public static function read($stream): ?stdClass
    {
        if (!is_resource($stream) || get_resource_type($stream) !== 'stream') {
            throw new ProtocolError('Expected a stream.');
        }
        $header = self::readBytes($stream, 4, true);
        if ($header === null) {
            return null;
        }
        $length = unpack('Nlength', $header)['length'];
        if ($length < 1 || $length > self::MAX_BYTES) {
            throw new ProtocolError('Invalid frame length.');
        }
        return self::decode(self::readBytes($stream, $length, false));
    }

    /** Encode only validated protocol messages. Writes/deadlines belong to the supervisor. */
    public static function encode(stdClass $message): string
    {
        self::validate($message);
        try {
            $json = json_encode($message, JSON_THROW_ON_ERROR, self::MAX_DEPTH + 2);
        } catch (JsonException $error) {
            throw new ProtocolError('Invalid JSON message.', 0, $error);
        }
        self::decode($json);
        return pack('N', strlen($json)) . $json;
    }

    public static function decode(string $json): stdClass
    {
        $message = self::parseObject($json);
        self::validate($message);
        return $message;
    }

    /** Strict bounded document parsing, also used for untrusted package manifests. */
    public static function parseObject(string $json): stdClass
    {
        if (strlen($json) < 1 || strlen($json) > self::MAX_BYTES) {
            throw new ProtocolError('Invalid message size.');
        }
        try {
            $message = json_decode($json, false, self::MAX_DEPTH + 2, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new ProtocolError('Invalid JSON message.', 0, $error);
        }
        // PHP's decoder accepts duplicate object keys. Detect them before dispatch.
        preg_match_all('/"(?:[^"\\\\]|\\\\.)*"|[{}\[\],:]/us', $json, $matches);
        $stack = array();
        foreach ($matches[0] as $token) {
            if ($token === '{' || $token === '[') {
                $stack[] = array('object' => $token === '{', 'key' => $token === '{', 'seen' => array());
                if (count($stack) > self::MAX_DEPTH) {
                    throw new ProtocolError('JSON nesting limit exceeded.');
                }
            } elseif ($token === '}' || $token === ']') {
                array_pop($stack);
            } elseif ($stack !== array()) {
                $index = count($stack) - 1;
                if ($token === ',' && $stack[$index]['object']) {
                    $stack[$index]['key'] = true;
                } elseif ($token[0] === '"' && $stack[$index]['object'] && $stack[$index]['key']) {
                    $key = '#' . json_decode($token, true, 2, JSON_THROW_ON_ERROR);
                    if (isset($stack[$index]['seen'][$key])) {
                        throw new ProtocolError('Duplicate JSON key.');
                    }
                    $stack[$index]['seen'][$key] = true;
                    $stack[$index]['key'] = false;
                }
            }
        }
        if (!$message instanceof stdClass) {
            throw new ProtocolError('Expected a message object.');
        }
        return $message;
    }

    private static function readBytes($stream, int $length, bool $allowEof): ?string
    {
        $data = '';
        while (strlen($data) < $length) {
            $part = fread($stream, min(8192, $length - strlen($data)));
            if ($part === false) {
                throw new ProtocolError('Stream read failed.');
            }
            if ($part === '') {
                if ($data === '' && $allowEof && feof($stream)) {
                    return null;
                }
                // Nonblocking starvation/timeouts are not clean EOF; never busy-loop.
                throw new ProtocolError('Incomplete frame.');
            }
            $data .= $part;
        }
        return $data;
    }

    private static function fields(stdClass $object, array $required): void
    {
        $actual = array_keys(get_object_vars($object));
        sort($actual);
        sort($required);
        if ($actual !== $required) {
            throw new ProtocolError('Unexpected or missing fields.');
        }
    }

    private static function token(mixed $value, int $max = 128): void
    {
        if (!is_string($value) || strlen($value) > $max || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]*\z/D', $value)) {
            throw new ProtocolError('Invalid identifier.');
        }
    }

    private static function object(mixed $value): stdClass
    {
        if (!$value instanceof stdClass) {
            throw new ProtocolError('Expected an object.');
        }
        return $value;
    }

    private static function validate(stdClass $message): void
    {
        if (($message->api ?? null) !== self::API) {
            throw new ProtocolError('Unsupported API version.');
        }
        self::token($message->id ?? null);
        $kind = $message->kind ?? null;
        if ($kind === 'request' || $kind === 'dispatch') {
            self::fields($message, array('api', 'kind', 'id', 'operation', 'invocation', 'params'));
            if (!is_string($message->invocation) || !preg_match('/\A[a-f0-9]{64}\z/D', $message->invocation)) {
                throw new ProtocolError('Invalid invocation handle.');
            }
            $params = self::object($message->params);
            $expected = $kind === 'request' ? 'content.read' : 'component.render';
            if ($message->operation !== $expected) {
                throw new ProtocolError('Unsupported operation.');
            }
            self::fields($params, array('content_id'));
            self::token($params->content_id);
        } elseif ($kind === 'response' || $kind === 'completion') {
            if (property_exists($message, 'error')) {
                self::fields($message, array('api', 'kind', 'id', 'error'));
                $error = self::object($message->error);
                self::fields($error, array('code', 'message', 'retryable', 'diagnostic_id'));
                if (
                    !in_array($error->code, array(
                    'invalid_request', 'unsupported_version', 'unauthenticated', 'forbidden', 'not_found',
                    'conflict', 'quota_exceeded', 'deadline_exceeded', 'unavailable', 'internal_error'
                    ), true)
                    || !is_bool($error->retryable)
                    || !is_string($error->message) || strlen($error->message) > 256
                ) {
                    throw new ProtocolError('Invalid error.');
                }
                self::token($error->diagnostic_id);
            } else {
                self::fields($message, array('api', 'kind', 'id', 'result'));
                $result = self::object($message->result);
                if ($kind === 'response') {
                    self::fields($result, array('content_id', 'revision', 'title', 'text'));
                    self::token($result->content_id);
                    self::token($result->revision);
                    if (
                        !is_string($result->title) || strlen($result->title) > 4096
                        || !is_string($result->text) || strlen($result->text) > 131072
                    ) {
                        throw new ProtocolError('Invalid content result.');
                    }
                } else {
                    self::fields($result, array('type', 'word_count', 'reading_seconds'));
                    if (
                        $result->type !== 'reading_summary' || !is_int($result->word_count)
                        || $result->word_count < 0 || $result->word_count > 131072
                        || !is_int($result->reading_seconds)
                        || $result->reading_seconds < 0 || $result->reading_seconds > 39322
                    ) {
                        throw new ProtocolError('Invalid component.');
                    }
                }
            }
        } else {
            throw new ProtocolError('Unsupported message kind.');
        }
    }
}
