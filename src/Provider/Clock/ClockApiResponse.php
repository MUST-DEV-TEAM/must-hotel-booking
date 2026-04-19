<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockApiResponse
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $body;

    /** @var mixed */
    private $data;

    /** @var string */
    private $errorCode;

    /** @var string */
    private $errorMessage;

    /** @var int */
    private $durationMs;

    /**
     * @param mixed $data
     */
    public function __construct(int $statusCode, string $body, $data = null, string $errorCode = '', string $errorMessage = '', int $durationMs = 0)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
        $this->data = $data;
        $this->errorCode = $errorCode;
        $this->errorMessage = $errorMessage;
        $this->durationMs = $durationMs;
    }

    public function isSuccess(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300 && $this->errorCode === '';
    }

    public function isAuthFailure(): bool
    {
        return \in_array($this->statusCode, [401, 403], true);
    }

    public function isConnectivityFailure(): bool
    {
        return $this->statusCode <= 0 && $this->errorCode !== '';
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    /** @return mixed */
    public function getData()
    {
        return $this->data;
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'success' => $this->isSuccess(),
            'status_code' => $this->statusCode,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'duration_ms' => $this->durationMs,
            'data' => $this->data,
        ];
    }
}
