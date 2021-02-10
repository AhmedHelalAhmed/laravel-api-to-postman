<?php

namespace AndreasElia\PostmanGenerator;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Storage;

class ExportPostman extends Command
{
    /** @var string */
    protected $signature = 'export:postman {--bearer= : The bearer token to use on your endpoints}';

    /** @var string */
    protected $description = 'Automatically generate a Postman collection for your API routes';

    /** @var \Illuminate\Routing\Router */
    protected $router;

    /** @var array */
    protected $routes;

    /** @var array */
    protected $config;

    public function __construct(Router $router, Repository $config)
    {
        parent::__construct();

        $this->router = $router;
        $this->config = $config['api-postman'];
    }

    public function handle(): void
    {
        $bearer = $this->option('bearer') ?? false;

        $this->routes = [
            'variable' => [
                [
                    'key' => 'base_url',
                    'value' => $this->config['base_url'],
                ],
            ],
            'info' => [
                'name' => $filename = date('Y_m_d_His').'_postman',
                'schema' => 'https://schema.getpostman.com/json/collection/v2.1.0/collection.json',
            ],
            'item' => [],
        ];

        $structured = $this->config['structured'];

        if ($bearer) {
            $this->routes['variable'][] = [
                'key' => 'token',
                'value' => $bearer,
            ];
        }

        foreach ($this->router->getRoutes() as $route) {
            $middleware = $route->gatherMiddleware();

            foreach ($route->methods as $method) {
                if ($method == 'HEAD' || empty($middleware) || !in_array('api', $middleware)) {
                    continue;
                }

                $request = $this->makeItem($route, $method);

                $request = $this->makeItem($route, $method, $routeHeaders);

                if ($structured) {
                    $routeNames = $route->action['as'] ?? null;
                    $routeNames = explode('.', $routeNames);
                    $routeNames = array_filter($routeNames, function ($value) {
                        return ! is_null($value) && $value !== '';
                    });

                    $destination = end($routeNames);

                    $this->ensurePath($this->routes, $routeNames, $request, $destination);
                } else {
                    $this->routes['item'][] = $request;
                }
            }
        }

        Storage::put($exportName = "$filename.json", json_encode($this->routes));

        $this->info("Postman Collection Exported: $exportName");
    }

    protected function ensurePath(array &$root, array $segments, array $request, string $destination): void
    {
        $parent = &$root;

        foreach ($segments as $segment) {
            $matched = false;

            foreach ($parent['item'] as &$item) {
                if ($item['name'] === $segment) {
                    $parent = &$item;

                    if ($segment === $destination) {
                        $parent['item'][] = $request;
                    }

                    $matched = true;
                    break;
                }
            }

            unset($item);

            if (! $matched) {
                $item = [
                    'name' => $segment,
                    'item' => [$request],
                ];

                $parent['item'][] = &$item;
                $parent = &$item;
            }

            unset($item);
        }
    }

    public function makeItem(Route $route, $method)
    {
        return [
            'name' => $route->uri(),
            'request' => [
                'method' => strtoupper($method),
                'header' => $this->configureHeaders($route->gatherMiddleware()),
                'url' => [
                    'raw' => '{{base_url}}/'.$route->uri(),
                    'host' => '{{base_url}}/'.$route->uri(),
                ],
            ],
        ];
    }

    /**
     * @param  array  $middleware
     * @return \string[][]
     */
    protected function configureHeaders(array $middleware)
    {
        $headers = [
            [
                'key' => 'Content-Type',
                'value' => 'application/json',
            ],
        ];

        if ($this->option('bearer') && in_array($this->config['auth_middleware'], $middleware)) {
            $headers[] = [
                'key' => 'Authorization',
                'value' => 'Bearer {{token}}',
            ];
        }

        return $headers;
    }
}
