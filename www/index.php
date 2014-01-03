<?php

require_once __DIR__.'/../vendor/autoload.php';

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Guzzle\Http\Client;


$app = new Silex\Application();

$app['debug'] = true;
$app['domain'] = 'proxy.dev';
$app['domains'] = array('proxy.dev', 'mocky.dev');

$app['removeHeaders'] = array(
        'Content-Length',
        'Connection',
        'Content-Encoding'
    );

function fix_headers($headers)
{
    return array_flatten_values(filter_headers($headers));
}

function array_flatten_values($headers)
{
    // Some functions returns each header value as header
    // Let's flatten a little bit
    array_walk($headers, function(&$value, $index){
        $value = is_array($value) ? implode($value) : $value;
    });

    return $headers;
}

function filter_headers($headers)
{
    global $app;

    // Filter some headers we don't want to store/pass around
    foreach($app['removeHeaders'] as $field) {

        if( isset($headers[$field]) ) {
            unset($headers[$field]);
        }

        if( isset($headers[strtolower($field)]) ) {
            unset($headers[strtolower($field)]);
        }

    }

    return $headers;
}

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

    $host = $request->getHost();
    $method = $request->getMethod();

    $mock = false;

    // Remove the proxy.dev
    foreach($app['domains'] as $domain) {
        if($domain == 'mocky.dev' && strpos($host, $domain) !== false) {
            $mock = true;
        }

        $host = str_replace('.'.$domain, '', $host);
    }

    // Look if we have a port
    $parts = explode('.', $host);
    // Extract host
    $host = $parts[0];
    $port = 80;
    if( count($parts) > 1 ) {
        $port = (int)$parts[1];
    }

    // replace dashes with dots
    $host = str_replace('-', '.', $host);
    // replace double dots (which were double dashes) to a dash
    $host = str_replace('..', '-', $host);

    $path = $request->getPathInfo();

    // Funny enough it's unclear how Guzzle supports x-www-form-urlencoded GET requests
    // sending the content in the query string does the trick for now
    if($request->getQueryString()) {
        $query = $request->getQueryString();
    } else if( $method == 'GET' && $request->headers->get('content-type') == 'application/x-www-form-urlencoded' ) {
        $query = $request->getContent();
    }

    $url = http_build_url(
        $request->getSchemeAndHttpHost(),
        array(
            'port' => $port,
            'scheme' => $port == 443 ? 'https' : 'http',
            'host' => $host,
            'path' => $request->getPathInfo(),
            'query' => $query
        )
    );

    if($mock) {
        $criteria = array(
            'request.host' => $host,
            //'request.path' => new MongoRegex("^".$path),
            'request.path' => $path,
            'response.status' => 200,
            'request.method' => $method,
            //'request.body' => $request->getContent(),
        );

        $db = $app['mongodb']->selectCollection('proxyservice', 'log');

        $cursor = $db->find($criteria)
            ->sort(array('request.date'=> -1))
            ->limit(1);

        $data = $cursor->getNext();

        if($data) {
            $headers = fix_headers($data['response']['headers']);

            $response = new Response($data['response']['body'], $data['response']['status'], $headers);
            return $response;
        } else {
            $response = doRequest($app, $request, $host, $path, $url);
        }

    } else {
        $response = doRequest($app, $request, $host, $path, $url);
    }

    return $response;
})->assert('url', '.*');


function doRequest($app, $request, $host, $path, $url) {
    $client = new Client($url, array(
        'request.options' => array(
            'exceptions' => false
        )
    ));

    $headers = fix_headers($request->headers->all());

    $headers['host'] = $host;

    $method = $request->getMethod();

    $proxyRequest = $client->createRequest($method, $url, $headers, $request->getContent());

    PHP_Timer::start();
    $proxyResponse = $proxyRequest->send();

    $time = PHP_Timer::stop();

    $document = array(
        'request' =>
            array(
                'body' => $request->getContent(),
                'method' => $method,
                //'headers' => $request->headers->all(),
                'headers' => array_flatten_values($proxyRequest->getHeaders()->toArray()),
                'host' => $proxyRequest->getHost(),
                'path' => $path,
                'time' => $time,
                'date' => new MongoDate(),
            ),
        'response' =>
            array(
                'body' => $proxyResponse->getBody(true),
                'headers' => array_flatten_values($proxyResponse->getHeaders()->toArray()),
                'status' => $proxyResponse->getStatusCode(),
            )
    );


    $db = $app['mongodb']->selectCollection('proxyservice', 'log');
    $db->insert($document);

    // Remove a few headers from the response like content-length
    $headers = fix_headers($proxyResponse->getHeaders()->toArray());

    return new Response($proxyResponse->getBody(), $proxyResponse->getStatusCode(), $headers);
}

$app->run();
