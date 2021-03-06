<?php
/**
 * Request
 * 
 * The request class holds all the parameters that are passed into an API call.
 * You can pass in an associative array to the constructor and that will be used
 * as a backing for the values.
 *
 * Request also holds all global state.
 *
 * You can use the push function to create a child Request object that will have
 * copy-on-write semantics.
 */
class Request extends ArrayObject {
	/**
	 * The parameters that can be accessed through this Request.
	 */
	private $params = null;

	/**
	 * The parent of this Request. This is set whenever the push function is called.
	 */
	private $parent = null;

	/**
	 * The format in which the request will be rendered.
	 */
	const JsonFormat = 0;
	const HtmlFormat = 1;
	public $renderFormat = Request::JsonFormat;

	/**
	 * The object of the user currently logged in.
	 */
	public $user = null;

	/**
	 * The method that will be called.
	 */
	public $method = null;

	/**
	 * The constructor of this class. Uses $contents as the backing for the values.
	 *
	 * @param array $contents An associative array with the values that this class
	 *                        will use.
	 */
	public function __construct($contents = null) {
		if ($contents != null && is_array($contents)) {
			$this->params = $contents;
		} else {
			$this->params = array();
		}
	}

	/**
	 * Assigns $key to $value. Used as $req[$key] = $value;
	 *
	 * @param string $key   The key.
	 * @param mixed  $value The value.
	 */
	public function offsetSet($key, $value) {
		$this->params[$key] = $value;
	}

	/**
	 * Whether $key exists. Used as isset($req[$key]);
	 *
	 * @param string $key The key.
	 */
	public function offsetExists($key) {
		return $key == 'auth_token' || isset($this->params[$key]) || ($this->parent != null && isset($this->parent[$key]));
	}

	/**
	 * Usets a key. Used as unset($req[$key]);
	 *
	 * @param string $key The key.
	 */
	public function offsetUnset($key) {
		unset($this->params[$key]);
	}

	/**
	 * Gets the value associated with a key. Used as $req[$key];
	 *
	 * @param string $key The key.
	 */
	public function offsetGet($key) {
		return (isset($this->params[$key]) && $this->params[$key] !== "null") ? $this->params[$key] : ($this->parent != null ? $this->parent[$key] : null);
	}

	/**
	 * Creates a new Request with the same members as the current Request, with copy-on-write semantics.
	 *
	 * @param array $contents The (optional) array with the values.
	 */
	public function push($contents = null) {
		$req = new Request($contents);
		$req->parent = $this;
		$req->user = $this->user;
		$req->renderFormat = $this->renderFormat;
		return $req;
	}

	/**
	 * Executes the user-provided function and returns its result.
	 */
	public function execute() {
		$response = call_user_func($this->method, $this);
		
		if ($response === false) {
			throw new NotFoundException("Api requested not found.");
		}
		
		return $response;
	}
}
