<?php

declare(strict_types=1);

namespace AxiomPress\Service;

use AxiomPress\Protocol\Protocol;
use InvalidArgumentException;
use stdClass;

/** Only a trusted core peer can supply this already-public, invocation-local snapshot. */
final readonly class Invocation
{
    public string $installation;
    public string $site;
    public stdClass $content;

    public function __construct(stdClass $input)
    {
        $keys = array_keys(get_object_vars($input));
        sort($keys);
        if (
            $keys !== array('api', 'content', 'installation', 'site')
            || $input->api !== 'axiompress.core-broker/0.1'
            || !is_string($input->installation)
            || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $input->installation)
            || !is_string($input->site) || !preg_match('/\Asite:[1-9][0-9]{0,9}\z/D', $input->site)
            || !$input->content instanceof stdClass
        ) {
            throw new InvalidArgumentException('Invalid core invocation.');
        }
        $fields = array_keys(get_object_vars($input->content));
        sort($fields);
        if (
            $fields !== array('content_id', 'revision', 'text', 'title')
            || !is_string($input->content->content_id)
            || !preg_match('/\Apost:[1-9][0-9]{0,17}\z/D', $input->content->content_id)
            || !is_string($input->content->revision)
            || !preg_match('/\A[a-f0-9]{64}\z/D', $input->content->revision)
        ) {
            throw new InvalidArgumentException('Invalid public content scope.');
        }
        // Reuse the worker's exact content bounds/UTF-8 contract before admitting any runtime work.
        Protocol::encode((object) array('api' => Protocol::API, 'kind' => 'response',
            'id' => 'snapshot', 'result' => $input->content));
        $this->installation = $input->installation;
        $this->site = $input->site;
        $this->content = $input->content;
    }
}
