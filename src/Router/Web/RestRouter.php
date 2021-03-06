<?php

namespace Symlex\Router\Web;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symlex\Exception\MethodNotAllowedException;
use Symlex\Exception\AccessDeniedException;

/**
 * @author Michael Mayer <michael@liquidbytes.net>
 * @license MIT
 * @see https://github.com/symlex/symlex-core#routers
 */
class RestRouter extends RouterAbstract
{
    public function route(string $routePrefix = '/api', string $servicePrefix = 'controller.rest.', string $servicePostfix = '')
    {
        $app = $this->app;
        $container = $this->container;

        $handler = function (Request $request, $path) use ($container, $servicePrefix, $servicePostfix) {
            if (0 === strpos($request->headers->get('Content-Type'), 'application/json')) {
                $data = json_decode($request->getContent(), true);
                $request->request->replace(is_array($data) ? $data : array());
            }

            $method = $request->getMethod();

            $prefix = strtolower($method);
            $parts = explode('/', $path);

            $controller = array_shift($parts);

            $subResources = '';
            $params = array();

            $count = count($parts);

            if ($count % 2 == 0 && $prefix != 'post') {
                $prefix = 'c' . $prefix;
            }

            for ($i = 0; $i < $count; $i++) {
                $params[] = $parts[$i];

                if (isset($parts[$i + 1])) {
                    $i++;
                    $subResources .= ucfirst($parts[$i]);
                }
            }

            $params[] = $request;
            $actionName = $prefix . $subResources . 'Action';

            $controllerService = $servicePrefix . strtolower($controller) . $servicePostfix;

            $controllerInstance = $this->getController($controllerService);

            if ($method === Request::METHOD_HEAD && !method_exists($controllerInstance, $actionName)) {
                $actionName = 'get' . $subResources . 'Action';

                if ($count % 2 == 0) {
                    $actionName = 'c' . $actionName;
                }
            }

            if (!method_exists($controllerInstance, $actionName)) {
                throw new MethodNotAllowedException ('Method ' . $method . ' not supported');
            }

            if (!$this->hasPermission($request)) {
                throw new AccessDeniedException ('Access denied');
            }

            $result = call_user_func_array(array($controllerInstance, $actionName), $params);

            if (!$result) {
                $httpCode = 204;
            } elseif ($method == 'POST') {
                $httpCode = 201;
            } else {
                $httpCode = 200;
            }

            $response = $this->getResponse($result, $httpCode);

            return $response;
        };

        $app->match($routePrefix . '/{path}', $handler, ['path' => '.+']);
    }

    protected function getResponse($result, int $httpCode): Response
    {
        if (is_object($result) && $result instanceof Response) {
            // If controller returns Response object, return it directly
            return $result;
        }

        return $this->app->json($result, $httpCode);
    }
}