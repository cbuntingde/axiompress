<?php

declare(strict_types=1);

namespace AxiomPress\Broker;

use AxiomPress\Protocol\Protocol;
use Closure;
use stdClass;
use Throwable;

/** A trusted content adapter supplies policy and allowlisted data, never worker credentials. */
final class ContentBroker
{
    public function __construct(
        private readonly InvocationRegistry $invocations,
        private readonly Closure $canRead,
        private readonly Closure $read
    ) {
    }

    /** $connection is supplied by the supervisor's attached channel, not the message. */
    public function handle(string $connection, stdClass $request): stdClass
    {
        Protocol::encode($request);
        if ($request->kind !== 'request') {
            throw new BrokerError('invalid_request');
        }
        $contentId = $request->params->content_id;
        $context = $this->invocations->begin($connection, $request->invocation, $request->id, $contentId);
        try {
            if (($this->canRead)($context['actor'], $context['site'], $contentId) !== true) {
                throw new BrokerError('not_found');
            }
            $content = ($this->read)($context['site'], $contentId);
            if (!$content instanceof stdClass || ($content->content_id ?? null) !== $contentId) {
                throw new BrokerError('not_found');
            }
            // Do not expose fields added by the internal content adapter.
            $result = (object) array(
                'content_id' => $contentId,
                'revision' => $content->revision ?? null,
                'title' => $content->title ?? null,
                'text' => $content->text ?? null,
            );
            $response = (object) array(
                'api' => Protocol::API, 'kind' => 'response', 'id' => $request->id, 'result' => $result,
            );
            Protocol::encode($response);
            if (($this->canRead)($context['actor'], $context['site'], $contentId) !== true) {
                throw new BrokerError('not_found');
            }
            $this->invocations->context($connection, $request->invocation);
            return $response;
        } catch (BrokerError $error) {
            throw $error;
        } catch (Throwable $error) {
            // Previous exception is for trusted diagnostics only, never a wire payload.
            throw new BrokerError('internal_error', $error);
        } finally {
            $this->invocations->finish($connection, $request->invocation);
        }
    }
}
