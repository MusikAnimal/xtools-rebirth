<?php
/**
 * This file contains only the XtoolsHttpException class.
 */

namespace AppBundle\Exception;


class XtoolsHttpException extends \RuntimeException
{
    protected $redirectUrl;
    protected $params;
    protected $api;

    /**
     * XtoolsHttpException constructor.
     * @param string $message
     * @param string $redirectUrl
     * @param array $params
     * @param bool $api Whether this is thrown during an API request.
     */
    public function __construct($message, $redirectUrl, $params = [], $api = false)
    {
        $this->redirectUrl = $redirectUrl;
        $this->params = $params;
        $this->api = $api;

        parent::__construct($message);
    }

    /**
     * The URL that should be redirected to.
     * @return string
     */
    public function getRedirectUrl()
    {
        return $this->redirectUrl;
    }

    /**
     * @return array
     */
    public function getParams()
    {
        return $this->params;
    }

    /**
     * Whether this exception was thrown as part of a request to the API.
     * @return bool
     */
    public function isApi()
    {
        return $this->api;
    }
}
