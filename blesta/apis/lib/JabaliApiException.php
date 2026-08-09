<?php
/**
 * Error type thrown by JabaliApiClient.
 *
 * Canonical copy lives in shared/src/ — synced into each module by
 * shared/sync.sh. See docs/API-CONTRACT.md §6 for the taxonomy.
 */

class JabaliApiException extends \Exception
{
    /** @var int HTTP status (0 for transport/config failures) */
    private $httpStatus;

    /**
     * @var string machine code: write-envelope error codes
     *             (scope_denied|not_found|conflict|unsupported|rate_limited|internal)
     *             plus client-side codes (network|config|clock_skew|unauthorized|error).
     */
    private $errorCode;

    /** @var bool whether a retry (with a fresh signature) may succeed */
    private $retryable;

    public function __construct($message, $httpStatus = 0, $errorCode = 'error', $retryable = false)
    {
        parent::__construct($message);
        $this->httpStatus = (int)$httpStatus;
        $this->errorCode = (string)$errorCode;
        $this->retryable = (bool)$retryable;
    }

    /** @return int */
    public function getHttpStatus()
    {
        return $this->httpStatus;
    }

    /** @return string */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /** @return bool */
    public function isRetryable()
    {
        return $this->retryable;
    }

    /** @return bool true when the failure is a client↔panel clock-skew 401 */
    public function isClockSkew()
    {
        return $this->errorCode === 'clock_skew';
    }

    /** @return bool true when the panel lacks the endpoint (upgrade needed) */
    public function isUnsupported()
    {
        return $this->errorCode === 'unsupported' || $this->httpStatus === 404;
    }
}
