<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Guzzle\Http\Client;

$app = new Silex\Application();

$app['debug'] = true;
$app['domain'] = 'proxy.dev';


class MyListener {
    function postConnect() {
        //print_r(func_get_args());
    }
}

$app->register(new SilexMongo\MongoDbExtension(), array(
    'mongodb.connection'    => array(
        'server' => 'localhost:17017',
        'configuration' => function($configuration) {
            $configuration->setLoggerCallable(function($logs) {
                //print_r($logs);
            });
        },
        'eventmanager' => function($eventmanager) {
            $eventmanager->addEventListener('postConnect', new MyListener());
        }
    )
));

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

    //$parts = explode('.', $host);

    $client = new Client($url, array(
        'request.options' => array(
            'exceptions' => false
        )
    ));

    $headers = $request->headers->all();
    $headers['host'] = $host;

    $proxyRequest = $client->createRequest($request->getMethod(), $url, $headers, $request->getContent());

    /*if($request->getMethod() != 'GET') {
        $proxyRequest->setBody($request->getContent());
    }*/

    $proxyResponse = $proxyRequest->send();

    $document = array(
        'request' =>
            array(
                'body' => $request->getContent(),
                //'headers' => $request->headers->all(),
                'headers' => $proxyRequest->getHeaders()->toArray(),
                'host' => $proxyRequest->getHost(),
                'path' => $proxyRequest->getPath().'?'.$proxyRequest->getQuery(true),
            ),
        'response' =>
            array(
                'body' => $proxyResponse->getBody(true),
                'headers' => $proxyResponse->getHeaders()->toArray(),
            )
    );

    $db = $app['mongodb']->selectCollection('proxyservice', 'log');
    $db->insert($document);


    $response = new Response($proxyResponse->getBody());

    return $response;
})->assert('url', '.*');

$app->run();
