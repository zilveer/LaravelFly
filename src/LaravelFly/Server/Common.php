<?php

namespace LaravelFly\Server;

use swoole_atomic;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\EventDispatcher\EventDispatcher;

// this is necessary for  const mapFlyFiles where LARAVELFLY_SERVICES is used
if (!defined('LARAVELFLY_SERVICES')) include __DIR__ . '/../../../config/laravelfly-server-config.example.php';

class Common
{
    use Traits\DispatchRequestByQuery;
    use Traits\Preloader;
    use Traits\Tinker;
    use Traits\Worker;
    use Traits\Laravel;

    /**
     * where laravel app located
     * @var string
     */
    protected $root;

    /**
     * @var array
     */
    protected $options;

    const mapFlyFiles = [
        'Container.php' =>
            '/vendor/laravel/framework/src/Illuminate/Container/Container.php',
        'Application.php' =>
            '/vendor/laravel/framework/src/Illuminate/Foundation/Application.php',
        'ServiceProvider.php' =>
            '/vendor/laravel/framework/src/Illuminate/Support/ServiceProvider.php',
        'Router.php' =>
            '/vendor/laravel/framework/src/Illuminate/Routing/Router.php',
        'ViewConcerns/ManagesComponents.php' =>
            '/vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesComponents.php',
        'ViewConcerns/ManagesLayouts.php' =>
            '/vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesLayouts.php',
        'ViewConcerns/ManagesLoops.php' =>
            '/vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesLoops.php',
        'ViewConcerns/ManagesStacks.php' =>
            '/vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesStacks.php',
        'ViewConcerns/ManagesTranslations.php' =>
            '/vendor/laravel/framework/src/Illuminate/View/Concerns/ManagesTranslations.php',
        'Facade.php' =>
            '/vendor/laravel/framework/src/Illuminate/Support/Facades/Facade.php',

        // otherwise on each boot of PaginationServiceProvider and NotificationServiceProvider,view paths would be appended to app('view')->finder->hints
        // by  $this->loadViewsFrom forever
        'FileViewFinder' . (LARAVELFLY_SERVICES['view.finder'] ? 'SameView' : '') . '.php' =>
            '/vendor/laravel/framework/src/Illuminate/View/FileViewFinder.php',

    ];
    protected static $conditionFlyFiles = [
        'log_cache' => [
            'StreamHandler.php' =>
                '/vendor/monolog/monolog/src/Monolog/Handler/StreamHandler.php',
        ],
        'config' => [
            'Config/Repository.php' =>
                '/vendor/laravel/framework/src/Illuminate/Config/Repository.php'

        ],
        'kernel' => [
            'Http/Kernel.php' =>
                '/vendor/laravel/framework/src/Illuminate/Foundation/Http/Kernel.php'

        ]
    ];


    /**
     * @var EventDispatcher
     */
    protected $dispatcher;

    /**
     * @var \swoole_http_server
     */
    var $swoole;

    /**
     * @var [\swoole_atomic] save shared actomic info across processes
     */
    var $atomicMemory = [];

    /**
     * @var string
     */
    protected $appClass;

    /**
     * @var string
     */
    protected $kernelClass = \LaravelFly\Kernel::class;


    public function __construct($dispatcher = null)
    {
        $this->dispatcher = $dispatcher ?: new EventDispatcher();

        $this->root = realpath(__DIR__ . '/../../../../../..');

        if (!(is_dir($this->root) && is_file($this->root . '/bootstrap/app.php'))) {
            die("This doc root is not for a Laravel app: {$this->root} \n");
        }

    }

    public function config(array $options)
    {
        if (empty($options['mode']) && defined('LARAVELFLY_MODE')) $options['mode'] = LARAVELFLY_MODE;


        $default = $this->getDefaultConfig();

        $this->options = array_merge($default, $options);

        $this->parseOptions($this->options);

    }

    public function getDefaultConfig()
    {
        $d = include __DIR__ . '/../../../config/laravelfly-server-config.example.php';

        return array_merge([
            'mode' => 'Map',
            'conf' => null, // server config file
            'colorize' => true,
        ], $d);
    }

    public function getConfig($name = null)
    {
        if (is_string($name)) {
            return $this->options[$name] ?? null;
        }
        return $this->options;

    }

    protected function parseOptions(array &$options)
    {
        static::includeFlyFiles($options);

        // as earlier as possible
        if ($options['pre_include'])
            $this->preInclude();

        if (isset($options['pid_file'])) {
            $options['pid_file'] .= '-' . $options['listen_port'];
        } else {
            $options['pid_file'] = $this->root . '/bootstrap/laravel-fly-' . $options['listen_port'] . '.pid';
        }

        $this->appClass = '\LaravelFly\\' . $options['mode'] . '\Application';

        if (!class_exists($this->appClass)) {
            die("[ERROR] Mode set in config file not valid\n");
        }

        $kernelClass = $options['kernel'] ?? 'App\Http\Kernel';
        if (!(
            (LARAVELFLY_MODE === 'Simple' && is_subclass_of($kernelClass, \LaravelFly\Simple\Kernel::class)) ||
            (LARAVELFLY_MODE === 'Map' && is_subclass_of($kernelClass, \LaravelFly\Map\Kernel::class))
        )) {

            $kernelClass = \LaravelFly\Kernel::class;
            echo $this->colorize(
                "[WARN] LaravelFly default kernel used: $kernelClass, 
      please edit App/Http/Kernel like https://github.com/scil/LaravelFly/blob/master/doc/config.md\n", 'WARNING'
            );
        }
        $this->kernelClass = $kernelClass;

        $this->prepareTinker($options);

        $this->dispatchRequestByQuery($options);
    }

    static function includeFlyFiles(&$options)
    {
        // all fly files are for Mode Map, except Config/SimpleRepository.php for Mode Simple
        if (defined('LARAVELFLY_SERVICES') && !LARAVELFLY_SERVICES['config'])
            include_once __DIR__ . '/../../fly/Config/' . (LARAVELFLY_MODE === 'Map' ? '' : 'Simple') . 'Repository.php';

        static $mapLoaded = false;
        static $logLoaded = false;

        if ($options['mode'] === 'Map' && !$mapLoaded) {

            $mapLoaded = true;

            if (defined('LARAVELFLY_SERVICES') && !(LARAVELFLY_SERVICES['kernel'] ?? true))
                include_once __DIR__ . '/../../fly/Http/Kernel.php';

            foreach (static::mapFlyFiles as $f => $offical) {
                require __DIR__ . "/../../fly/" . $f;
            }

        }

        if ($logLoaded) return;

        $logLoaded = true;

        if (is_int($options['log_cache']) && $options['log_cache'] > 1) {

            foreach (static::$conditionFlyFiles['log_cache'] as $f => $offical) {
                require __DIR__ . "/../../fly/" . $f;
            }

        } else {

            $options['log_cache'] = false;
        }

    }

    public function createSwooleServer(): \swoole_http_server
    {
        $options = $this->options;

        if ($options['early_laravel']) $this->startLaravel();

        if ($this->options['daemonize'])
            $this->options['colorize'] = false;

        $this->swoole = $swoole = new \swoole_http_server($options['listen_ip'], $options['listen_port']);

        $swoole->set($options);

        $this->setListeners();

        $swoole->fly = $this;

        return $swoole;
    }

    public function setListeners()
    {
        $this->swoole->on('WorkerStart', array($this, 'onWorkerStart'));

        $this->swoole->on('WorkerStop', array($this, 'onWorkerStop'));

        $this->swoole->on('Request', array($this, 'onRequest'));

    }

    function onRequest(\swoole_http_request $request, \swoole_http_response $response)
    {
    }

    public function start()
    {

        if (!method_exists('\co', 'getUid'))
            die("[ERROR] pecl install swoole or enable swoole.use_shortname.\n");


        if ($this->getConfig('watch_down')) {

            $this->addMemory('isDown', new swoole_atomic(0));
        }

        try {

            $this->swoole->start();

        } catch (\Throwable $e) {

            die("[ERROR] swoole server started failed: {$e->getMessage()} \n");

        }
    }

    /**
     * @return EventDispatcher
     */
    public function getDispatcher(): EventDispatcher
    {
        return $this->dispatcher;
    }

    static function getAllFlyMap()
    {
        $r = static::mapFlyFiles;

        foreach (static::$conditionFlyFiles as $map) {
            $r = array_merge($r, $map);
        }
        return $r;
    }

    public function getSwooleServer(): \swoole_server
    {
        return $this->swoole;
    }

    public function path($path = null): string
    {
        return $path ? "{$this->root}/$path" : $this->root;
    }

    public function getMemory(string $name): ?int
    {
        if ($this->atomicMemory[$name] ?? null) {
            return $this->atomicMemory[$name]->get();
        }
        return null;
    }

    /**
     * @param array $memory
     */
    public function addMemory(string $name, swoole_atomic $atom)
    {
        $this->atomicMemory[$name] = $atom;
    }

    function setMemory(string $name, $value)
    {
        $this->atomicMemory[$name]->set((int)$value);
    }

    function colorize($text, $status)
    {
        if (!$this->getConfig('colorize')) return $text;

        $out = "";
        switch ($status) {
            case "SUCCESS":
                $out = "[42m"; //Green background
                break;
            case "FAILURE":
                $out = "[41m"; //Red background
                break;
            case "WARNING":
                $out = "[41m"; //Red background
                break;
            case "NOTE":
                $out = "[43m"; //Yellow background
                // $out = "[44m"; //Blue background
                break;
            default:
                throw new Exception("Invalid status: " . $status);
        }
        return chr(27) . "$out" . "$text" . chr(27) . "[0m";
    }

}