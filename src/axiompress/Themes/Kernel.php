<?php

declare(strict_types=1);

namespace AxiomPress\Core\Themes;

use AxiomPress\Core\Auth\Guard;
use AxiomPress\Core\Auth\Store;
use AxiomPress\Core\HtmlResponse;
use AxiomPress\Core\Kernel as PublicKernel;
use AxiomPress\Core\Platform\PublicRepository;
use AxiomPress\Core\Response;

final readonly class Kernel
{
    public function __construct(private PublicKernel $public)
    {
    }

    public function handle(string $method, string $uri, string $cookie, bool $secure): HtmlResponse|Response
    {
        if (strlen($uri) > 256) {
            return new Response(414, array('error' => 'uri_too_long'));
        }
        if (!in_array($method, array('GET', 'HEAD'), true)) {
            return new Response(405, array('error' => 'method_not_allowed'));
        }
        if (preg_match('~\A/preview/([1-9][0-9]{0,17})\z~D', $uri, $path)) {
            $guard = new Guard(static fn(): Store => Store::fromEnvironment(), (string) getenv('AXIOM_AUTH_ORIGIN'));
            $context = $guard->authorize($cookie, '', '', $secure, false);
            if ($context instanceof Response) {
                return $context;
            }
            $store = $context['store'];
            $token = $context['token'];
            $article = $store->content($token, 'read', $path[1], array(), '');
            if (isset($article['error'])) {
                return new Response($article['error'] === 'unauthenticated' ? 401 : 404, $article);
            }
            $settings = $store->platform($token, 'settings.read', array(), '');
            if (isset($settings['error'])) {
                return new Response(401, array('error' => 'unauthenticated'));
            }
            return Renderer::article($settings, $article, array(), static function (string $id) use ($store, $token) {
                $image = $store->media($token, 'read', $id, array(), '');
                return ($image['status'] ?? '') === 'ready' ? $image : null;
            }, true);
        }
        if (getenv('AXIOM_PUBLIC_BACKEND') !== 'postgres') {
            return new Response(404, array('error' => 'not_found'));
        }
        if (
            !preg_match(
                '~\A/site/([1-9][0-9]{0,9})(?:/articles/([1-9][0-9]{0,17})|(?:\?before=([1-9][0-9]{0,17}))?)\z~D',
                $uri,
                $path
            )
        ) {
            return new Response(404, array('error' => 'not_found'));
        }
        $site = (int) $path[1];
        $repository = PublicRepository::fromEnvironment();
        $settings = $repository->settings($site);
        if ($settings === null) {
            return new Response(404, array('error' => 'not_found'));
        }
        if (($path[2] ?? '') === '') {
            return Renderer::listing($settings, $site, $repository->articles($site, (int) ($path[3] ?? 0)));
        }
        $result = $this->public->handle($method, '/v1/sites/' . $site . '/content/' . $path[2]);
        if ($result->status !== 200) {
            return $result;
        }
        return Renderer::article(
            $settings,
            $result->data['content'],
            $repository->terms($site, (int) $path[2]),
            static fn(string $id): ?array => $repository->media($site, $id)
        );
    }
}
