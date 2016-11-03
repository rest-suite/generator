<?php

namespace Example\Api;

use Slim\App;

/**
 * Class Bootstrap
 * 
 * Creating routes and starting application
 * 
 * @package Example\Api
 */
class Bootstrap {

	/**
	 * Slim application
	 * 
	 * @var App
	 */
	private $app;

	/**
	 * Bootstrap constructor
	 * 
	 * @param App $app
	 */
	public function __construct(App $app = null) {
		$this->app = is_null($app) ? new App() : $app;
		$this->routeToItemController();
	}

	/**
	 * Start application
	 */
	public function run() {
		$this->app->run();
	}

	/**
	 * Route to /item api group
	 */
	private function routeToItemController() {
		$this->app->group('/item', function () {
			/** @var App $this */
			$this->post('', '\Example\Api\Controllers\ItemController:addItem');
			$this->get('/{id}', '\Example\Api\Controllers\ItemController:getItem');
			$this->put('/{id}', '\Example\Api\Controllers\ItemController:updateItem');
			$this->delete('/{id}', '\Example\Api\Controllers\ItemController:deleteItem');
		});
	}
}