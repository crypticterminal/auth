<?php
/**
 * SocialConnect project
 * @author: Patsura Dmitry https://github.com/ovr <talk@dmtry.me>
 */

namespace SocialConnect\Auth\OAuth;

class Request
{
    public $parameters;

    public $http_method;

    public $http_url;

    // for debug purposes
    public $base_string;

    public static $version = '1.0';

    public static $POST_INPUT = 'php://input';

    public function __construct($http_method, $http_url, $parameters = null)
    {
        $parameters = ($parameters) ? $parameters : array();
        $parameters = array_merge(Util::parse_parameters(parse_url($http_url, PHP_URL_QUERY)), $parameters);
        $this->parameters  = $parameters;
        $this->http_method = $http_method;
        $this->http_url    = $http_url;
    }

    /**
     * Attempt to build up a request from what was passed to the server
     *
     * @param null $method
     * @param null $url
     * @param null $parameters
     * @return Request
     */
    public static function fromRequest($method = null, $url = null, $parameters = null)
    {
        $scheme = (!isset($_SERVER['HTTPS']) || $_SERVER['HTTPS'] != "on") ? 'http' : 'https';
        $url = ($url) ? $url : $scheme . '://' . $_SERVER['SERVER_NAME'] . ':' . $_SERVER['SERVER_PORT'] . $_SERVER['REQUEST_URI'];
        $method = ($method) ? $method : $_SERVER['REQUEST_METHOD'];

        // We weren't handed any parameters, so let's find the ones relevant to
        // this request.
        // If you run XML-RPC or similar you should use this to provide your own
        // parsed parameter-list
        if (!$parameters) {
            // Find request headers
            $request_headers = Util::get_headers();

            // Parse the query-string to find GET parameters
            $parameters = Util::parse_parameters($_SERVER['QUERY_STRING']);

            // It's a POST request of the proper content-type, so parse POST
            // parameters and add those overriding any duplicates from GET
            if ($method == 'POST' && isset($request_headers['Content-Type']) && strstr($request_headers['Content-Type'], 'application/x-www-form-urlencoded')) {
                $post_data  = Util::parse_parameters(file_get_contents(self::$POST_INPUT));
                $parameters = array_merge($parameters, $post_data);
            }

            // We have a Authorization-header with OAuth data. Parse the header
            // and add those overriding any duplicates from GET or POST
            if (isset($request_headers['Authorization']) && substr($request_headers['Authorization'], 0, 6) == 'OAuth ') {
                $header_parameters = Util::split_header($request_headers['Authorization']);
                $parameters = array_merge($parameters, $header_parameters);
            }
        }

        return new self($method, $url, $parameters);
    }

    /**
     * @param Consumer $consumer
     * @param Token $token
     * @param string $method
     * @param string $url
     * @param array $parameters
     * @return Request
     */
    public static function fromConsumerAndToken(Consumer $consumer, Token $token, $method, $url, array $parameters = array())
    {
        $defaults   = array(
            'oauth_version' => self::$version,
            'oauth_nonce' => self::generateNonce(),
            'oauth_timestamp' => time(),
            'oauth_consumer_key' => $consumer->key
        );

        if ($token) {
            $defaults['oauth_token'] = $token->key;
        }

        $parameters = array_merge($defaults, $parameters);
        return new self($method, $url, $parameters);
    }

    public function setParameter($name, $value, $allow_duplicates = true)
    {
        if ($allow_duplicates && isset($this->parameters[$name])) {
            // We have already added parameter(s) with this name, so add to the list
            if (is_scalar($this->parameters[$name])) {
                // This is the first duplicate, so transform scalar (string)
                // into an array so we can add the duplicates
                $this->parameters[$name] = array(
                    $this->parameters[$name]
                );
            }

            $this->parameters[$name][] = $value;
        } else {
            $this->parameters[$name] = $value;
        }
    }
    public function getParameter($name)
    {
        return isset($this->parameters[$name]) ? $this->parameters[$name] : null;
    }
    public function getParameters()
    {
        return $this->parameters;
    }
    public function unsetParameter($name)
    {
        unset($this->parameters[$name]);
    }

    /**
     * The request parameters, sorted and concatenated into a normalized string.
     *
     * @return string
     */
    public function getSignableParameters()
    {
        // Grab all parameters
        $params = $this->parameters;

        // Remove oauth_signature if present
        // Ref: Spec: 9.1.1 ("The oauth_signature parameter MUST be excluded.")
        if (isset($params['oauth_signature'])) {
            unset($params['oauth_signature']);
        }

        return Util::build_http_query($params);
    }

    /**
     * Returns the base string of this request
     *
     * The base string defined as the method, the url
     * and the parameters (normalized), each urlencoded
     * and the concated with &.
     *
     * @return string
     */
    public function getSignatureBaseString()
    {
        $parts = array(
            $this->getNormalizedHttpMethod(),
            $this->getNormalizedHttpUrl(),
            $this->getSignableParameters()
        );

        $parts = Util::urlencode_rfc3986($parts);

        return implode('&', $parts);
    }

    /**
     * just uppercases the http method
     */
    public function getNormalizedHttpMethod()
    {
        return strtoupper($this->http_method);
    }

    /**
     * parses the url and rebuilds it to be
     * scheme://host/path
     */
    public function getNormalizedHttpUrl()
    {
        $parts = parse_url($this->http_url);

        $scheme = (isset($parts['scheme'])) ? $parts['scheme'] : 'http';
        $port   = (isset($parts['port'])) ? $parts['port'] : (($scheme == 'https') ? '443' : '80');
        $host   = (isset($parts['host'])) ? strtolower($parts['host']) : '';
        $path   = (isset($parts['path'])) ? $parts['path'] : '';

        if (($scheme == 'https' && $port != '443') || ($scheme == 'http' && $port != '80')) {
            $host = "$host:$port";
        }
        return "$scheme://$host$path";
    }

    /**
     * builds a url usable for a GET request
     */
    public function toUrl()
    {
        $post_data = $this->toPostData();
        $out       = $this->getNormalizedHttpUrl();
        if ($post_data) {
            $out .= '?' . $post_data;
        }
        return $out;
    }

    /**
     * builds the data one would send in a POST request
     */
    public function toPostData()
    {
        return Util::build_http_query($this->parameters);
    }

    /**
     * builds the Authorization: header
     */
    public function toHeader($realm = null)
    {
        $first = true;
        if ($realm) {
            $out   = 'OAuth realm="' . Util::urlencode_rfc3986($realm) . '"';
            $first = false;
        } else {
            $out = 'OAuth';
        }

        $total = array();
        foreach ($this->parameters as $k => $v) {
            if (substr($k, 0, 5) != "oauth") {
                continue;
            }
            if (is_array($v)) {
                continue;
            }
            $out .= ($first) ? ' ' : ', ';
            $out .= Util::urlencode_rfc3986($k) . '="' . Util::urlencode_rfc3986($v) . '"';
            $first = false;
        }
        return array(
            'Authorization' => $out
        ); //- hacked into this to make it return an array. 15/11/2014.
    }

    public function __toString()
    {
        return $this->toUrl();
    }

    /**
     * @param AbstractSignatureMethod $signature_method
     * @param Consumer $consumer
     * @param Token $token
     */
    public function signRequest(AbstractSignatureMethod $signature_method, Consumer $consumer, Token $token)
    {
        $this->setParameter('oauth_signature_method', $signature_method->get_name(), false);
        $signature = $this->buildSignature($signature_method, $consumer, $token);
        $this->setParameter('oauth_signature', $signature, false);
    }

    /**
     * @param AbstractSignatureMethod $signatureMethod
     * @param Consumer $consumer
     * @param Token $token
     * @return string
     */
    public function buildSignature(AbstractSignatureMethod $signatureMethod, Consumer $consumer, Token $token)
    {
        $signature = $signatureMethod->buildSignature($this, $consumer, $token);
        return $signature;
    }

    /**
     * util function: current nonce
     */
    private static function generateNonce()
    {
        return md5(microtime() . mt_rand());
    }
}