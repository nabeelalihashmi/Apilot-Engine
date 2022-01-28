<?php
/**
 * Apilot
 * Version: 0.8.0
 * Author: Nabeel Ali
 * Author Email: mail2nabeelali@gmail.com
 * Author URI: https://iconiccodes.com
 * License: Custom
 * License URI: https://iconiccodes.com
 * Copyright (c) 2022 Nabeel Ali - IconicCodes
 * 
 * @package Apilot
 * @author Nabeel Ali
 * @version 0.8.0
 * @license Custom
 */
namespace ApilotEngine;

class Apilot {

    private $routes = [];

    private $basePath = null;
    private $compiled_routes_path;
    private $handlers_path;

    public $allowedHandlers = ['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS', 'HEAD'];
    private $on404 = null;

    /**
     * Constructor
     *
     * @param string $handlers_path
     * @param string $compiled_routes_path
     * @param string $basePath
     */
    public function __construct($handlers_path, $compiled_routes_path, $basePath = null) {
        $this->handlers_path = $handlers_path;
        $this->compiled_routes_path = $compiled_routes_path;
        $this->basePath = $basePath;
    }

    /**
     * Delete Cache
     * @return void
     * @throws Exception
     */
    public function dropCache() {
        unlink($this->compiled_routes_path);
    }

    /**
     * Add Route
     *
     * @param string $uri
     * @param string $callback
     * @param string $method
     * @param boolean $before_middleware
     * @param boolean $after_middlware
     * @return void
     */
    public function addRoute($uri, $callback, $method = 'GET',  $before_middleware = false, $after_middlware = false) {
        $this->routes[] = [
            'method' => $method,
            'url' => $uri,
            'callback' => $callback,
            'before' => $before_middleware,
            'after' => $after_middlware,
        ];
    }

    /**
     * On 404
     *
     * @param callable $callback
     * @return void
     */
    public function on404($callback) {
        $this->on404 = $callback;
    }

    /** 
     * getRoutes
     * @return array
     */
    public function getRoutes() {
        return $this->routes;
    }

    /**
     * Takeoff
     *
     * @return void
     * @throws Exception
     */
    public function takeoff() {

        $compiled_routes = [];
        if (!file_exists($this->compiled_routes_path)) {
            $compiled_routes = $this->scan_dir($this->handlers_path);
            $compiled_routes = array_merge($this->routes, $compiled_routes);
            file_put_contents($this->compiled_routes_path, '<?php return ' . var_export($compiled_routes, true) . ';');
        }

        $compiled_routes = include $this->compiled_routes_path;
        $this->routes = array_values($compiled_routes);
        $res = $this->matchRoute($this->routes);

        if ($res !== null) {
            include $res['route']['callback'];
            if ($res['route']['before'] === true) {
                $before = call_user_func_array('before_' . $res['route']['method'], $res['params']);
                if ($before === true) {
                    call_user_func_array($res['route']['method'], $res['params']);
                }
            } else {
                call_user_func_array($res['route']['method'], $res['params']);
            }
            if ($res['route']['after']) {
                call_user_func_array('after_' . $res['route']['method'], $res['params']);
            }
        } else {
            if (is_callable($this->on404)) {
                call_user_func($this->on404);
            }
        }
    }

    /** 
     * listFunctionsInPhpFile
     * @param string $file
     * @return array
     */
    private function listFunctionsInPhpFile($file) {
        $functions = [];
        $file_handle = fopen($file, "r");
        $line_number = 1;
        while (!feof($file_handle)) {
            $line = fgets($file_handle);
            if (preg_match('/function ([a-zA-Z0-9_]+)/', $line, $matches)) {
                $functions[] = $matches[1];
            }
            $line_number++;
        }
        fclose($file_handle);

        return $functions;
    }

    /**
     * Scan Dir
     *
     * @param string $dir
     * @return array
     */
    private function scan_dir($path) {
        $files = [];
        $dir = opendir($path);
        while ($file = readdir($dir)) {
            if ($file != '.' && $file != '..') {
                if (is_dir($path . '/' . $file)) {
                    $files = array_merge($files, $this->scan_dir($path . '/' . $file));
                } else {
                    $uri = str_replace($this->handlers_path, '', $path . '/' . $file);
                    $uri = str_replace('/index.php', '/', $uri);
                    $uri = str_replace('.php', '', $uri);

                    if (strlen($uri) > 1) {
                        $uri = rtrim($uri, '/');
                    }

                    $availableHandler = $this->listFunctionsInPhpFile($path . '/' . $file);

                    foreach ($availableHandler as $handler) {
                        if (in_array($handler, $this->allowedHandlers)) {
                            $before = false;
                            $after = false;
                            if (in_array('before_' . $handler, $availableHandler)) {
                                $before = true;
                            }
                            if (in_array('after_' . $handler, $availableHandler)) {
                                $after = true;
                            }

                            $uri_parts = explode(':', $uri);

                            // loop on uri part, if last character is ?, then make string from array index 0 to that index
                            foreach ($uri_parts as $key => $uri_part) {
                                if (substr($uri_part, -1) == '?') {
                                    $__uri = implode('/:', array_slice($uri_parts, 0, $key));
                                    $__uri = preg_replace('/\/+/', '/', $__uri);
                                    if ($uri !== '/') {
                                        $__uri = rtrim($__uri, '/');
                                    }
                                    $files[]  = ['method' => $handler, 'url' => $__uri, 'callback' => $path . '/' . $file, 'before' => $before, 'after' => $after];
                                }
                            }
                            $uri = str_replace(':', '/:', $uri);
                            $uri = preg_replace('/\/+/', '/', $uri);
                            if ($uri !== '/') {
                                $uri = rtrim($uri, '/');
                            }
                            $files[]  = ['method' => $handler, 'url' => $uri, 'callback' => $path . '/' . $file, 'before' => $before, 'after' => $after];
                        }
                    }
                }
            }
        }
        closedir($dir);

        // TODO: arrange in chuncks
        // like
        // id
        // :/id
        // another/route
        // /another/:optional?
   // arrange array by url, if : is in url,  then arrange them at end in descending order based upon /
        
        $files = array_values($files);
        usort($files, function($a, $b) {
            $a_parts = explode('/', $a['url']);
            $b_parts = explode('/', $b['url']);
            $a_parts = array_filter($a_parts, function($part) {
                return $part != ':';
            });
            $b_parts = array_filter($b_parts, function($part) {
                return $part != ':';
            });
            $a_parts = array_values($a_parts);
            $b_parts = array_values($b_parts);
            $a_parts = array_reverse($a_parts);
            $b_parts = array_reverse($b_parts);
            $a_parts = array_map(function($part) {
                return strlen($part);
            }, $a_parts);
            $b_parts = array_map(function($part) {
                return strlen($part);
            }, $b_parts);
            $a_parts = array_map(function($part) {
                return str_pad($part, 10, '0', STR_PAD_LEFT);
            }, $a_parts);
            $b_parts = array_map(function($part) {
                return str_pad($part, 10, '0', STR_PAD_LEFT);
            }, $b_parts);
            $a_parts = implode('', $a_parts);
            $b_parts = implode('', $b_parts);
            return $a_parts < $b_parts ? 1 : -1;
        });
    

        
        return $files;
    }


    /**
     * Match Route
     *
     * @param array $routes
     * @return array|null
     */
    private function matchRoute($routes) {


        $requestMethod = $_SERVER['REQUEST_METHOD'];
        if ($requestMethod == 'POST' && isset($_POST['_method']) && in_array($_POST['_method'], $this->allowedHandlers)) {
            $requestMethod = $_POST['_method'];
        }

        $reqUrl = $this->getURI();

        foreach ($routes as  $route) {
            $pattern = "@^" . preg_replace('/:[a-zA-Z0-9\_\-]+/', '([a-zA-Z0-9\-\_]+)', $route['url']) . "$@D";

            $matches = array();
            if ($requestMethod == $route['method'] && preg_match($pattern, $reqUrl, $matches)) {
                array_shift($matches);
                return ['route' => $route, 'params' => $matches];
            }
        }
        return null;
    }


    public function getURI() {
        $uri = substr(rawurldecode($_SERVER['REQUEST_URI']), strlen($this->getBase()));

        if (strstr($uri, '?')) {
            $uri = substr($uri, 0, strpos($uri, '?'));
        }

        return '/' . trim($uri, '/');
    }

    public function getBase() {

        if ($this->basePath === null) {
            $this->basePath = implode('/', array_slice(explode('/', $_SERVER['SCRIPT_NAME']), 0, -1)) . '/';
        }

        return $this->basePath;
    }
}
