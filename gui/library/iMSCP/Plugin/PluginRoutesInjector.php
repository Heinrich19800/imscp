<?php
/**
 * i-MSCP - internet Multi Server Control Panel
 * Copyright (C) 2010-2019 by Laurent Declercq <l.declercq@nuxwin.com>
 *
 * This program is free software; you can redistribute it and/or
 * modify it under the terms of the GNU General Public License
 * as published by the Free Software Foundation; either version 2
 * of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
 */

/** @noinspection PhpIncludeInspection PhpUnusedParameterInspection */

namespace iMSCP\Plugin;

use Slim\App;

class PluginRoutesInjector
{
    const DEFAULT_METHODS = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS'
    ];

    /**
     * Inject plugin routes into Slim application
     * 
     * @param App $app
     * @param \iMSCP_Plugin_Manager $pm
     * @return void
     */
    public function __invoke(App $app, \iMSCP_Plugin_Manager $pm): void
    {
        foreach ($pm->pluginGetLoaded('Action') as $plugin) {
            // For backward compatibility only (duck-typing).
            if (method_exists($plugin, 'route')) {
                /** @var \Psr\Http\Message\RequestInterface $request */
                $request = $app->getContainer()->get('request');
                if (!($pluginActionScriptPath = $plugin->route(parse_url($request->getUri())))) {
                    continue;
                }

                $routes = [$request->getUri()->getPath() => $pluginActionScriptPath];
            } else {
                $routes = $plugin->getRoutes();
            }

            $this->injectRoutes($app, $routes, $plugin);
        }
    }

    /**
     * @param App $app
     * @param array $routes
     * @param \iMSCP_Plugin $plugin
     * @return void
     */
    private function injectRoutes(App $app, array $routes, \iMSCP_Plugin $plugin): void
    {
        foreach ($routes as $key => $spec) {
            // For backward compatibility only.
            // Wrap execution in specific callback
            if (is_string($key)) {
                $app->any($key, function ($request, $response) use ($spec) {
                    require $spec;
                });
                continue;
            }

            if (isset($spec['routes'])) {
                $this->injectRouteGroup($app, $spec, $plugin);
                continue;
            }

            $this->injectRoute($app, $spec, $plugin);
        }
    }

    /**
     * @param App $app
     * @param array $spec
     * @param \iMSCP_Plugin $plugin
     * @return void
     */
    private function injectRouteGroup(App $app, array $spec, \iMSCP_Plugin $plugin): void
    {
        if (!isset($spec['path'])) {
            throw new \InvalidArgumentException(sprintf(
                'Missing "path" key in route group specification for the "%s" plugin.',
                $plugin->getName()
            ));
        }

        $self = $this;
        $group = $app->group($spec['path'], function () use ($app, $spec, $plugin, $self) {
            foreach ($spec['routes'] as $routeSpec) {
                $self->injectRoute($app, $routeSpec, $plugin);
            }
        });

        // Add route group middleware if any
        if (isset($spec['middleware'])) {
            foreach ((array)$spec['middleware'] as $middleware) {
                $group->add($middleware);
            }
        }
    }

    /**
     * @param App $app
     * @param array $spec
     * @param \iMSCP_Plugin $plugin
     * @return void
     */
    private function injectRoute(App $app, array $spec, \iMSCP_Plugin $plugin): void
    {
        if (!isset($spec['path']) || !isset($spec['handler'])) {
            throw new \InvalidArgumentException(sprintf(
                'Missing "path" or "handler" key in route specification for the "%s" plugin.',
                $plugin->getName()
            ));
        }

        $methods = isset($spec['methods']) ? $spec['methods'] : self::DEFAULT_METHODS;

        if (!is_array($methods)) {
            throw new \InvalidArgumentException(sprintf(
                'Allowed HTTP methods for a route must be in form of an array; received "%s" for the "%s" plugin',
                gettype($methods),
                $plugin->getName()
            ));
        }

        $route = $app->map($methods, $spec['path'], $spec['handler']);

        // Set route name if any
        if (isset($spec['name'])) {
            $route->setName($spec['name']);
        }

        // Add route middleware if any
        if (isset($routeSpec['middleware'])) {
            foreach ((array)$spec['middleware'] as $middleware) {
                $route->add($middleware);
            }
        }
    }
}
