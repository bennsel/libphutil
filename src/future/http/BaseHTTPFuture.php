<?php

/*
 * Copyright 2012 Facebook, Inc.
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

/**
 * Execute HTTP requests with a future-oriented API. For example:
 *
 *   $future = new HTTPFuture('http://www.example.com/');
 *   list($status, $body, $headers) = $future->resolve();
 *
 * This is an abstract base class which defines the API that HTTP futures
 * conform to. Concrete implementations are available in @{class:HTTPFuture}
 * and @{class:HTTPSFuture}. All futures return a <status, body, header> tuple
 * when resolved; status is an object of class @{class:HTTPFutureResponseStatus}
 * and may represent any of a wide variety of errors in the transport layer,
 * a support library, or the actual HTTP exchange.
 *
 * @task create Creating a New Request
 * @task config Configuring the Request
 * @task resolve Resolving the Request
 * @task internal Internals
 * @group futures
 */
abstract class BaseHTTPFuture extends Future {

  private $method   = 'GET';
  private $timeout  = 300.0;
  private $headers  = array();
  private $uri;
  private $data;


/* -(  Creating a New Request  )--------------------------------------------- */


  /**
   * Build a new future which will make an HTTP request to a given URI, with
   * some optional data payload. Since this class is abstract you can't actually
   * instantiate it; instead, build a new @{class:HTTPFuture} or
   * @{class:HTTPSFuture}.
   *
   * @param string Fully-qualified URI to send a request to.
   * @param mixed  String or array to include in the request. Strings will be
   *               transmitted raw; arrays will be encoded and sent as
   *               'application/x-www-form-urlencoded'.
   * @task create
   */
  final public function __construct($uri, $data = array()) {
    $this->setURI((string)$uri);
    $this->setData($data);
  }


/* -(  Configuring the Request  )-------------------------------------------- */


  /**
   * Set a timeout for the service call. If the request hasn't resolved yet,
   * the future will resolve with a status that indicates the request timed
   * out. You can determine if a status is a timeout status by calling
   * isTimeout() on the status object.
   *
   * @param float Maximum timeout, in seconds.
   * @return this
   * @task config
   */
  public function setTimeout($timeout) {
    $this->timeout = $timeout;
    return $this;
  }


  /**
   * Get the currently configured timeout.
   *
   * @return float Maximum number of seconds the request will execute for.
   * @task config
   */
  public function getTimeout() {
    return $this->timeout;
  }


  /**
   * Select the HTTP method (e.g., "GET", "POST", "PUT") to use for the request.
   * By default, requests use "GET".
   *
   * @param string HTTP method name.
   * @return this
   * @task config
   */
  final public function setMethod($method) {
    static $supported_methods = array(
      'GET'   => true,
      'POST'  => true,
      'PUT'   => true,
    );

    if (empty($supported_methods[$method])) {
      $method_list = implode(', ', array_keys($supported_methods));
      throw new Exception(
        "The HTTP method '{$method}' is not supported. Supported HTTP methods ".
        "are: {$method_list}.");
    }

    $this->method = $method;
    return $this;
  }


  /**
   * Get the HTTP method the request will use.
   *
   * @return string HTTP method name, like "GET".
   * @task config
   */
  final public function getMethod() {
    return $this->method;
  }


  /**
   * Set the URI to send the request to. Note that this is also a constructor
   * parameter.
   *
   * @param string URI to send the request to.
   * @return this
   * @task config
   */
  public function setURI($uri) {
    $this->uri = (string)$uri;
    return $this;
  }


  /**
   * Get the fully-qualified URI the request will be made to.
   *
   * @return string URI the request will be sent to.
   * @task config
   */
  public function getURI() {
    return $this->uri;
  }


  /**
   * Provide data to send along with the request. Note that this is also a
   * constructor parameter; it may be more convenient to provide it there. Data
   * must be a string (in which case it will be sent raw) or an array (in which
   * case it will be encoded and sent as 'application/x-www-form-urlencoded').
   *
   * @param mixed Data to send with the request.
   * @return this
   * @task config
   */
  public function setData($data) {
    if (!is_string($data) && !is_array($data)) {
      throw new Exception("Data parameter must be an array or string.");
    }
    $this->data = $data;
    return $this;
  }


  /**
   * Get the data which will be sent with the request.
   *
   * @return mixed Data which will be sent.
   * @task config
   */
  public function getData() {
    return $this->data;
  }


  /**
   * Add an HTTP header to the request. The same header name can be specified
   * more than once, which will cause multiple headers to be sent.
   *
   * @param string Header name, like "Accept-Language".
   * @param string Header value, like "en-us".
   * @return this
   * @task config
   */
  public function addHeader($name, $value) {
    $this->headers[] = array($name, $value);
    return $this;
  }


  /**
   * Get headers which will be sent with the request. Optionally, you can
   * provide a filter, which will return only headers with that name. For
   * example:
   *
   *   $all_headers = $future->getHeaders();
   *   $just_user_agent = $future->getHeaders('User-Agent');
   *
   * In either case, an array with all (or all matching) headers is returned.
   *
   * @param string|null Optional filter, which selects only headers with that
   *                    name if provided.
   * @return array      List of all (or all matching) headers.
   * @task config
   */
  public function getHeaders($filter = null) {
    $filter = strtolower($filter);

    $result = array();
    foreach ($this->headers as $header) {
      list($name, $value) = $header;
      if (!$filter || ($filter == strtolower($name))) {
        $result[] = $header;
      }
    }

    return $result;
  }


/* -(  Resolving the Request  )---------------------------------------------- */


  /**
   * Exception-oriented resolve(). Throws if the status indicates an error
   * occurred.
   *
   * @return tuple  HTTP request result <status, body, headers> tuple.
   * @task resolve
   */
  final public function resolvex() {
    $result = $this->resolve();

    list($status, $body, $headers) = $result;
    if ($status->isError()) {
      throw $status;
    }

    return array($body, $headers);
  }


/* -(  Internals  )---------------------------------------------------------- */


  /**
   * Parse a raw HTTP response into a <status, body, headers> tuple.
   *
   * @param string Raw HTTP response.
   * @return tuple Valid resolution tuple.
   * @task internal
   */
  protected function parseRawHTTPResponse($raw_response) {
    $rex_base = "@^(?P<head>.*?)\r?\n\r?\n(?P<body>.*)$@s";
    $rex_head = "@^HTTP/\S+ (?P<code>\d+) .*?(?:\r?\n(?P<headers>.*))?$@s";

    // We need to parse one or more header blocks in case we got any
    // "HTTP/1.X 100 Continue" nonsense back as part of the response. This
    // happens with HTTPS requests, at the least.
    $response = $raw_response;
    while (true) {
      $matches = null;
      if (!preg_match($rex_base, $response, $matches)) {
        return $this->buildMalformedResult($raw_response);
      }

      $head = $matches['head'];
      $body = $matches['body'];

      if (!preg_match($rex_head, $head, $matches)) {
        return $this->buildMalformedResult($raw_response);
      }

      $response_code = (int)$matches['code'];
      if ($response_code == 100) {
        // This is HTTP/1.X 100 Continue, so this whole chunk is moot.
        $response = $body;
      } else {
        $headers = $this->parseHeaders(idx($matches, 'headers'));
        break;
      }
    }

    $status = new HTTPFutureResponseStatusHTTP($response_code);
    return array($status, $body, $headers);
  }

  /**
   * Parse an HTTP header block.
   *
   * @param string Raw HTTP headers.
   * @return list List of HTTP header tuples.
   * @task internal
   */
  protected function parseHeaders($head_raw) {
    $rex_header = '@^(?P<name>.*?):\s*(?P<value>.*)$@';

    $headers = array();

    if (!$head_raw) {
      return $headers;
    }

    $headers_raw = preg_split("/\r?\n/", $head_raw);
    foreach ($headers_raw as $header) {
      $m = null;
      if (preg_match($rex_header, $header, $m)) {
        $headers[] = array($m['name'], $m['value']);
      } else {
        $headers[] = array($header, null);
      }
    }

    return $headers;
  }


  /**
   * Build a result tuple indicating a parse error resulting from a malformed
   * HTTP response.
   *
   * @return tuple Valid resolution tuple.
   * @task internal
   */
  protected function buildMalformedResult($raw_response) {
    $body = null;
    $headers = array();

    $status = new HTTPFutureResponseStatusParse(
      HTTPFutureResponseStatusParse::ERROR_MALFORMED_RESPONSE,
      $raw_response);
    return array($status, $body, $headers);
  }
}