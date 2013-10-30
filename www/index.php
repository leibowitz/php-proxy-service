<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Guzzle\Http\Client;

$app = new Silex\Application();

$app['debug'] = true;
$app['domain'] = 'proxy.dev';

$app->match('{url}', function($url, Request $request) use ($app) {

    $host = str_replace('.'.$app['domain'], '', $request->getHost());
    $host = str_replace('-', '.', $host);
    $host = str_replace('..', '-', $host);

    $url = http_build_url(
        $request->getSchemeAndHttpHost(),
        array(
            'host' => $host,
            'path' => $request->getPathInfo(),
            'query' => $request->getQueryString()
        )
    );

    $client = new Client($url, array(
        'request.options' => array(
            'exceptions' => false
        )
    ));
    $proxyRequest = $client->createRequest($request->getMethod(), $url);
    if($request->getMethod() != 'GET') {
        $proxyRequest->setBody($request->getContent());
    }

    $proxyResponse = $proxyRequest->send();

    $response = new Response($proxyResponse->getBody());
    return $response;
})->assert('url', '.*');

$app->run();
