<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Guzzle\Http\Client;

$app = new Silex\Application();

$app['debug'] = true;
$app['domain'] = 'proxy.dev';

$app['removeHeaders'] = array(
        'Content-Length',
        'Connection',
        'Content-Encoding'
    );


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

    // Remove the proxy.dev
    $host = str_replace('.'.$app['domain'], '', $request->getHost());

    // Look if we have a port
    $parts = explode('.', $host);
    // Extract host
    $host = $parts[0];
    if( count($parts) > 1 ) {
        $port = (int)$parts[1];
    } else {
        $port = 80;
    }

    // replace dashes with dots
    $host = str_replace('-', '.', $host);
    // replace double dots (which were double dashes) to a dash
    $host = str_replace('..', '-', $host);

    $url = http_build_url(
        $request->getSchemeAndHttpHost(),
        array(
            'port' => $port,
            'scheme' => $port == 443 ? 'https' : 'http',
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

    $headers = $request->headers->all();
    $headers['host'] = $host;

    $proxyRequest = $client->createRequest($request->getMethod(), $url, $headers, $request->getContent());

    /*if($request->getMethod() != 'GET') {
        $proxyRequest->setBody($request->getContent());
    }*/

    PHP_Timer::start();
    $proxyResponse = $proxyRequest->send();
    $time = PHP_Timer::stop();

    $document = array(
        'request' =>
            array(
                'body' => $request->getContent(),
                //'headers' => $request->headers->all(),
                'headers' => $proxyRequest->getHeaders()->toArray(),
                'host' => $proxyRequest->getHost(),
                'path' => $proxyRequest->getPath().'?'.$proxyRequest->getQuery(true),
                'time' => $time,
                'date' => new MongoDate(),
            ),
        'response' =>
            array(
                'body' => $proxyResponse->getBody(true),
                'headers' => $proxyResponse->getHeaders()->toArray(),
            )
    );

    $db = $app['mongodb']->selectCollection('proxyservice', 'log');
    $db->insert($document);

    // Remove a few headers from the response like content-length
    $headers = $proxyResponse->getHeaders()->toArray();

    foreach($app['removeHeaders'] as $field) {

        if( !isset($headers[$field]) ) {
            continue;
        }

        unset($headers[$field]);
    }

    $response = new Response($proxyResponse->getBody(), $proxyResponse->getStatusCode(), $headers);

    return $response;
})->assert('url', '.*');

$app->run();
